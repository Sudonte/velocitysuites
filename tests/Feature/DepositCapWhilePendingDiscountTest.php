<?php

namespace Tests\Feature;

use App\Http\Controllers\Api\BookingController;
use App\Http\Controllers\Api\PaymentController;
use App\Http\Controllers\Api\ReservationController;
use App\Models\Booking;
use App\Models\Discount;
use App\Models\Payment;
use App\Models\Reservation;
use App\Models\RoomType;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Str;

/**
 * Several deposits must not add up past what the bill will become once the requested discount is applied.
 * While a discount is pending, ALL payments together (already paid + this one) stay under the smaller of
 *   - 50% of the undiscounted total, and
 *   - the total after the requested discount (BookingService::quoteRoomCharge + App\Support\BillDiscount).
 * Reservation: 1 room x 2 nights x P1,000 = P2,000, so the 50% cap is P1,000; a 20% discount leaves P1,600.
 */
class DepositCapWhilePendingDiscountTest extends ApiFlowTestCase
{
    private function discount(string $name, float $percent): Discount
    {
        return Discount::create(['name' => $name, 'discount_type' => 'percentage', 'value' => $percent, 'description' => $name, 'status' => 'active']);
    }

    private function reservation($guest, Discount $discount): Reservation
    {
        $rt = RoomType::where('name', 'Deluxe')->first() ?? $this->makeRoomTypeWithRooms('Deluxe', 1000, 2, 3);
        $r = new Reservation();
        $r->forceFill([
            'guest_id' => $guest->id, 'room_type_id' => $rt->id, 'rooms_requested' => 1,
            'check_in' => now('Asia/Manila')->addDays(3)->startOfDay(), 'check_out' => now('Asia/Manila')->addDays(5)->startOfDay(),
            'number_of_guests' => 1, 'adults' => 1, 'children' => 0,
            'status' => Reservation::STATUS_AWAITING_CASH, 'payment_method' => 'cash',
            'discount_requested' => true, 'discount_verification_status' => 'pending',
            'id_card_type' => $discount->name, 'discount_id' => $discount->id,
        ])->save();

        return $r;
    }

    private function verified(Reservation $r, float $amount): void
    {
        $p = new Payment();
        $p->forceFill([
            'reservation_id' => $r->id, 'payment_method' => 'cash', 'amount_paid' => $amount, 'payment_status' => 'completed',
            'payment_stage' => 'deposit', 'payment_date' => now()->subDay(), 'verified_at' => now()->subHours(2), 'verified_by' => 1,
        ])->save();
    }

    private function pay($user, Reservation $r, float $amount, string $type = 'partial')
    {
        $request = Request::create("/api/guest/reservations/{$r->id}/payments", 'POST', [
            'payment_method' => 'cash', 'payment_type' => $type, 'amount_paid' => $amount, 'idempotency_key' => (string) Str::uuid(),
        ]);
        $request->setUserResolver(fn () => $user);
        $this->actingAs($user);

        return app(PaymentController::class)->store($request, $r->fresh());
    }

    private function message($response): string
    {
        return json_decode($response->getContent(), true)['message'] ?? $response->getContent();
    }

    private function assertAccepted($response): void
    {
        $this->assertSame(201, $response->getStatusCode(), $response->getContent());
    }

    private function assertRefused($response, string $needle): void
    {
        $this->assertSame(422, $response->getStatusCode(), $response->getContent());
        $this->assertStringContainsString($needle, $this->message($response));
    }

    // ---- the worked example: 1,000 + 500 + 300 on a P2,000 bill that becomes P1,600 ----

    public function test_deposits_that_would_add_up_past_the_discounted_total_are_stopped(): void
    {
        [$user, $guest] = $this->makeGuestUser('Cap1');
        $r = $this->reservation($guest, $this->discount('Senior Citizen', 20)); // 2,000 -> 1,600; cap = min(1,000, 1,600) = 1,000

        $this->assertAccepted($this->pay($user, $r, 1000));   // the first deposit, up to the cap
        $this->verified($r, 1000);                            // the receptionist confirms it
        $this->assertRefused($this->pay($user, $r, 500), 'maximum deposit');
        $this->assertRefused($this->pay($user, $r, 300), 'maximum deposit');
    }

    public function test_the_message_when_the_cap_leaves_nothing_payable(): void
    {
        [$user, $guest] = $this->makeGuestUser('Cap2');
        $r = $this->reservation($guest, $this->discount('Senior Citizen', 20));
        $this->verified($r, 1000);

        $this->assertRefused($this->pay($user, $r, 500),
            "You've paid the maximum deposit while your discount is being verified. The rest is settled at the front desk.");
    }

    public function test_a_second_deposit_can_use_what_is_left_under_the_cap_but_not_more(): void
    {
        [$user, $guest] = $this->makeGuestUser('Cap3');
        $r = $this->reservation($guest, $this->discount('Senior Citizen', 20));
        $this->verified($r, 600); // 400 of the 1,000 cap is left

        $this->assertRefused($this->pay($user, $r, 401), '₱400.00');
        $this->assertAccepted($this->pay($user, $r, 400));
    }

    // ---- large discounts: the discounted total, not the 50%, is the cap ----

