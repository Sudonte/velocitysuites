<?php

namespace Tests\Feature;

use App\Models\Amenity;
use App\Models\AmenityRequest;
use App\Models\Booking;
use App\Models\Room;
use App\Models\RoomType;
use App\Models\User;
use App\Support\StayBill;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;

/**
 * Panel revision: during the check-in process - before the guest is checked in - the front desk can add amenities to a confirmed
 * booking from the Expected Check-ins tab. They are approved at once (the desk is adding them), are on the Check In panel's bill,
 * and move to the room assigned at check-in.
 */
class DeskAmenitiesBeforeCheckInTest extends ApiFlowTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        if (! Schema::hasTable('promotion_amenity')) {
            Schema::create('promotion_amenity', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('promotion_id');
                $table->unsignedBigInteger('amenity_id');
                $table->unsignedInteger('quantity')->default(1);
                $table->timestamps();
            });
        }
        Storage::fake('local');
    }

    private function desk(): User
    {
        return User::firstOrCreate(['email' => 'desk-before@hotel.com'], [
            'first_name' => 'Rita', 'last_name' => 'Desk', 'password' => bcrypt('x'), 'role' => 'receptionist', 'status' => 'active', 'email_verified_at' => now(),
        ]);
    }

    private function amenity(string $name, float $charge, int $quantity = 20): Amenity
    {
        return Amenity::create(['amenity_name' => $name, 'description' => 'd', 'category' => 'Room', 'quantity' => $quantity, 'charge' => $charge, 'status' => 'active']);
    }

    /** A confirmed, verified booking of 2 Deluxe rooms arriving today, 2 nights x 1,000 x 2 = 4,000, not checked in. */
    private function confirmedBooking(array $extra = []): array
    {
        [, $guest] = $this->makeGuestUser('DBC' . uniqid());
        $rt = RoomType::first() ?? $this->makeRoomTypeWithRooms('Deluxe', 1000, 2, 3);
        $booking = Booking::create(array_merge([
            'guest_id' => $guest->id, 'room_type_id' => $rt->id, 'rooms_requested' => 2, 'guest_first_name' => 'Ana', 'guest_last_name' => 'Cruz',
            'check_in' => today()->toDateString(), 'check_out' => today()->addDays(2)->toDateString(),
            'adults' => 2, 'children' => 0, 'number_of_guests' => 2, 'payment_method' => 'cash',
            'booking_status' => Booking::STATUS_ACTIVE, 'confirmed_at' => now(), 'verified_at' => now(),
        ], $extra));
        $booking->roomLines()->create(['room_type_id' => $rt->id, 'room_type_name' => 'Deluxe', 'quantity' => 2, 'price_per_night' => 1000, 'number_of_nights' => 2, 'subtotal' => 4000]);

        return [$booking->fresh(), $rt];
    }

    public function test_the_expected_check_ins_tab_offers_add_amenity_and_the_picker_lists_the_booked_rooms(): void
    {
        [$booking] = $this->confirmedBooking();
        $this->amenity('Extra bed', 300);
        $desk = $this->desk();

        $this->actingAs($desk)->get(route('receptionist.check-in.index'))->assertOk()
            ->assertSee('data-booking-id="' . $booking->id . '"', false)->assertSee('Add Amenity');

        $this->actingAs($desk)->get(route('receptionist.amenities.create', $booking))->assertOk()
            ->assertSee('Not checked in yet')->assertSee('Which booked room is it for?')
            ->assertSee('Deluxe (room 1)')->assertSee('Deluxe (room 2)')->assertSee('Extra bed');
    }

    public function test_added_before_check_in_it_is_approved_on_the_check_in_bill_and_moves_to_the_assigned_room(): void
    {
        [$booking, $rt] = $this->confirmedBooking();
        $bed = $this->amenity('Extra bed', 300);
        $desk = $this->desk();

        $this->actingAs($desk)->postJson(route('receptionist.amenities.store', $booking), [
            'items' => [['amenity_id' => $bed->id, 'quantity' => 2]],
            'room_slot' => $rt->id . ':std#2',
        ])->assertOk()->assertJson(['message' => '1 amenity request added.']);

        $row = AmenityRequest::sole();
        $this->assertSame('approved', $row->status, 'the desk added it - nothing to decide');
        $this->assertSame(AmenityRequest::ORIGIN_STAFF, $row->origin);
        $this->assertSame($desk->id, (int) $row->decided_by);
        $this->assertNull($row->room_id, 'no room is assigned yet');
        $this->assertSame('Deluxe (room 2)', $row->room_label);
        $this->assertSame(600.0, (float) StayBill::forBooking($booking->fresh())['amenity_charge']);
        $this->assertSame(0, AmenityRequest::pendingGuestRequests()->count(), 'nothing waits on the Amenity Requests page');

        // the Check In panel's bill already includes it
        $this->actingAs($desk)->get(route('receptionist.check-in.panel', $booking))->assertOk()->assertSee('Extra bed');

        $rooms = Room::where('room_type_id', $rt->id)->orderBy('id')->get();
        $this->actingAs($desk)->postJson(route('receptionist.check-in.store', $booking), [
            'guest_first_name' => 'Ana', 'guest_last_name' => 'Cruz', 'checkin_permanent_address' => '1 Test St', 'current_address_same_as_permanent' => true,
            'checkin_contact_number' => '09171234567', 'adults' => 2, 'children' => 0,
            'room_ids' => [(string) $rt->id => [$rooms[0]->id, $rooms[2]->id]], 'payment_method' => 'cash', 'amount_received' => 4600,
        ])->assertOk();

        $this->assertSame(Booking::STATUS_CHECKED_IN, $booking->fresh()->booking_status);
        $this->assertSame($rooms[2]->id, (int) $row->fresh()->room_id, 'booked room 2 is the second room assigned');
        $this->assertSame(600.0, (float) StayBill::forBooking($booking->fresh())['amenity_charge']);
    }

    public function test_without_a_room_choice_it_is_for_the_first_booked_room(): void
    {
        [$booking] = $this->confirmedBooking();
        $bed = $this->amenity('Extra bed', 300);

        $this->actingAs($this->desk())->postJson(route('receptionist.amenities.store', $booking), ['items' => [['amenity_id' => $bed->id, 'quantity' => 1]]])->assertOk();

        $this->assertSame('Deluxe (room 1)', AmenityRequest::sole()->room_label);
    }

    public function test_stock_free_amenities_and_unverified_or_finished_bookings_are_refused(): void
    {
        [$booking, $rt] = $this->confirmedBooking();
        $desk = $this->desk();
        $scarce = $this->amenity('Crib', 200, 1);
        $free = $this->amenity('Wi-Fi', 0);

        $this->actingAs($desk)->postJson(route('receptionist.amenities.store', $booking), ['items' => [['amenity_id' => $scarce->id, 'quantity' => 2]]])
            ->assertStatus(422)->assertJson(['message' => 'Only 1 of "Crib" left - please lower the quantity.']);
        $this->actingAs($desk)->postJson(route('receptionist.amenities.store', $booking), ['items' => [['amenity_id' => $free->id, 'quantity' => 1]]])
            ->assertStatus(422)->assertJson(['message' => 'Only Paid/Additional amenities can be requested.']);
        $this->actingAs($desk)->postJson(route('receptionist.amenities.store', $booking), ['items' => [['amenity_id' => $scarce->id, 'quantity' => 1]], 'room_slot' => ($rt->id + 99) . ':std#1'])
            ->assertStatus(422);
        $this->assertSame(0, AmenityRequest::count());

        [$unverified] = $this->confirmedBooking(['verified_at' => null]);
        [$cancelled] = $this->confirmedBooking(['booking_status' => Booking::STATUS_CANCELLED]);
        foreach ([$unverified, $cancelled] as $b) {
            $this->actingAs($desk)->get(route('receptionist.amenities.create', $b))->assertNotFound();
            $this->actingAs($desk)->postJson(route('receptionist.amenities.store', $b), ['items' => [['amenity_id' => $scarce->id, 'quantity' => 1]]])->assertStatus(422);
        }
        $this->assertSame(0, AmenityRequest::count());
    }
}
