<?php

namespace Tests\Feature;

use App\Models\Amenity;
use App\Models\AmenityRequest;
use App\Models\Booking;
use App\Models\Reservation;
use App\Models\Room;
use App\Models\User;
use App\Support\StayBill;
use Carbon\Carbon;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;

/**
 * Part 5 (same on the app): a BOOKING's guest can request amenities - included and paid - from the moment the booking exists until
 * check-out. Before check-in the request names the booked room ("Deluxe (room 2)"); during the stay, the assigned room. Every request
 * waits for the front desk; a paid one is billed when approved, an included one adds nothing. At check-in a request made earlier moves
 * to the room that was assigned. A reservation has no amenities until it becomes a booking.
 */
class AmenitiesAfterBookingTest extends ApiFlowTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        foreach (['api_tokens' => function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('user_id');
            $table->string('token')->unique();
            $table->string('device_name')->nullable();
            $table->timestamp('last_used_at')->nullable();
            $table->timestamps();
        }, 'promotion_amenity' => function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('promotion_id');
            $table->unsignedBigInteger('amenity_id');
            $table->unsignedInteger('quantity')->default(1);
            $table->timestamps();
        }] as $name => $definition) {
            if (! Schema::hasTable($name)) {
                Schema::create($name, $definition);
            }
        }
        Storage::fake('local');
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function asGuest(User $user): static
    {
        $plain = 'aab-token-' . $user->id;
        DB::table('api_tokens')->updateOrInsert(['user_id' => $user->id], ['token' => hash('sha256', $plain), 'created_at' => now(), 'updated_at' => now()]);

        return $this->withHeaders(['Authorization' => "Bearer {$plain}"]);
    }

    private function desk(): User
    {
        return User::firstOrCreate(['email' => 'desk-aab@hotel.com'], [
            'first_name' => 'Rita', 'last_name' => 'Desk', 'password' => bcrypt('x'), 'role' => 'receptionist', 'status' => 'active', 'email_verified_at' => now(),
        ]);
    }

    private function amenity(string $name, float $charge): Amenity
    {
        return Amenity::create(['amenity_name' => $name, 'description' => 'd', 'category' => 'Room', 'quantity' => 20, 'charge' => $charge, 'status' => 'active']);
    }

    /** A confirmed booking of 2 Deluxe rooms arriving today, 2 nights x 1,000 x 2 = 4,000, not checked in. */
    private function confirmedBooking(): array
    {
        [$user, $guest] = $this->makeGuestUser('AAB' . uniqid());
        $rt = $this->makeRoomTypeWithRooms('Deluxe', 1000, 2, 3);
        $booking = Booking::create([
            'guest_id' => $guest->id, 'room_type_id' => $rt->id, 'rooms_requested' => 2, 'guest_first_name' => 'Ana', 'guest_last_name' => 'Cruz',
            'check_in' => today()->toDateString(), 'check_out' => today()->addDays(2)->toDateString(),
            'adults' => 2, 'children' => 0, 'number_of_guests' => 2, 'payment_method' => 'cash',
            'booking_status' => Booking::STATUS_ACTIVE, 'confirmed_at' => now(), 'verified_at' => now(),
        ]);
        $booking->roomLines()->create(['room_type_id' => $rt->id, 'room_type_name' => 'Deluxe', 'quantity' => 2, 'price_per_night' => 1000, 'number_of_nights' => 2, 'subtotal' => 4000]);

        return [$user, $booking->fresh(), $rt];
    }

    private function url(Booking $booking): string
    {
        return "/api/guest/bookings/{$booking->id}/additional-amenities";
    }

    // ---- before check-in

    public function test_before_check_in_the_booking_lists_its_booked_rooms_to_choose_from(): void
    {
        [$user, $booking, $rt] = $this->confirmedBooking();

        $info = $this->asGuest($user)->getJson($this->url($booking))->assertOk()->json();

        $this->assertTrue($info['can_request']);
        $this->assertNull($info['reason']);
        $this->assertCount(1, $info['stays']);
        $this->assertSame('upcoming', $info['stays'][0]['state']);
        $this->assertSame([], $info['stays'][0]['rooms'], 'no room is assigned yet');
        $this->assertSame(['Deluxe (room 1)', 'Deluxe (room 2)'], array_column($info['stays'][0]['slots'], 'label'));
        $this->assertSame([$rt->id, $rt->id], array_column($info['stays'][0]['slots'], 'room_type_id'));
        $this->assertSame([1, 2], array_column($info['stays'][0]['slots'], 'room_slot'));
    }

    public function test_a_request_before_check_in_keeps_the_booked_room_waits_for_approval_and_bills_only_the_paid_item(): void
    {
        [$user, $booking, $rt] = $this->confirmedBooking();
        $bed = $this->amenity('Extra bed', 300);
        $pillow = $this->amenity('Extra pillow', 0);
        $desk = $this->desk();

        $res = $this->asGuest($user)->postJson($this->url($booking), ['rooms' => [[
            'room_type_id' => $rt->id, 'room_slot' => 2,
            'items' => [['amenity_id' => $bed->id, 'quantity' => 1], ['amenity_id' => $pillow->id, 'quantity' => 2]],
        ]]])->assertCreated();

        $this->assertCount(2, $res->json('requests'));
        $this->assertSame(['Deluxe (room 2)', 'Deluxe (room 2)'], array_column($res->json('requests'), 'room_label'));
        $rows = AmenityRequest::orderBy('id')->get();
        $this->assertSame(['pending', 'pending'], $rows->pluck('status')->all());
        $this->assertSame(['guest_request', 'guest_request'], $rows->pluck('origin')->all());
        $this->assertNull($rows[0]->room_id, 'no room is assigned before check-in');
        $this->assertSame(2, (int) $rows[0]->room_slot);
        $this->assertNotNull($rows[0]->room_line_id);
        $this->assertSame(0.0, (float) StayBill::forBooking($booking->fresh())['amenity_charge'], 'pending requests are never billed');

        foreach ($rows as $row) {
            $this->actingAs($desk)->put(route('receptionist.amenity-requests.approve', $row))->assertSessionHas('success');
        }
        $this->assertSame(300.0, (float) StayBill::forBooking($booking->fresh())['amenity_charge'], 'the paid one is billed when approved; the included one adds nothing');
        $this->assertEqualsWithDelta(4300, \App\Support\StayPayment::of($booking->fresh())['total'], 0.01);
    }

    public function test_the_front_desk_list_shows_a_request_made_before_check_in_with_its_booked_room(): void
    {
        [$user, $booking, $rt] = $this->confirmedBooking();
        $bed = $this->amenity('Extra bed', 300);
        $this->asGuest($user)->postJson($this->url($booking), ['rooms' => [['room_type_id' => $rt->id, 'room_slot' => 1, 'items' => [['amenity_id' => $bed->id, 'quantity' => 1]]]]])->assertCreated();

        $this->actingAs($this->desk())->get(route('receptionist.amenities.index'))->assertOk()
            ->assertSee('Extra bed')->assertSee('Deluxe (room 1)')->assertSee('Before check-in')->assertSee('Waiting for your decision');
    }

    public function test_a_room_that_was_not_booked_is_refused_and_an_older_app_without_rooms_gets_the_first_booked_room(): void
    {
        [$user, $booking, $rt] = $this->confirmedBooking();
        $bed = $this->amenity('Extra bed', 300);

        $this->asGuest($user)->postJson($this->url($booking), ['rooms' => [['room_type_id' => $rt->id, 'room_slot' => 3, 'items' => [['amenity_id' => $bed->id, 'quantity' => 1]]]]])->assertStatus(422);
        $this->asGuest($user)->postJson($this->url($booking), ['rooms' => [['room_type_id' => $rt->id + 99, 'room_slot' => 1, 'items' => [['amenity_id' => $bed->id, 'quantity' => 1]]]]])->assertStatus(422);
        $this->assertSame(0, AmenityRequest::count());

        // the flat form (an older app version): the first booked room
        $this->asGuest($user)->postJson($this->url($booking), ['items' => [['amenity_id' => $bed->id, 'quantity' => 1]]])->assertCreated();
        $this->assertSame('Deluxe (room 1)', AmenityRequest::first()->room_label);
    }

    // ---- check-in: the request moves to the assigned room

    public function test_at_check_in_a_request_made_earlier_carries_over_to_the_assigned_room(): void
    {
        [$user, $booking, $rt] = $this->confirmedBooking();
        $bed = $this->amenity('Extra bed', 300);
        $towel = $this->amenity('Towel', 50);
        $desk = $this->desk();
        $this->asGuest($user)->postJson($this->url($booking), ['rooms' => [
            ['room_type_id' => $rt->id, 'room_slot' => 1, 'items' => [['amenity_id' => $towel->id, 'quantity' => 1]]],
            ['room_type_id' => $rt->id, 'room_slot' => 2, 'items' => [['amenity_id' => $bed->id, 'quantity' => 1]]],
        ]])->assertCreated();
        foreach (AmenityRequest::all() as $row) {
            $this->actingAs($desk)->put(route('receptionist.amenity-requests.approve', $row))->assertSessionHas('success');
        }
        $rooms = Room::where('room_type_id', $rt->id)->orderBy('id')->get();
        $first = $rooms[2];  // the desk picks the third room first, then the first
        $second = $rooms[0];

        // bill at check-in: 4,000 rooms + 350 amenities
        $this->actingAs($desk)->postJson(route('receptionist.check-in.store', $booking), [
            'guest_first_name' => 'Ana', 'guest_last_name' => 'Cruz', 'checkin_permanent_address' => '1 Test St', 'current_address_same_as_permanent' => true,
            'checkin_contact_number' => '09171234567', 'adults' => 2, 'children' => 0,
            'room_ids' => [(string) $rt->id => [$first->id, $second->id]], 'payment_method' => 'cash', 'amount_received' => 4350,
        ])->assertOk();

        $this->assertSame(Booking::STATUS_CHECKED_IN, $booking->fresh()->booking_status);
        $byName = AmenityRequest::all()->keyBy('amenity_name');
        $this->assertSame($first->id, (int) $byName['Towel']->room_id, 'room 1 of the booking is the first room assigned');
        $this->assertSame($second->id, (int) $byName['Extra bed']->room_id, 'room 2 is the second');
        $this->assertSame(350.0, (float) StayBill::forBooking($booking->fresh())['amenity_charge'], 'the bill is unchanged by the move');

        // and from now on the guest requests for the assigned rooms, as before
        $info = $this->asGuest($user)->getJson($this->url($booking))->assertOk()->json();
        $this->assertSame('in_house', $info['stays'][0]['state']);
        $this->assertSame([], $info['stays'][0]['slots']);
        $this->assertCount(2, $info['stays'][0]['rooms']);
        $this->asGuest($user)->postJson($this->url($booking), ['rooms' => [['room_id' => $first->id, 'items' => [['amenity_id' => $towel->id, 'quantity' => 1]]]]])->assertCreated();
        $this->assertSame($first->id, (int) AmenityRequest::latest('id')->first()->room_id);
    }

    public function test_amenities_chosen_while_booking_are_not_moved_by_the_carry_over(): void
    {
        [, $booking, $rt] = $this->confirmedBooking();
        $line = $booking->roomLines()->first();
        $row = AmenityRequest::create(['booking_id' => $booking->id, 'room_type_id' => $rt->id, 'room_line_id' => $line->id, 'room_slot' => 1, 'amenity_name' => 'Extra bed',
            'category' => 'Room', 'quantity' => 1, 'charge' => 300, 'status' => 'approved', 'origin' => AmenityRequest::ORIGIN_BOOKING]);
        $room = Room::where('room_type_id', $rt->id)->first();

        $moved = \App\Support\AmenityRoomCarryOver::apply($booking, null, [(string) $rt->id => [$room->id]]);

        $this->assertSame(0, $moved);
        $this->assertNull($row->fresh()->room_id, 'an older row stays exactly as it was');
    }

    // ---- reservations

    public function test_a_reservation_cannot_request_amenities_until_it_becomes_a_booking(): void
    {
        [$user, $guest] = $this->makeGuestUser('RES' . uniqid());
        $rt = $this->makeRoomTypeWithRooms('Deluxe', 1000, 2, 3);
        $bed = $this->amenity('Extra bed', 300);
        $reservation = Reservation::create([
            'guest_id' => $guest->id, 'room_type_id' => $rt->id, 'rooms_requested' => 1, 'guest_first_name' => 'A', 'guest_last_name' => 'B',
            'check_in' => now()->addDays(5), 'check_out' => now()->addDays(6), 'adults' => 1, 'children' => 0, 'number_of_guests' => 1,
            'status' => Reservation::STATUS_AWAITING_CASH,
        ]);
        $url = "/api/guest/reservations/{$reservation->id}/additional-amenities";

        $info = $this->asGuest($user)->getJson($url)->assertOk()->json();
        $this->assertFalse($info['can_request']);
        $this->assertSame('Amenities can be requested once your reservation becomes a booking.', $info['reason']);
        $this->asGuest($user)->postJson($url, ['items' => [['amenity_id' => $bed->id, 'quantity' => 1]]])->assertStatus(422);
        $this->assertSame(0, AmenityRequest::count());
    }

    public function test_once_a_reservation_is_a_booking_the_booking_rules_apply_with_the_reservations_own_room_lines(): void
    {
        [$user, $guest] = $this->makeGuestUser('CONV' . uniqid());
        $rt = $this->makeRoomTypeWithRooms('Deluxe', 1000, 2, 3);
        $bed = $this->amenity('Extra bed', 300);
        $reservation = Reservation::create([
            'guest_id' => $guest->id, 'room_type_id' => $rt->id, 'rooms_requested' => 2, 'guest_first_name' => 'A', 'guest_last_name' => 'B',
            'check_in' => today()->toDateString(), 'check_out' => today()->addDays(2)->toDateString(), 'adults' => 1, 'children' => 0, 'number_of_guests' => 1,
            'status' => Reservation::STATUS_CONVERTED,
        ]);
        $resLine = $reservation->roomLines()->create(['room_type_id' => $rt->id, 'room_type_name' => 'Deluxe', 'quantity' => 2, 'price_per_night' => 1000, 'number_of_nights' => 2, 'subtotal' => 4000]);
        $booking = Booking::create([
            'reservation_id' => $reservation->id, 'room_type_id' => $rt->id, 'rooms_requested' => 2,
            'check_in' => today()->toDateString(), 'check_out' => today()->addDays(2)->toDateString(), 'adults' => 1, 'children' => 0, 'number_of_guests' => 1,
            'booking_status' => Booking::STATUS_ACTIVE, 'payment_method' => 'cash', 'confirmed_at' => now(), 'verified_at' => now(),
        ]);
        $booking->roomLines()->create(['room_type_id' => $rt->id, 'room_type_name' => 'Deluxe', 'quantity' => 2, 'price_per_night' => 1000, 'number_of_nights' => 2, 'subtotal' => 4000]);
        $url = "/api/guest/reservations/{$reservation->id}/additional-amenities";

        $info = $this->asGuest($user)->getJson($url)->assertOk()->json();
        $this->assertTrue($info['can_request']);
        $this->assertSame(['Deluxe (room 1)', 'Deluxe (room 2)'], array_column($info['stays'][0]['slots'], 'label'));

        $this->asGuest($user)->postJson($url, ['rooms' => [['room_type_id' => $rt->id, 'room_slot' => 2, 'items' => [['amenity_id' => $bed->id, 'quantity' => 1]]]]])->assertCreated();
        $row = AmenityRequest::first();
        $this->assertSame($reservation->id, (int) $row->reservation_id, 'a converted stay keeps its amenity rows on the reservation, as before');
        $this->assertSame($resLine->id, (int) $row->room_line_id);
        $this->assertSame('Deluxe (room 2)', $row->room_label);

        // the carry-over finds the reservation's line too
        $rooms = Room::where('room_type_id', $rt->id)->orderBy('id')->get();
        $this->assertSame(1, \App\Support\AmenityRoomCarryOver::apply($booking->fresh(), null, [(string) $rt->id => [$rooms[0]->id, $rooms[1]->id]]));
        $this->assertSame($rooms[1]->id, (int) $row->fresh()->room_id);
    }

    // ---- closed bookings, and the guest web

    public function test_cancelled_and_checked_out_bookings_cannot_request(): void
    {
        [$user, $booking, $rt] = $this->confirmedBooking();
        $bed = $this->amenity('Extra bed', 300);

        foreach ([Booking::STATUS_CANCELLED, Booking::STATUS_COMPLETED, Booking::STATUS_NO_SHOW] as $status) {
            $booking->update(['booking_status' => $status]);
            $info = $this->asGuest($user)->getJson($this->url($booking))->assertOk()->json();
            $this->assertFalse($info['can_request'], $status);
            $this->assertSame([], $info['stays'], $status);
            $this->asGuest($user)->postJson($this->url($booking), ['rooms' => [['room_type_id' => $rt->id, 'room_slot' => 1, 'items' => [['amenity_id' => $bed->id, 'quantity' => 1]]]]])->assertStatus(422);
        }
        $this->assertSame(0, AmenityRequest::count());
    }

    public function test_the_guest_web_offers_the_request_for_a_booking_before_check_in_and_during_the_stay(): void
    {
        $js = file_get_contents(public_path('js/portal/amenities.js'));

        $this->assertStringContainsString("!/ACTIVE_BOOKING|CHECKED_IN/.test(b.booking_status || '')", $js);
        $this->assertStringContainsString('Before check-in you choose the room you booked.', $js);
        $this->assertStringContainsString("{ room_type_id: +sp[1], option_key: sp[2], room_slot: +sp[3], items: items }", $js);
        $this->assertStringContainsString('{ room_id: +st.room, items: items }', $js);
        foreach (['Included with your room', 'Additional amenities'] as $needle) {
            $this->assertStringContainsString($needle, $js);
        }
    }
}
