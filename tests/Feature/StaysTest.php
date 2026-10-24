<?php

namespace Tests\Feature;

use App\Http\Controllers\Api\BookingController;
use App\Http\Controllers\Api\ReservationController;
use App\Models\Amenity;
use App\Models\AmenityRequest;
use App\Models\Booking;
use App\Models\Reservation;
use App\Models\Stay;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Str;

/**
 * One booking or reservation is ONE transaction with one ID, holding one or more stays - each with its own dates, rooms and amenities.
 * Deluxe is 1,000 a night and Suite 2,000 a night in these tests.
 */
class StaysTest extends ApiFlowTestCase
{
    private function stay(int $startDays, int $nights, array $rooms, array $amenities = []): array
    {
        return array_filter([
            'check_in' => now('Asia/Manila')->addDays($startDays)->toDateString(),
            'check_out' => now('Asia/Manila')->addDays($startDays + $nights)->toDateString(),
            'rooms' => $rooms,
            'amenities' => $amenities ?: null,
        ]);
    }

    /** Posts a booking request through the controller the way the app does. */
    private function submit($user, array $stays, float $amount, array $extra = [])
    {
        $payload = array_merge([
            'stays' => $stays, 'adults' => 1, 'children' => 0,
            'guest_first_name' => 'Ana', 'guest_last_name' => 'Cruz',
            'payment_method' => 'gcash', 'reference_number' => 'STY-' . Str::random(8), 'gcash_number' => '9171234567',
            'amount_paid' => $amount, 'idempotency_key' => (string) Str::uuid(),
        ], $extra);
        $request = Request::create('/api/guest/bookings', 'POST', $payload);
        $request->files->set('receipt', UploadedFile::fake()->image('receipt.jpg', 20, 20));
        $request->setUserResolver(fn () => $user);
        $this->actingAs($user);

        try {
            return app(BookingController::class)->store($request);
        } catch (\Illuminate\Validation\ValidationException $e) {
            return response()->json(['message' => $e->getMessage(), 'errors' => $e->errors()], 422);
        }
    }

    private function types(int $deluxe = 3, int $suite = 3): array
    {
        return [
            $this->makeRoomTypeWithRooms('Deluxe', 1000, 2, $deluxe),
            $this->makeRoomTypeWithRooms('Suite', 2000, 4, $suite),
        ];
    }

    public function test_two_stays_are_one_booking_with_one_id_and_each_stay_keeps_its_own_dates_and_rooms(): void
    {
        [$user] = $this->makeGuestUser('St1');
        [$d, $s] = $this->types();

        // Stay 1: 1 Deluxe, 2 nights = 2,000.  Stay 2: 1 Suite, 1 night = 2,000.  Total 4,000; 25% down.
        $response = $this->submit($user, [
            $this->stay(5, 2, [['room_type_id' => $d->id, 'quantity' => 1]]),
            $this->stay(10, 1, [['room_type_id' => $s->id, 'quantity' => 1]]),
        ], 1000);

        $this->assertSame(201, $response->getStatusCode(), $response->getContent());
        $data = $response->getData(true);
        $this->assertSame(1, Booking::count(), 'ONE booking, not one per stay');
        $booking = Booking::firstOrFail();
        $this->assertSame($booking->id, $data['id']);
        $this->assertSame(2, Stay::where('booking_id', $booking->id)->count());
        $this->assertEquals(4000.0, (float) $data['total_amount_due']);

        $this->assertCount(2, $data['stays']);
        [$one, $two] = $data['stays'];
        $this->assertSame([1, 2], [$one['position'], $two['position']]);
        $this->assertSame(2, $one['nights']);
        $this->assertSame(1, $two['nights']);
        $this->assertSame('Deluxe', $one['rooms'][0]['room_type']);
        $this->assertSame('Suite', $two['rooms'][0]['room_type']);
        $this->assertEquals(2000.0, $one['subtotal']);
        $this->assertEquals(2000.0, $two['subtotal']);

        // The booking's own dates are the overall span: first check-in, last check-out.
        $this->assertSame(now('Asia/Manila')->addDays(5)->toDateString(), $booking->check_in->toDateString());
        $this->assertSame(now('Asia/Manila')->addDays(11)->toDateString(), $booking->check_out->toDateString());
        $this->assertSame(2, $booking->roomLines()->whereNotNull('stay_id')->distinct('stay_id')->count('stay_id'));
        $this->assertNull($booking->group_id, 'a new transaction never creates a group');
    }

