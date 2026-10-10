<?php

namespace Tests\Feature;

use App\Models\Booking;
use App\Models\Room;
use App\Models\User;
use App\Services\RoomAvailabilityService;

/**
 * Archived rooms keep their history but leave inventory: never counted,
 * never assignable. Archiving is refused while the room is occupied or
 * needed by confirmed bookings of its type.
 */
class RoomArchiveTest extends ApiFlowTestCase
{
    private function admin(): User
    {
        return User::create([
            'first_name' => 'Ada', 'last_name' => 'Admin', 'email' => 'admin-archive@example.test',
            'password' => bcrypt('x'), 'role' => 'admin', 'status' => 'active', 'email_verified_at' => now(),
        ]);
    }

    private function confirmedBooking(int $roomTypeId, int $rooms = 1, string $status = Booking::STATUS_ACTIVE): Booking
    {
        return Booking::create([
            'room_type_id' => $roomTypeId, 'rooms_requested' => $rooms,
            'check_in' => now()->addDays(5)->startOfDay(), 'check_out' => now()->addDays(7)->startOfDay(),
            'adults' => 1, 'children' => 0, 'number_of_guests' => 1,
            'booking_status' => $status, 'guest_first_name' => 'G', 'guest_last_name' => 'H',
        ]);
    }

    public function test_archived_room_leaves_inventory_and_can_be_restored(): void
    {
        $rt = $this->makeRoomTypeWithRooms('Deluxe', 1000, 2, 3);
        $room = Room::where('room_type_id', $rt->id)->first();
        $availability = app(RoomAvailabilityService::class);
        $in = now()->addDays(10);
        $out = now()->addDays(11);

        $this->actingAs($this->admin())->put(route('admin.rooms.archive', $room))->assertSessionHas('success');

        $this->assertNotNull($room->fresh()->archived_at);
        $this->assertSame('archived', $room->fresh()->effective_status);
        $this->assertSame(2, $availability->totalInventory($rt));
        $this->assertSame(2, $availability->availableCount($rt, $in, $out));
        $booking = $this->confirmedBooking($rt->id);
        $this->assertNotContains($room->id, $availability->assignableRoomsOfType($rt->id, $booking)->pluck('id')->all());

        $this->put(route('admin.rooms.restore', $room))->assertSessionHas('success');
        $this->assertNull($room->fresh()->archived_at);
        $this->assertSame(3, $availability->totalInventory($rt));
    }

    public function test_archive_is_refused_when_confirmed_bookings_need_the_room(): void
    {
        $rt = $this->makeRoomTypeWithRooms('Suite', 2000, 2, 2);
        $this->confirmedBooking($rt->id, 2);
        $room = Room::where('room_type_id', $rt->id)->first();

        $this->actingAs($this->admin())->put(route('admin.rooms.archive', $room))->assertSessionHas('error');

        $this->assertNull($room->fresh()->archived_at);
    }

    public function test_archive_is_refused_while_a_guest_is_checked_in(): void
    {
        $rt = $this->makeRoomTypeWithRooms('Standard', 800, 1, 3);
        $room = Room::where('room_type_id', $rt->id)->first();
        $booking = $this->confirmedBooking($rt->id, 1, Booking::STATUS_CHECKED_IN);
        $booking->rooms()->attach($room->id);

        $this->actingAs($this->admin())->put(route('admin.rooms.archive', $room))->assertSessionHas('error');

        $this->assertNull($room->fresh()->archived_at);
    }

    public function test_only_admins_can_archive(): void
    {
        $rt = $this->makeRoomTypeWithRooms('Executive', 1500, 2, 2);
        $room = Room::where('room_type_id', $rt->id)->first();
        $receptionist = User::create([
            'first_name' => 'Rex', 'last_name' => 'Desk', 'email' => 'desk-archive@example.test',
            'password' => bcrypt('x'), 'role' => 'receptionist', 'status' => 'active', 'email_verified_at' => now(),
        ]);

        $this->actingAs($receptionist)->put(route('admin.rooms.archive', $room));

        $this->assertNull($room->fresh()->archived_at);
    }
}
