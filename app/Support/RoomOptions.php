<?php

namespace App\Support;

use App\Models\Room;
use App\Models\RoomType;
use Illuminate\Support\Collection;

/**
 * PART 5 FOUNDATION (branch part5-room-options, NOT deployed): the options a room type offers.
 *
 * A room already carries its own price (rooms.rate_override) and capacity (rooms.room_capacity), set on the admin Add Rooms / Edit Room
 * forms, so no admin field or migration is needed to define an option. Rooms are grouped by (price per night, capacity): the combination
 * the room type itself has is the STANDARD option; every other combination is an "other" option ("Other Deluxe rooms").
 * An option's key is stable and derived from its values, e.g. "std" or "r4200c4", so a booked line can name it without a new table.
 */
class RoomOptions
{
    public const STANDARD = 'std';

    public static function keyFor(float $rate, int $capacity): string
    {
        return 'r'.rtrim(rtrim(number_format($rate, 2, '.', ''), '0'), '.').'c'.$capacity;
    }

    /**
     * @param  Collection<int,Room>|null  $rooms  the type's rooms (default: its rooms in inventory, archived ones excluded)
     * @return Collection<int,array{key:string,rate:float,capacity:int,is_standard:bool,room_ids:array<int,int>,count:int}>  standard first, then others by price
     */
    public static function forType(RoomType $type, ?Collection $rooms = null): Collection
    {
        $rooms ??= Room::notArchived()->where('room_type_id', $type->id)->with('roomType')->get();
        $stdRate = (float) $type->rate;
        $stdCap = (int) $type->capacity;

        $groups = [];
        foreach ($rooms as $room) {
            $room->setRelation('roomType', $type);
            $rate = (float) $room->room_rate;
            $cap = (int) $room->guest_capacity;
            $standard = abs($rate - $stdRate) < 0.005 && $cap === $stdCap;
            $key = $standard ? self::STANDARD : self::keyFor($rate, $cap);
            $groups[$key] ??= ['key' => $key, 'rate' => $rate, 'capacity' => $cap, 'is_standard' => $standard, 'room_ids' => [], 'count' => 0];
            $groups[$key]['room_ids'][] = (int) $room->id;
            $groups[$key]['count']++;
        }
        // the standard option is always listed first, even when no room currently matches it
        $groups[self::STANDARD] ??= ['key' => self::STANDARD, 'rate' => $stdRate, 'capacity' => $stdCap, 'is_standard' => true, 'room_ids' => [], 'count' => 0];

        return collect($groups)->sortBy(fn ($g) => [$g['is_standard'] ? 0 : 1, $g['rate'], $g['capacity']])->values();
    }

    /** The options other than the standard one: the "Other Deluxe rooms" list (empty when every room is standard). */
    public static function others(RoomType $type, ?Collection $rooms = null): Collection
    {
        return self::forType($type, $rooms)->reject(fn ($g) => $g['is_standard'])->filter(fn ($g) => $g['count'] > 0)->values();
    }
}