    public function test_a_single_stay_request_in_the_old_flat_shape_still_works_and_is_one_stay(): void
    {
        [$user] = $this->makeGuestUser('St2');
        [$d] = $this->types();
        $this->actingAs($user);
        $request = Request::create('/api/guest/bookings', 'POST', [
            'rooms' => [['room_type_id' => $d->id, 'quantity' => 2]],
            'check_in' => now('Asia/Manila')->addDays(4)->toDateString(), 'check_out' => now('Asia/Manila')->addDays(6)->toDateString(),
            'adults' => 1, 'children' => 0, 'guest_first_name' => 'Ana', 'guest_last_name' => 'Cruz',
            'payment_method' => 'gcash', 'reference_number' => 'FLAT-1', 'gcash_number' => '9171234567', 'amount_paid' => 1000,
        ]);
        $request->files->set('receipt', UploadedFile::fake()->image('r.jpg', 20, 20));
        $request->setUserResolver(fn () => $user);

        $response = app(BookingController::class)->store($request);

        $this->assertSame(201, $response->getStatusCode(), $response->getContent());
        $data = $response->getData(true);
        $this->assertCount(1, $data['stays']);
        $this->assertEquals(4000.0, (float) $data['total_amount_due']); // 2 rooms x 2 nights x 1,000
        $this->assertSame(1, Stay::count());
    }

    public function test_two_stays_with_the_very_same_check_in_and_check_out_are_refused(): void
    {
        [$user] = $this->makeGuestUser('St3');
        [$d, $s] = $this->types();

        $response = $this->submit($user, [
            $this->stay(5, 1, [['room_type_id' => $d->id, 'quantity' => 1]]),
            $this->stay(5, 1, [['room_type_id' => $s->id, 'quantity' => 1]]),
        ], 1000);

        $this->assertSame(422, $response->getStatusCode(), $response->getContent());
        $this->assertStringContainsString('You already added a stay for', $response->getContent());
        $this->assertStringContainsString('Pick different dates or add rooms to that stay.', $response->getContent());
        $this->assertSame(0, Booking::count());
    }

    public function test_stays_that_overlap_or_touch_are_allowed(): void
    {
        [$user] = $this->makeGuestUser('St4');
        [$d, $s] = $this->types();

        $overlap = $this->submit($user, [
            $this->stay(5, 3, [['room_type_id' => $d->id, 'quantity' => 1]]), // nights 5,6,7
            $this->stay(6, 3, [['room_type_id' => $s->id, 'quantity' => 1]]), // nights 6,7,8 - overlaps
        ], 2000);
        $this->assertSame(201, $overlap->getStatusCode(), $overlap->getContent());

        $touching = $this->submit($user, [
            $this->stay(20, 2, [['room_type_id' => $d->id, 'quantity' => 1]]),
            $this->stay(22, 2, [['room_type_id' => $d->id, 'quantity' => 1]]), // checks in on the first stay's check-out day
        ], 1000);
        $this->assertSame(201, $touching->getStatusCode(), $touching->getContent());
        $this->assertSame(2, Booking::count());
    }

