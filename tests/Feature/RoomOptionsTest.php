<?php

namespace Tests\Feature;

use App\Models\Room;
use App\Support\RoomOptions;

/** Part 5 foundation: rooms group by (price per night, capacity); the type's own values are the standard option, the rest are "other" options. */
class RoomOptionsTest extends ApiFlowTestCase
{
    public function test_rooms_group_by_price_and_capacity_with_the_type_values_as_the_standard_option(): void
    {
        $deluxe = $this->makeRoomTypeWithRooms('Deluxe', 3500, 2, 3);           // 3 standard rooms: 3,500 / 2 guests
        Room::create(['room_number' => 'D-4', 'room_name' => 'Deluxe 4', 'room_type_id' => $deluxe->id, 'room_capacity' => 4, 'rate_override' => 4200, 'status' => 'available']);
        Room::create(['room_number' => 'D-5', 'room_name' => 'Deluxe 5', 'room_type_id' => $deluxe->id, 'room_capacity' => 4, 'rate_override' => 4200, 'status' => 'available']);
        Room::create(['room_number' => 'D-6', 'room_name' => 'Deluxe 6', 'room_type_id' => $deluxe->id, 'room_capacity' => 2, 'rate_override' => 3900, 'status' => 'available']);
        Room::create(['room_number' => 'D-7', 'room_name' => 'Deluxe 7', 'room_type_id' => $deluxe->id, 'room_capacity' => 4, 'status' => 'available']);   // own capacity, the type's price
        $archived = Room::create(['room_number' => 'D-8', 'room_name' => 'Deluxe 8', 'room_type_id' => $deluxe->id, 'room_capacity' => 6, 'rate_override' => 9000, 'status' => 'available']);
        $archived->forceFill(['archived_at' => now()])->save();

        $options = RoomOptions::forType($deluxe);
        $this->assertSame(['std', 'r3500c4', 'r3900c2', 'r4200c4'], $options->pluck('key')->all(), 'standard first, then the others by price');
        $std = $options->first();
        $this->assertTrue($std['is_standard']);
        $this->assertSame(3, $std['count']);
        $this->assertEquals(3500.0, $std['rate']);
        $this->assertSame(2, $std['capacity']);

        $others = RoomOptions::others($deluxe);
        $this->assertCount(3, $others, 'the archived room is not an option');
        $byKey = $others->keyBy('key');
        $this->assertSame(2, $byKey['r4200c4']['count']);
        $this->assertSame(4, $byKey['r4200c4']['capacity']);
        $this->assertSame(1, $byKey['r3900c2']['count']);
        $this->assertSame(1, $byKey['r3500c4']['count'], 'a different capacity at the type price is its own option');
        $this->assertFalse($others->contains(fn ($g) => $g['is_standard']));
    }

    public function test_a_type_whose_rooms_are_all_standard_has_no_other_options(): void
    {
        $standard = $this->makeRoomTypeWithRooms('Standard', 1500, 2, 4);
        $this->assertCount(0, RoomOptions::others($standard));
        $this->assertCount(1, RoomOptions::forType($standard));
        $this->assertSame('r4200c4', RoomOptions::keyFor(4200.0, 4));
        $this->assertSame('r1500.5c2', RoomOptions::keyFor(1500.5, 2));
    }
}
