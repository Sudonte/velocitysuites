<?php

namespace Tests\Feature;

use App\Http\Controllers\Api\BookingController;
use App\Http\Controllers\Api\CatalogController;
use App\Http\Controllers\Api\ReservationController;
use App\Models\Booking;
use App\Models\Discount;
use App\Models\Reservation;
use App\Support\DiscountSelection;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Str;

/**
 * Server-side enforcement of the rules the app applies in the booking /
 * reservation wizards (check-in window, guest capacity, active-only discounts):
 * a request that skips the app's own checks must still be rejected.
 */
class GuestRulesApiTest extends ApiFlowTestCase
{
    private function discount(string $name, string $status = 'active', float $value = 10): Discount
    {
        return Discount::create(['name' => $name, 'discount_type' => 'percentage', 'value' => $value, 'description' => $name . ' desc', 'status' => $status]);
    }

    private function reservationPayload($roomType, array $overrides = []): array
    {
        return array_merge([
            'rooms' => [['room_type_id' => $roomType->id, 'quantity' => 1]],
            'check_in' => now('Asia/Manila')->addDay()->toDateString(),
            'check_out' => now('Asia/Manila')->addDays(3)->toDateString(),
            'adults' => 1,
            'children' => 0,
            'guest_first_name' => 'ClaudeTest',
            'guest_last_name' => 'Rules',
            'payment_method' => 'cash',
        ], $overrides);
    }

    private function bookingPayload($roomType, float $amount, array $overrides = []): array
    {
        return array_merge($this->reservationPayload($roomType), [
            'amount_paid' => $amount,
            'idempotency_key' => (string) Str::uuid(),
        ], $overrides);
    }

    private function postReservation($user, array $payload)
    {
        $request = Request::create('/api/guest/reservations', 'POST', $payload);
        $request->setUserResolver(fn () => $user);
        $this->actingAs($user);

        return $this->asJson(fn () => app(ReservationController::class)->store($request));
    }

    /** Controllers called directly let a failed validate() throw; the HTTP layer would turn that into a 422 - do the same here. */
    private function asJson(callable $call)
    {
        try {
            return $call();
        } catch (\Illuminate\Validation\ValidationException $e) {
            return response()->json(['message' => $e->getMessage(), 'errors' => $e->errors()], 422);
        }
    }

    private function postBooking($user, array $payload, ?UploadedFile $idImage = null)
    {
        $request = Request::create('/api/guest/bookings', 'POST', $payload);
        if ($idImage) {
            $request->files->set('id_card_image', $idImage);
        }
        $request->setUserResolver(fn () => $user);
        $this->actingAs($user);

        return $this->asJson(fn () => app(BookingController::class)->store($request));
    }

    private function assertRejected($response, string $needle): void
    {
        $this->assertContains($response->getStatusCode(), [422], $response->getContent());
        $this->assertStringContainsStringIgnoringCase($needle, $response->getContent());
    }

    // ---- Task 1: check-in window -------------------------------------------------

    public function test_reservation_check_in_must_be_within_two_days_of_manila_today(): void
    {
        $rt = $this->makeRoomTypeWithRooms('Deluxe', 1000, 2, 9);
        $today = now('Asia/Manila');

        foreach ([0, 1, 2] as $offset) {
            [$user] = $this->makeGuestUser('Window' . $offset);
            $res = $this->postReservation($user, $this->reservationPayload($rt, [
                'check_in' => $today->copy()->addDays($offset)->toDateString(),
                'check_out' => $today->copy()->addDays($offset + 4)->toDateString(),
                'idempotency_key' => (string) Str::uuid(),
            ]));
            $this->assertEquals(201, $res->getStatusCode(), "offset {$offset}: " . $res->getContent());
        }

        foreach ([-1, 3] as $offset) {
            [$user] = $this->makeGuestUser('WindowBad' . ($offset + 1));
            $res = $this->postReservation($user, $this->reservationPayload($rt, [
                'check_in' => $today->copy()->addDays($offset)->toDateString(),
                'check_out' => $today->copy()->addDays($offset + 2)->toDateString(),
            ]));
            $this->assertRejected($res, 'Check-in must be today or within the next 2 days');
        }
    }

    public function test_booking_check_in_outside_window_is_rejected(): void
    {
        [$user] = $this->makeGuestUser('WindowB');
        $rt = $this->makeRoomTypeWithRooms('Deluxe', 1000, 2, 3);
        $res = $this->postBooking($user, $this->bookingPayload($rt, 1000, [
            'check_in' => now('Asia/Manila')->addDays(3)->toDateString(),
            'check_out' => now('Asia/Manila')->addDays(4)->toDateString(),
        ]));
        $this->assertRejected($res, 'Check-in must be today or within the next 2 days');
    }

    // ---- Task 5: guest capacity ---------------------------------------------------

