<?php

namespace Tests\Feature;

use App\Http\Controllers\Api\BookingController;
use App\Http\Controllers\Api\ReservationController;
use App\Models\Amenity;
use App\Models\AmenityRequest;
use App\Models\Booking;
use App\Models\Reservation;
use App\Models\ReservationAmenity;
use App\Support\RoomLineRef;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Str;

/**
 * Booking-time amenities are chosen per room: the amenity rows keep a reference to the booked room line (and which room of it), the API
 * and the bill say "Deluxe (room 2)", and a row without a room (an older one, or one for the whole stay) stays under its stay as before.
 */
class RoomAmenityTest extends ApiFlowTestCase
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

    private function book($user, array $stays, float $amount)
    {
        $request = Request::create('/api/guest/bookings', 'POST', [
            'stays' => $stays, 'adults' => 1, 'children' => 0, 'guest_first_name' => 'Ana', 'guest_last_name' => 'Cruz',
            'payment_method' => 'gcash', 'reference_number' => 'RA-' . Str::random(8), 'gcash_number' => '9171234567',
            'amount_paid' => $amount, 'idempotency_key' => (string) Str::uuid(),
        ]);
        $request->files->set('receipt', UploadedFile::fake()->image('receipt.jpg', 20, 20));
        $request->setUserResolver(fn () => $user);
        $this->actingAs($user);
        try {
            return app(BookingController::class)->store($request);
        } catch (\Illuminate\Validation\ValidationException $e) {
            return response()->json(['message' => $e->getMessage(), 'errors' => $e->errors()], 422);
        }
    }

    private function bed(): Amenity
    {
        return Amenity::create(['amenity_name' => 'Extra bed', 'description' => 'd', 'category' => 'Room', 'quantity' => 9, 'charge' => 300, 'status' => 'active']);
    }

    public function test_an_amenity_chosen_for_a_room_keeps_the_room_line_and_which_room_of_it(): void
    {
        [$user] = $this->makeGuestUser('RA1');
        $deluxe = $this->makeRoomTypeWithRooms('Deluxe', 1000, 2, 3);
        $bed = $this->bed();

        $response = $this->book($user, [
            $this->stay(5, 1, [['room_type_id' => $deluxe->id, 'quantity' => 2]], [
                ['amenity_id' => $bed->id, 'quantity' => 1, 'room_type_id' => $deluxe->id, 'room_slot' => 2],
                ['amenity_id' => $bed->id, 'quantity' => 2, 'room_type_id' => $deluxe->id, 'room_slot' => 1],
            ]),
        ], 1000);

        $this->assertSame(201, $response->getStatusCode(), $response->getContent());
        $line = Booking::firstOrFail()->roomLines()->firstOrFail();
        $rows = AmenityRequest::orderBy('id')->get();
        $this->assertCount(2, $rows, 'one row per room, not merged into one');
        $this->assertSame($line->id, $rows[0]->room_line_id);
        $this->assertSame(2, $rows[0]->room_slot);
        $this->assertSame(1, $rows[1]->room_slot);
        $this->assertSame('Deluxe (room 2)', $rows[0]->room_label);

        $data = $response->getData(true);
        $labels = collect($data['stays'][0]['amenities'])->pluck('room_label')->sort()->values()->all();
        $this->assertSame(['Deluxe (room 1)', 'Deluxe (room 2)'], $labels);
        $this->assertEquals(900.0, $data['stays'][0]['amenities_subtotal'], 'the money is unchanged: 3 beds x 300');
        $this->assertEquals(2900.0, (float) $data['total_amount_due']);
        $this->assertSame('Deluxe (room 1)', collect($data['amenities'])->firstWhere('quantity', 2)['room_label']);
    }

    public function test_an_amenity_without_a_room_stays_under_the_stay_as_before(): void
    {
        [$user] = $this->makeGuestUser('RA2');
        $deluxe = $this->makeRoomTypeWithRooms('Deluxe', 1000, 2, 3);
        $bed = $this->bed();

        $response = $this->book($user, [$this->stay(5, 1, [['room_type_id' => $deluxe->id, 'quantity' => 1]], [['amenity_id' => $bed->id, 'quantity' => 1]])], 500);

        $this->assertSame(201, $response->getStatusCode(), $response->getContent());
        $row = AmenityRequest::firstOrFail();
        $this->assertNull($row->room_line_id);
        $this->assertNull($row->room_slot);
        $this->assertNull($row->room_label);
        $payload = $response->getData(true)['stays'][0]['amenities'][0];
        $this->assertNull($payload['room_label']);
        $this->assertSame('Extra bed', $payload['amenity_name']);
    }

    public function test_a_room_that_is_not_part_of_the_stay_is_refused(): void
    {
        [$user] = $this->makeGuestUser('RA3');
        $deluxe = $this->makeRoomTypeWithRooms('Deluxe', 1000, 2, 3);
        $suite = $this->makeRoomTypeWithRooms('Suite', 2000, 4, 3);
        $bed = $this->bed();

        $notBooked = $this->book($user, [$this->stay(5, 1, [['room_type_id' => $deluxe->id, 'quantity' => 1]], [['amenity_id' => $bed->id, 'quantity' => 1, 'room_type_id' => $suite->id, 'room_slot' => 1]])], 500);
        $this->assertSame(422, $notBooked->getStatusCode(), $notBooked->getContent());

        $tooHigh = $this->book($user, [$this->stay(5, 1, [['room_type_id' => $deluxe->id, 'quantity' => 1]], [['amenity_id' => $bed->id, 'quantity' => 1, 'room_type_id' => $deluxe->id, 'room_slot' => 2]])], 500);
        $this->assertSame(422, $tooHigh->getStatusCode(), 'room 2 of a one-room line');
        $this->assertSame(0, Booking::count());
    }

    public function test_each_stay_keeps_its_own_rooms_amenities(): void
    {
        [$user] = $this->makeGuestUser('RA4');
        $deluxe = $this->makeRoomTypeWithRooms('Deluxe', 1000, 2, 3);
        $suite = $this->makeRoomTypeWithRooms('Suite', 2000, 4, 3);
        $bed = $this->bed();

        $response = $this->book($user, [
            $this->stay(5, 1, [['room_type_id' => $deluxe->id, 'quantity' => 1]], [['amenity_id' => $bed->id, 'quantity' => 1, 'room_type_id' => $deluxe->id, 'room_slot' => 1]]),
            $this->stay(9, 1, [['room_type_id' => $suite->id, 'quantity' => 1], ['room_type_id' => $deluxe->id, 'quantity' => 1]], [['amenity_id' => $bed->id, 'quantity' => 1, 'room_type_id' => $suite->id, 'room_slot' => 1]]),
        ], 1000);

        $this->assertSame(201, $response->getStatusCode(), $response->getContent());
        $stays = $response->getData(true)['stays'];
        $this->assertSame('Deluxe (room 1)', $stays[0]['amenities'][0]['room_label']);
        $this->assertSame('Suite (room 1)', $stays[1]['amenities'][0]['room_label']);
        $lines = Booking::firstOrFail()->roomLines()->orderBy('id')->get();
        $second = AmenityRequest::orderBy('id')->get()[1];
        $this->assertSame($lines->firstWhere('room_type_name', 'Suite')->id, $second->room_line_id, 'the Suite line of stay 2, not another stay line');
    }

    public function test_a_reservation_keeps_the_room_too_and_it_survives_conversion(): void
    {
        [$user] = $this->makeGuestUser('RA5');
        $deluxe = $this->makeRoomTypeWithRooms('Deluxe', 1000, 2, 3);
        $bed = $this->bed();
        $this->actingAs($user);
        $request = Request::create('/api/guest/reservations', 'POST', [
            'stays' => [$this->stay(6, 1, [['room_type_id' => $deluxe->id, 'quantity' => 2]], [['amenity_id' => $bed->id, 'quantity' => 1, 'room_type_id' => $deluxe->id, 'room_slot' => 2]])],
            'adults' => 1, 'children' => 0, 'guest_first_name' => 'Ana', 'guest_last_name' => 'Cruz', 'payment_method' => 'cash',
        ]);
        $request->setUserResolver(fn () => $user);

        $response = app(ReservationController::class)->store($request);

        $this->assertSame(201, $response->getStatusCode(), $response->getContent());
        $this->assertSame('Deluxe (room 2)', $response->getData(true)['stays'][0]['amenities'][0]['room_label']);
        $snapshot = ReservationAmenity::firstOrFail();
        $this->assertSame(2, $snapshot->room_slot);
        $this->assertSame('Deluxe (room 2)', $snapshot->room_label);
        $reservation = Reservation::firstOrFail();
        $this->assertSame('Deluxe (room 2)', $reservation->amenities[0]['room_label']);

        $staff = \App\Models\User::create(['first_name' => 'Rita', 'last_name' => 'Desk', 'email' => 'desk-ra5@example.test', 'password' => bcrypt('x'), 'role' => 'receptionist', 'status' => 'active', 'email_verified_at' => now()]);
        $booking = app(\App\Services\ReservationWorkflowService::class)->convertToBooking($reservation, $staff);
        RoomLineRef::forget();
        $payload = $booking->fresh()->append(['amenities'])->withStays()->toArray();
        $this->assertSame('Deluxe (room 2)', $payload['stays'][0]['amenities'][0]['room_label'], 'still named after the room once it is a booking');
        $this->assertEquals(2300.0, (float) $booking->fresh()->total_amount_due);
    }

    public function test_old_rows_without_a_room_have_no_label_and_no_crash(): void
    {
        $this->assertNull(RoomLineRef::label((object) ['room_line_id' => null, 'room_slot' => null]));
        $this->assertNull(RoomLineRef::label((object) ['room_line_id' => 99999, 'room_slot' => 1, 'reservation_id' => null]), 'a line that no longer exists');
    }

    public function test_the_bill_names_the_room_of_each_amenity_line_and_the_stay_section_does_too(): void
    {
        [$user] = $this->makeGuestUser('RA6');
        $deluxe = $this->makeRoomTypeWithRooms('Deluxe', 1000, 2, 3);
        $bed = $this->bed();
        $response = $this->book($user, [$this->stay(5, 1, [['room_type_id' => $deluxe->id, 'quantity' => 2]], [
            ['amenity_id' => $bed->id, 'quantity' => 1, 'room_type_id' => $deluxe->id, 'room_slot' => 2],
        ])], 1000);
        $this->assertSame(201, $response->getStatusCode(), $response->getContent());
        AmenityRequest::query()->update(['status' => 'approved']);

        $bill = \App\Support\StayBill::forBooking(Booking::firstOrFail()->fresh(), \App\Support\StayBill::PROJECTED);

        $this->assertSame('Deluxe (room 2)', $bill['amenity_lines'][0]['room_label']);
        $this->assertSame('Deluxe (room 2)', $bill['stays'][0]['amenity_lines'][0]['room_label']);
        $this->assertEquals(300.0, $bill['amenity_charge']);
    }
}