    public function test_a_fifty_percent_discount_caps_at_the_discounted_total_which_equals_the_fifty_percent(): void
    {
        [$user, $guest] = $this->makeGuestUser('Cap4');
        $r = $this->reservation($guest, $this->discount('Senior Citizen', 50)); // 2,000 -> 1,000

        $this->assertRefused($this->pay($user, $r, 1000.01), '₱1,000.00');
        $this->assertAccepted($this->pay($user, $r, 1000));
    }

    public function test_a_sixty_percent_discount_caps_below_the_fifty_percent(): void
    {
        [$user, $guest] = $this->makeGuestUser('Cap5');
        $r = $this->reservation($guest, $this->discount('Senior Citizen', 60)); // 2,000 -> 800; cap = min(1,000, 800)

        $this->assertRefused($this->pay($user, $r, 800.01), '₱800.00');
        $this->assertAccepted($this->pay($user, $r, 800));
    }

    public function test_the_cap_covers_every_discount_type_not_only_senior_and_pwd(): void
    {
        [$user, $guest] = $this->makeGuestUser('Cap6');
        $r = $this->reservation($guest, $this->discount('VIP', 60)); // not pre-applied by the quote; the cap still sees it

        $this->assertRefused($this->pay($user, $r, 800.01), '₱800.00');
        $this->assertAccepted($this->pay($user, $r, 800));
    }

    public function test_an_eighty_percent_discount_leaves_room_for_exactly_the_minimum_deposit(): void
    {
        [$user, $guest] = $this->makeGuestUser('Cap7');
        $r = $this->reservation($guest, $this->discount('VIP', 80)); // 2,000 -> 400 = the 20% minimum

        $this->assertRefused($this->pay($user, $r, 401), '₱400.00');
        $this->assertAccepted($this->pay($user, $r, 400));
    }

    public function test_a_discount_so_large_that_it_is_below_the_minimum_deposit_allows_no_deposit_online(): void
    {
        [$user, $guest] = $this->makeGuestUser('Cap8');
        $r = $this->reservation($guest, $this->discount('VIP', 90)); // 2,000 -> 200, under the 400 minimum

        $this->assertRefused($this->pay($user, $r, 400), 'maximum deposit');
        $this->assertRefused($this->pay($user, $r, 200), 'maximum deposit');
    }

    public function test_the_reservation_tells_the_app_its_cap(): void
    {
        [, $guest] = $this->makeGuestUser('Cap9');
        $r = $this->reservation($guest, $this->discount('Senior Citizen', 60));

        $this->assertEquals(800.0, $r->fresh()->toArray()['deposit_cap']);
        $r->forceFill(['discount_verification_status' => 'not_requested'])->save();
        $this->assertNull($r->fresh()->toArray()['deposit_cap']);
    }

    // ---- creating a reservation / a booking together with its payment ----

    private function creationPayload($roomType, array $extra): array
    {
        return array_merge([
            'rooms' => [['room_type_id' => $roomType->id, 'quantity' => 1]],
            'check_in' => now('Asia/Manila')->addDays(3)->toDateString(),
            'check_out' => now('Asia/Manila')->addDays(5)->toDateString(),
            'adults' => 1, 'children' => 0,
            'guest_first_name' => 'ClaudeTest', 'guest_last_name' => 'Cap',
            'idempotency_key' => (string) Str::uuid(),
        ], $extra);
    }

    public function test_creating_a_reservation_with_a_large_discount_applies_the_same_cap(): void
    {
        [$user] = $this->makeGuestUser('Cap10');
        $rt = $this->makeRoomTypeWithRooms('Deluxe', 1000, 2, 3);
        $discount = $this->discount('Senior Citizen', 60); // cap 800

        $post = function (float $amount) use ($user, $rt, $discount) {
            $payload = $this->creationPayload($rt, [
                'payment_method' => 'gcash', 'reference_number' => (string) random_int(1000000000000, 9999999999999),
                'gcash_number' => '9171234567', 'amount_paid' => $amount, 'discount_id' => $discount->id,
            ]);
            $request = Request::create('/api/guest/reservations', 'POST', $payload);
            $request->files->set('receipt', UploadedFile::fake()->image('r.jpg'));
            $request->setUserResolver(fn () => $user);
            $this->actingAs($user);

            return app(ReservationController::class)->store($request);
        };

        $this->assertRefused($post(801), '₱800.00');
        $this->assertAccepted($post(800));
    }

    public function test_creating_a_booking_with_a_large_discount_applies_the_same_cap(): void
    {
        [$user] = $this->makeGuestUser('Cap11');
        $rt = $this->makeRoomTypeWithRooms('Deluxe', 1000, 2, 3);
        $discount = $this->discount('VIP', 60); // cap 800

        $post = function (float $amount) use ($user, $rt, $discount) {
            $payload = $this->creationPayload($rt, ['payment_method' => 'cash', 'amount_paid' => $amount, 'discount_id' => $discount->id]);
            $request = Request::create('/api/guest/bookings', 'POST', $payload);
            $request->files->set('id_card_image', UploadedFile::fake()->image('id.jpg'));
            $request->setUserResolver(fn () => $user);
            $this->actingAs($user);

            return app(BookingController::class)->store($request);
        };

        $this->assertRefused($post(801), '₱800.00');
        $this->assertSame(0, Booking::count());
        $this->assertAccepted($post(800));
    }
}
