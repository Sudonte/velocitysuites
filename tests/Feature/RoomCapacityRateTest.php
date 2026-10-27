<?php

namespace Tests\Feature;

use App\Models\Booking;
use App\Models\Room;
use App\Models\User;

/**
 * A room is known by its number (no separate name) and shows its type's description. Add Rooms and Edit Room set
 * each room's own guest capacity and nightly rate (blank = its type's), and the desk checks guests against the rooms
 * actually picked.
 */
class RoomCapacityRateTest extends ApiFlowTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        // The room type pages also read these; the shared API schema doesn't carry them.
        \Illuminate\Support\Facades\Schema::table('room_types', function ($table) {
            foreach (['bed_type', 'description', 'number_format', 'image'] as $col) {
                if (! \Illuminate\Support\Facades\Schema::hasColumn('room_types', $col)) {
                    $table->string($col)->nullable();
                }
            }
            if (! \Illuminate\Support\Facades\Schema::hasColumn('room_types', 'image_changed_at')) {
                $table->timestamp('image_changed_at')->nullable();
            }
        });
    }

    private function admin(): User
    {
        return User::create([
            'first_name' => 'Ada', 'last_name' => 'Admin', 'email' => 'admin-rooms@example.test',
            'password' => bcrypt('x'), 'role' => 'admin', 'status' => 'active', 'email_verified_at' => now(),
        ]);
    }

    public function test_add_rooms_sets_capacity_and_rate_for_the_batch_and_names_rooms_by_number(): void
    {
        $rt = $this->makeRoomTypeWithRooms('Romero', 200, 2, 0);
        $rt->update(['number_format' => 'Z-##']);
        $admin = $this->admin();

        $page = $this->actingAs($admin)->get(route('admin.room-types.show', $rt))->assertOk();
        $page->assertSee('name="room_capacity"', false)->assertSee('name="rate_override"', false)
            ->assertDontSee('value="Romero Room"', false)->assertDontSee('No description set on the room type yet.');

        $this->actingAs($admin)->post(route('admin.room-types.rooms.store', $rt), ['quantity' => 2, 'status' => 'available', 'room_capacity' => 7, 'rate_override' => 300])
            ->assertSessionHasNoErrors();
        $this->actingAs($admin)->post(route('admin.room-types.rooms.store', $rt), ['quantity' => 1, 'status' => 'available', 'room_capacity' => '', 'rate_override' => ''])
            ->assertSessionHasNoErrors();

        $rooms = Room::where('room_type_id', $rt->id)->orderBy('id')->get();
        $this->assertCount(3, $rooms);
        $this->assertSame([7, 7, 2], $rooms->map->guest_capacity->all());
        $this->assertSame([300.0, 300.0, 200.0], $rooms->map(fn ($r) => (float) $r->room_rate)->all());
        $this->assertNull($rooms[2]->rate_override, 'blank = the type\'s base rate, not a copy of it');
        $this->assertSame('Room ' . $rooms[0]->room_number, $rooms[0]->room_name);
        $this->assertSame('Up to 7 guests', $rooms[0]->capacity_label);
    }

    public function test_edit_room_has_no_name_or_description_and_saves_capacity_and_rate(): void
    {
        $rt = $this->makeRoomTypeWithRooms('Romero', 200, 2, 1);
        $room = $rt->rooms()->first();
        $admin = $this->admin();

        $this->actingAs($admin)->get(route('admin.rooms.edit', $room))->assertOk()
            ->assertDontSee('name="room_name"', false)->assertDontSee('>Description<', false)->assertSee('name="room_capacity"', false);

        $this->actingAs($admin)->put(route('admin.rooms.update', $room), [
            'room_number' => 'Z-02', 'room_type_id' => $rt->id, 'room_capacity' => 7, 'rate_override' => 300,
        ])->assertSessionHasNoErrors();

        $room->refresh();
        $this->assertSame('Room Z-02', $room->room_name);
        $this->assertSame(7, $room->guest_capacity);
        $this->assertEquals(300.0, (float) $room->room_rate);
        $this->assertTrue($room->has_capacity_override);
    }

    public function test_changing_the_types_capacity_keeps_a_rooms_own_capacity(): void
    {
        $rt = $this->makeRoomTypeWithRooms('Romero', 200, 2, 2);
        [$follows, $own] = $rt->rooms()->orderBy('id')->get()->all();
        $own->update(['room_capacity' => 7]);
        $rt->update(['number_format' => 'Z-##', 'min_capacity' => 1]);

        $this->actingAs($this->admin())->put(route('admin.room-types.update', $rt), [
            'name' => 'Romero', 'rate' => 200, 'capacity' => 3, 'min_capacity' => 1, 'number_format' => 'Z-##', 'status' => 'active',
        ])->assertSessionHasNoErrors();

        $this->assertSame(3, $follows->fresh()->guest_capacity, 'followed the type');
        $this->assertSame(7, $own->fresh()->guest_capacity, 'its own capacity is kept');
    }

    public function test_check_in_counts_guests_against_the_picked_rooms_own_capacity(): void
    {
        $rt = $this->makeRoomTypeWithRooms('Romero', 200, 2, 2);
        [$small, $big] = $rt->rooms()->orderBy('id')->get()->all();
        $big->update(['room_capacity' => 7]);
        $booking = Booking::create([
            'room_type_id' => $rt->id, 'rooms_requested' => 1, 'guest_first_name' => 'Ana', 'guest_last_name' => 'Cruz',
            'check_in' => today()->toDateString(), 'check_out' => today()->addDay()->toDateString(),
            'adults' => 5, 'children' => 0, 'number_of_guests' => 5,
            'booking_status' => Booking::STATUS_ACTIVE, 'confirmed_at' => now(), 'verified_at' => now(),
        ]);
        $desk = User::create(['first_name' => 'Rita', 'last_name' => 'Desk', 'email' => 'desk-cap@example.test',
            'password' => bcrypt('x'), 'role' => 'receptionist', 'status' => 'active', 'email_verified_at' => now()]);
        $payload = fn ($room) => [
            'guest_first_name' => 'Ana', 'guest_last_name' => 'Cruz', 'checkin_permanent_address' => '1 Test St',
            'current_address_same_as_permanent' => true, 'checkin_contact_number' => '09171234567', 'adults' => 5, 'children' => 0,
            'room_ids' => [(string) $rt->id => [$room->id]], 'payment_method' => 'cash', 'amount_received' => 200,
        ];

        // 5 guests don't fit the type's 2-guest room...
        $this->actingAs($desk)->postJson(route('receptionist.check-in.store', $booking), $payload($small))->assertStatus(422)->assertJsonValidationErrors('adults');
        // ...but they fit the room that holds 7.
        $this->actingAs($desk)->postJson(route('receptionist.check-in.store', $booking), $payload($big))->assertOk();
        $this->assertSame(Booking::STATUS_CHECKED_IN, $booking->fresh()->booking_status);
    }
}