    public function test_guest_count_may_use_full_room_capacity_in_any_mix_but_not_exceed_it(): void
    {
        [$user] = $this->makeGuestUser('Cap');
        [$user2] = $this->makeGuestUser('Cap2');
        $rt = $this->makeRoomTypeWithRooms('Family', 1000, 6, 3);

        // 1 adult + 5 children = 6: no fixed 3-children cap any more.
        $ok = $this->postReservation($user, $this->reservationPayload($rt, ['adults' => 1, 'children' => 5, 'idempotency_key' => (string) Str::uuid()]));
        $this->assertEquals(201, $ok->getStatusCode(), $ok->getContent());

        $tooMany = $this->postReservation($user2, $this->reservationPayload($rt, ['adults' => 4, 'children' => 3]));
        $this->assertRejected($tooMany, 'exceed the total capacity');

        $bookingTooMany = $this->postBooking($user2, $this->bookingPayload($rt, 2000, ['adults' => 4, 'children' => 3]));
        $this->assertRejected($bookingTooMany, 'exceed the total capacity');
    }

    public function test_capacity_sums_every_selected_room(): void
    {
        [$user] = $this->makeGuestUser('CapSum');
        $rt = $this->makeRoomTypeWithRooms('Twin', 1000, 2, 3);
        $res = $this->postReservation($user, $this->reservationPayload($rt, [
            'rooms' => [['room_type_id' => $rt->id, 'quantity' => 2]],
            'adults' => 2, 'children' => 2, 'idempotency_key' => (string) Str::uuid(),
        ]));
        $this->assertEquals(201, $res->getStatusCode(), $res->getContent());
    }

    // ---- Task 4: active-only discounts --------------------------------------------

    public function test_discount_catalog_lists_only_active_discounts(): void
    {
        $this->discount('Senior Citizen', 'active', 20);
        $this->discount('Retired Promo', 'inactive');

        $payload = json_decode(app(CatalogController::class)->discounts()->getContent(), true);
        $names = array_column($payload, 'name');
        $this->assertSame(['Senior Citizen'], $names);
        foreach (['id', 'name', 'discount_type', 'value', 'description', 'status'] as $field) {
            $this->assertArrayHasKey($field, $payload[0]);
        }
    }

    public function test_discount_selection_resolver(): void
    {
        $active = $this->discount('Active');
        $inactive = $this->discount('Inactive', 'inactive');

        $this->assertSame([null, null], DiscountSelection::resolve(null, null));
        $this->assertSame([null, null], DiscountSelection::resolve(null, 'None'));
        $this->assertSame($active->id, DiscountSelection::resolve($active->id, null)[0]->id);
        $this->assertNotNull(DiscountSelection::resolve($inactive->id, null)[1]);
        $this->assertNotNull(DiscountSelection::resolve(9999, null)[1]);
        // an edit may keep its already-chosen discount even once the admin deactivated it
        $this->assertNull(DiscountSelection::resolve($inactive->id, null, $inactive->id)[1]);
        // older app builds send only the name
        $this->assertSame($active->id, DiscountSelection::resolve(null, 'Active')[0]->id);
        $this->assertNotNull(DiscountSelection::resolve(null, 'Inactive')[1]);
    }

    public function test_reservation_rejects_inactive_discount_and_stores_active_one(): void
    {
        [$user] = $this->makeGuestUser('Disc');
        $rt = $this->makeRoomTypeWithRooms('Deluxe', 1000, 2, 3);
        $inactive = $this->discount('Retired Promo', 'inactive');
        $active = $this->discount('VIP', 'active', 15);

        $bad = $this->postReservation($user, $this->reservationPayload($rt, ['discount_id' => $inactive->id]));
        $this->assertRejected($bad, 'no longer available');

        $good = $this->postReservation($user, $this->reservationPayload($rt, ['discount_id' => $active->id, 'idempotency_key' => (string) Str::uuid()]));
        $this->assertEquals(201, $good->getStatusCode(), $good->getContent());
        $reservation = Reservation::latest('id')->first();
        $this->assertSame($active->id, (int) $reservation->discount_id);
        $this->assertSame('VIP', $reservation->id_card_type);
        $this->assertTrue((bool) $reservation->discount_requested);
        $this->assertSame('pending', $reservation->discount_verification_status);
    }

    public function test_booking_with_discount_requires_an_id_image_and_an_active_discount(): void
    {
        [$user] = $this->makeGuestUser('DiscB');
        $rt = $this->makeRoomTypeWithRooms('Deluxe', 1000, 2, 3);
        $inactive = $this->discount('Retired Promo', 'inactive');
        $active = $this->discount('VIP', 'active', 15);
        $total = 2 * 1000; // 2 nights

        $this->assertRejected($this->postBooking($user, $this->bookingPayload($rt, $total, ['discount_id' => $inactive->id]), UploadedFile::fake()->image('id.jpg')), 'no longer available');
        $this->assertRejected($this->postBooking($user, $this->bookingPayload($rt, $total, ['discount_id' => $active->id])), 'upload a valid ID');

        $ok = $this->postBooking($user, $this->bookingPayload($rt, $total, ['discount_id' => $active->id]), UploadedFile::fake()->image('id.jpg'));
        $this->assertEquals(201, $ok->getStatusCode(), $ok->getContent());
        $booking = Booking::latest('id')->first();
        $this->assertSame($active->id, (int) $booking->discount_id);
        $this->assertSame('VIP', $booking->id_card_type);
        $this->assertNotEmpty($booking->id_card_image_path);
    }
}
