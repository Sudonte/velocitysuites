<?php

namespace Tests\Feature;

use App\Http\Controllers\Api\BookingController;
use App\Http\Controllers\Api\PaymentController;
use App\Http\Controllers\Api\ReservationController;
use App\Models\Booking;
use App\Models\Discount;
use App\Models\Payment;
use App\Models\Reservation;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Str;

/**
 * While a Senior Citizen / PWD discount waits for the receptionist's ID check (discount_verification_status
 * 'pending') the guest may pay a DEPOSIT only. Full Payment comes back once the discount is approved - and then the
 * total due already includes it - or rejected. Reservation: 1 room x 2 nights x P1,000 = P2,000; Senior Citizen 20%.
 */
class DiscountWaitingFullPaymentTest extends ApiFlowTestCase
{
    private function senior(): Discount
    {
        return Discount::create(['name' => 'Senior Citizen', 'discount_type' => 'percentage', 'value' => 20, 'description' => 'RA 9994', 'status' => 'active']);
    }

    private function reservation($guest, string $discountStatus, ?Discount $discount = null): Reservation
    {
        $rt = \App\Models\RoomType::where('name', 'Deluxe')->first() ?? $this->makeRoomTypeWithRooms('Deluxe', 1000, 2, 3);
        $r = new Reservation();
        $r->forceFill([
            'guest_id' => $guest->id, 'room_type_id' => $rt->id, 'rooms_requested' => 1,
            'check_in' => now('Asia/Manila')->addDays(3)->startOfDay(), 'check_out' => now('Asia/Manila')->addDays(5)->startOfDay(),
            'number_of_guests' => 1, 'adults' => 1, 'children' => 0,
            'status' => Reservation::STATUS_AWAITING_CASH, 'payment_method' => 'cash',
            'discount_requested' => $discountStatus !== 'not_requested',
            'discount_verification_status' => $discountStatus,
            'id_card_type' => $discount?->name, 'discount_id' => $discount?->id,
        ])->save();

        return $r;
    }

    private function earlierPayment(Reservation $r, float $amount): void
    {
        $p = new Payment();
        $p->forceFill([
            'reservation_id' => $r->id, 'payment_method' => 'cash', 'amount_paid' => $amount, 'payment_status' => 'completed',
            'payment_stage' => 'deposit', 'payment_date' => now()->subDay(), 'verified_at' => now()->subHours(2), 'verified_by' => 1,
        ])->save();
    }

    private function pay($user, Reservation $r, string $type, float $amount)
    {
        $request = Request::create("/api/guest/reservations/{$r->id}/payments", 'POST', [
            'payment_method' => 'cash', 'payment_type' => $type, 'amount_paid' => $amount, 'idempotency_key' => (string) Str::uuid(),
        ]);
        $request->setUserResolver(fn () => $user);
        $this->actingAs($user);

        return app(PaymentController::class)->store($request, $r->fresh());
    }

    private function assertAccepted($response): void
    {
        $this->assertEquals(201, $response->getStatusCode(), $response->getContent());
    }

    private function assertRefused($response, string $needle): void
    {
        $this->assertEquals(422, $response->getStatusCode(), $response->getContent());
        $message = json_decode($response->getContent(), true)['message'] ?? $response->getContent();
        $this->assertStringContainsString($needle, $message);
    }

    // ---- pending: deposit only ----

    public function test_while_the_discount_is_pending_full_payment_is_refused_and_a_deposit_is_accepted(): void
    {
        [$user, $guest] = $this->makeGuestUser('Disc1');
        $r = $this->reservation($guest, 'pending', $this->senior());

        $this->assertRefused($this->pay($user, $r, 'full', 2000), 'being verified');
        $this->assertSame(0, Payment::where('reservation_id', $r->id)->count(), 'the refused payment was not recorded');
        $this->assertAccepted($this->pay($user, $r, 'partial', 500));
    }