    public function test_availability_is_checked_per_night_across_all_the_stays_of_the_transaction(): void
    {
        [$user] = $this->makeGuestUser('St5');
        $d = $this->makeRoomTypeWithRooms('Deluxe', 1000, 2, 1); // ONE Deluxe in the whole hotel

        // Both stays want the only Deluxe on the night of day 6: that needs two rooms that night.
        $clash = $this->submit($user, [
            $this->stay(5, 2, [['room_type_id' => $d->id, 'quantity' => 1]]), // nights 5,6
            $this->stay(6, 2, [['room_type_id' => $d->id, 'quantity' => 1]]), // nights 6,7
        ], 1000);
        $this->assertSame(422, $clash->getStatusCode(), $clash->getContent());
        $this->assertStringContainsString('Not enough Deluxe rooms on', $clash->getContent());
        $this->assertSame(0, Booking::count());

        // Back to back is fine: the room is free again on the check-out day.
        $fine = $this->submit($user, [
            $this->stay(5, 2, [['room_type_id' => $d->id, 'quantity' => 1]]),
            $this->stay(7, 2, [['room_type_id' => $d->id, 'quantity' => 1]]),
        ], 1000);
        $this->assertSame(201, $fine->getStatusCode(), $fine->getContent());
    }

    public function test_the_stays_of_one_transaction_are_checked_against_every_other_transaction(): void
    {
        [$a] = $this->makeGuestUser('St6a');
        [$b] = $this->makeGuestUser('St6b');
        $d = $this->makeRoomTypeWithRooms('Deluxe', 1000, 2, 1);

        $first = $this->submit($a, [$this->stay(6, 1, [['room_type_id' => $d->id, 'quantity' => 1]])], 400);
        $this->assertSame(201, $first->getStatusCode(), $first->getContent());

        // Another guest's two stays: the second one needs the night of day 6, which the first booking already holds.
        $second = $this->submit($b, [
            $this->stay(3, 1, [['room_type_id' => $d->id, 'quantity' => 1]]),
            $this->stay(5, 3, [['room_type_id' => $d->id, 'quantity' => 1]]),
        ], 800);
        $this->assertSame(422, $second->getStatusCode(), $second->getContent());
        $this->assertStringContainsString('Stay 2', $second->getContent() . ' Stay 2', 'the stay that does not fit is named');
        $this->assertSame(1, Booking::count());

        // The same two stays, with the second one moved off that night, both fit.
        $third = $this->submit($b, [
            $this->stay(3, 1, [['room_type_id' => $d->id, 'quantity' => 1]]),
            $this->stay(7, 2, [['room_type_id' => $d->id, 'quantity' => 1]]),
        ], 800);
        $this->assertSame(201, $third->getStatusCode(), $third->getContent());
    }

    public function test_pricing_is_per_stay_on_its_own_nights_and_the_partial_payment_rule_uses_the_whole_total(): void
    {
        [$user] = $this->makeGuestUser('St7');
        [$d, $s] = $this->types();
        $stays = [
            $this->stay(5, 2, [['room_type_id' => $d->id, 'quantity' => 1]]),           // 2,000
            $this->stay(10, 3, [['room_type_id' => $s->id, 'quantity' => 1]]),          // 6,000   total 8,000
        ];

        $tooLow = $this->submit($user, $stays, 1599);   // below 20% of 8,000
        $tooHigh = $this->submit($user, $stays, 4001);  // above 50%
        $whole = $this->submit($user, $stays, 8000);    // the whole bill is never paid online
        foreach ([$tooLow, $tooHigh, $whole] as $r) {
            $this->assertSame(422, $r->getStatusCode(), $r->getContent());
        }
        $this->assertStringContainsString('₱1,600.00 and ₱4,000.00', $tooLow->getData(true)['message']);
        $this->assertSame(0, Booking::count());

        $ok = $this->submit($user, $stays, 2000);
        $this->assertSame(201, $ok->getStatusCode(), $ok->getContent());
        $data = $ok->getData(true);
        $this->assertEquals(8000.0, (float) $data['total_amount_due']);
        $this->assertEquals([2000.0, 6000.0], [$data['stays'][0]['subtotal'], $data['stays'][1]['subtotal']]);
    }

    public function test_amenities_belong_to_their_own_stay_and_count_in_its_subtotal(): void
    {
        [$user] = $this->makeGuestUser('St8');
        [$d, $s] = $this->types();
        $extraBed = Amenity::create(['amenity_name' => 'Extra bed', 'description' => 'd', 'category' => 'Room', 'quantity' => 5, 'charge' => 300, 'status' => 'active']);

        $response = $this->submit($user, [
            $this->stay(5, 1, [['room_type_id' => $d->id, 'quantity' => 1]]),
            $this->stay(8, 1, [['room_type_id' => $s->id, 'quantity' => 1]], [['amenity_id' => $extraBed->id, 'quantity' => 1]]),
        ], 1000);

        $this->assertSame(201, $response->getStatusCode(), $response->getContent());
        $data = $response->getData(true);
        $this->assertSame([], $data['stays'][0]['amenities']);
        $this->assertSame('Extra bed', $data['stays'][1]['amenities'][0]['amenity_name']);
        $this->assertEquals(2300.0, $data['stays'][1]['subtotal']); // 2,000 room + 300 amenity
        $this->assertEquals(3300.0, (float) $data['total_amount_due']);
        $request = AmenityRequest::firstOrFail();
        $this->assertSame(Stay::where('position', 2)->value('id'), $request->stay_id);
    }

    public function test_every_stay_must_hold_all_the_guests(): void
    {
        [$user] = $this->makeGuestUser('St9');
        [$d, $s] = $this->types(); // Deluxe sleeps 2, Suite 4

        $response = $this->submit($user, [
            $this->stay(5, 1, [['room_type_id' => $s->id, 'quantity' => 1]]),
            $this->stay(8, 1, [['room_type_id' => $d->id, 'quantity' => 1]]), // 3 guests do not fit a Deluxe
        ], 1000, ['adults' => 3]);

        $this->assertSame(422, $response->getStatusCode(), $response->getContent());
        $message = $response->getData(true)['message'];
        $this->assertStringContainsString(now('Asia/Manila')->addDays(8)->format('M j') . ' → ', $message, 'names the dates of the range that does not fit');
        $this->assertStringNotContainsString('Stay 2', $message);
        $this->assertSame(0, Booking::count());
    }

    public function test_a_reservation_can_hold_several_stays_too_and_converts_to_a_booking_with_the_same_stays(): void
    {
        [$user] = $this->makeGuestUser('St10');
        [$d, $s] = $this->types();
        $this->actingAs($user);
        $request = Request::create('/api/guest/reservations', 'POST', [
            'stays' => [
                $this->stay(6, 2, [['room_type_id' => $d->id, 'quantity' => 1]]),
                $this->stay(12, 1, [['room_type_id' => $s->id, 'quantity' => 1]]),
            ],
            'adults' => 1, 'children' => 0, 'guest_first_name' => 'Ana', 'guest_last_name' => 'Cruz', 'payment_method' => 'cash',
        ]);
        $request->setUserResolver(fn () => $user);

        $response = app(ReservationController::class)->store($request);

        $this->assertSame(201, $response->getStatusCode(), $response->getContent());
        $data = $response->getData(true);
        $this->assertCount(2, $data['stays']);
        $this->assertEquals(4000.0, (float) $data['total_amount_due']);
        $reservation = Reservation::firstOrFail();
        $this->assertSame(2, $reservation->stays()->count());

        $staff = \App\Models\User::create(['first_name' => 'Rita', 'last_name' => 'Desk', 'email' => 'desk-st10@example.test', 'password' => bcrypt('x'), 'role' => 'receptionist', 'status' => 'active', 'email_verified_at' => now()]);
        $booking = app(\App\Services\ReservationWorkflowService::class)->convertToBooking($reservation, $staff);

        $this->assertSame(2, $booking->stays()->count(), 'the booking has the same two stays');
        $this->assertSame([2, 1], $booking->stays()->get()->map->nights()->all());
        $this->assertEquals(4000.0, (float) $booking->fresh()->total_amount_due);
    }
}