    public function test_the_pending_message_names_the_deposit_range(): void
    {
        [$user, $guest] = $this->makeGuestUser('Disc2');
        $r = $this->reservation($guest, 'pending', $this->senior());

        $this->assertRefused($this->pay($user, $r, 'full', 2000), '₱400.00 and ₱1,000.00');
    }

    public function test_pending_also_blocks_full_for_the_exact_remaining_balance_after_an_earlier_deposit(): void
    {
        [$user, $guest] = $this->makeGuestUser('Disc3');
        $r = $this->reservation($guest, 'pending', $this->senior());
        $this->earlierPayment($r, 600);

        $this->assertRefused($this->pay($user, $r, 'full', 1400), 'being verified');
        // all payments together stay under the cap: min(50% of 2,000, 1,600 after the 20% discount) = 1,000, so 400 is left
        $this->assertRefused($this->pay($user, $r, 'partial', 500), '₱400.00');
        $this->assertAccepted($this->pay($user, $r, 'partial', 400));
    }

    public function test_once_the_cap_is_used_up_nothing_more_can_be_paid_online(): void
    {
        [$user, $guest] = $this->makeGuestUser('Disc4');
        $r = $this->reservation($guest, 'pending', $this->senior());
        $this->earlierPayment($r, 1000); // exactly the cap

        $this->assertRefused($this->pay($user, $r, 'partial', 1000), "maximum deposit");
        $this->assertRefused($this->pay($user, $r, 'full', 1000), "maximum deposit");
    }

    public function test_pending_with_the_cap_already_exceeded_allows_nothing_more(): void
    {
        [$user, $guest] = $this->makeGuestUser('Disc5');
        $r = $this->reservation($guest, 'pending', $this->senior());
        $this->earlierPayment($r, 1700); // 300 left, under the 400 minimum deposit

        // already past the cap (it cannot happen through the app; the rule still has to hold): nothing more online
        $this->assertRefused($this->pay($user, $r, 'partial', 300), 'maximum deposit');
        $this->assertRefused($this->pay($user, $r, 'full', 300), 'maximum deposit');
    }

    // ---- approved: the discount is in the total ----

    public function test_once_approved_the_total_due_includes_the_discount_and_full_equals_the_discounted_balance(): void
    {
        [$user, $guest] = $this->makeGuestUser('Disc6');
        $r = $this->reservation($guest, 'approved', $this->senior());

        // before a Billing exists the undiscounted 2,000 would have been charged; now it is the quote's 1,600
        $this->assertEquals(1600.0, $r->fresh()->total_amount_due);
        $this->assertRefused($this->pay($user, $r, 'full', 2000), '₱1,600.00');
        $this->assertAccepted($this->pay($user, $r, 'full', 1600));
    }

    public function test_approved_after_a_deposit_full_is_the_discounted_total_minus_what_was_paid(): void
    {
        [$user, $guest] = $this->makeGuestUser('Disc7');
        $r = $this->reservation($guest, 'approved', $this->senior());
        $this->earlierPayment($r, 600);

        $this->assertRefused($this->pay($user, $r, 'full', 1400), '₱1,000.00');
        $this->assertAccepted($this->pay($user, $r, 'full', 1000));
    }

    public function test_approved_deposits_follow_twenty_to_fifty_percent_of_the_discounted_total(): void
    {
        [$user, $guest] = $this->makeGuestUser('Disc8');
        $r = $this->reservation($guest, 'approved', $this->senior());

        $this->assertRefused($this->pay($user, $r, 'partial', 319.99), '₱320.00');
        $this->assertAccepted($this->pay($user, $r, 'partial', 800));
    }

    // ---- rejected / not requested: the normal rules ----

    public function test_a_rejected_discount_leaves_the_normal_rules(): void
    {
        [$user, $guest] = $this->makeGuestUser('Disc9');
        $r = $this->reservation($guest, 'rejected', $this->senior());

        $this->assertEquals(2000.0, $r->fresh()->total_amount_due);
        $this->assertAccepted($this->pay($user, $r, 'full', 2000));
    }

    public function test_no_discount_requested_leaves_the_normal_rules(): void
    {
        [$user, $guest] = $this->makeGuestUser('Disc10');
        $r = $this->reservation($guest, 'not_requested');

        $this->assertAccepted($this->pay($user, $r, 'full', 2000));
    }

    // ---- creating a reservation / a booking with the payment ----

    private function creationPayload($roomType, array $extra): array
    {
        return array_merge([
            'rooms' => [['room_type_id' => $roomType->id, 'quantity' => 1]],
            'check_in' => now('Asia/Manila')->addDays(3)->toDateString(),
            'check_out' => now('Asia/Manila')->addDays(5)->toDateString(),
            'adults' => 1, 'children' => 0,
            'guest_first_name' => 'ClaudeTest', 'guest_last_name' => 'Disc',
            'idempotency_key' => (string) Str::uuid(),
        ], $extra);
    }

    private function postReservation($user, array $payload, ?UploadedFile $receipt = null)
    {
        $request = Request::create('/api/guest/reservations', 'POST', $payload);
        if ($receipt) {
            $request->files->set('receipt', $receipt);
        }
        $request->setUserResolver(fn () => $user);
        $this->actingAs($user);

        try {
            return app(ReservationController::class)->store($request);
        } catch (\Illuminate\Validation\ValidationException $e) {
            return response()->json(['message' => $e->getMessage(), 'errors' => $e->errors()], 422);
        }
    }

    private function gcashFields(float $amount): array
    {
        return ['payment_method' => 'gcash', 'reference_number' => (string) random_int(1000000000000, 9999999999999),
            'gcash_number' => '9171234567', 'amount_paid' => $amount];
    }

    public function test_creating_a_reservation_with_a_discount_and_the_full_total_is_refused_but_a_deposit_goes_through(): void
    {
        [$user] = $this->makeGuestUser('Disc11');
        $rt = $this->makeRoomTypeWithRooms('Deluxe', 1000, 2, 3);
        $discount = $this->senior();

        $full = $this->postReservation($user, $this->creationPayload($rt, $this->gcashFields(2000) + ['discount_id' => $discount->id]), UploadedFile::fake()->image('r.jpg'));
        $this->assertRefused($full, 'being verified');

        $deposit = $this->postReservation($user, $this->creationPayload($rt, $this->gcashFields(500) + ['discount_id' => $discount->id]), UploadedFile::fake()->image('r.jpg'));
        $this->assertAccepted($deposit);

        // and without a discount the full total is still fine
        $plain = $this->postReservation($user, $this->creationPayload($rt, $this->gcashFields(2000)), UploadedFile::fake()->image('r.jpg'));
        $this->assertAccepted($plain);
    }

    public function test_creating_a_booking_with_a_discount_and_the_full_total_is_refused_but_a_deposit_goes_through(): void
    {
        [$user] = $this->makeGuestUser('Disc12');
        $rt = $this->makeRoomTypeWithRooms('Deluxe', 1000, 2, 3);
        $discount = $this->senior();

        $post = function (float $amount) use ($user, $rt, $discount) {
            $payload = $this->creationPayload($rt, ['payment_method' => 'cash', 'amount_paid' => $amount, 'discount_id' => $discount->id]);
            $request = Request::create('/api/guest/bookings', 'POST', $payload);
            $request->files->set('id_card_image', UploadedFile::fake()->image('id.jpg'));
            $request->setUserResolver(fn () => $user);
            $this->actingAs($user);
            try {
                return app(BookingController::class)->store($request);
            } catch (\Illuminate\Validation\ValidationException $e) {
                return response()->json(['message' => $e->getMessage(), 'errors' => $e->errors()], 422);
            }
        };

        $this->assertRefused($post(2000), 'being verified');
        $this->assertSame(0, Booking::count(), 'a refused full payment creates no booking');
        $this->assertAccepted($post(500));
    }
}
