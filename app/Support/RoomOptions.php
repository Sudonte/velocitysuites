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
        return 'r'.str_replace('.', 'p', rtrim(rtrim(number_format($rate, 2, '.', ''), '0'), '.')).'c'.$capacity;
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

    /** The option a physical room belongs to ("std" when it has the type's own price and capacity). */
    public static function keyForRoom(Room $room): string
    {
        $type = $room->roomType;
        $rate = (float) $room->room_rate;
        $cap = (int) $room->guest_capacity;

        return abs($rate - (float) $type->rate) < 0.005 && $cap === (int) $type->capacity ? self::STANDARD : self::keyFor($rate, $cap);
    }

    /** True for the standard option (a missing key is the standard option: every line stored before options existed). */
    public static function isStandard(?string $key): bool
    {
        return $key === null || $key === '' || $key === self::STANDARD;
    }

    /**
     * The room type as ONE option sees it: a copy whose rate and capacity are the option's own (and `option_key` set), so every price and
     * capacity calculation that reads $type->rate / $type->capacity works on the option without knowing about options. Never saved.
     *
     * @return RoomType|null null when the type has no such option with rooms in inventory
     */
    public static function typeFor(RoomType $type, ?string $key): ?RoomType
    {
        if (self::isStandard($key)) {
            $copy = clone $type;
            $copy->setAttribute('option_key', self::STANDARD);

            return $copy;
        }
        $group = self::forType($type)->first(fn ($g) => $g['key'] === $key && $g['count'] > 0);
        if (! $group) {
            return null;
        }
        $copy = clone $type;
        $copy->setAttribute('rate', $group['rate']);
        $copy->setAttribute('capacity', $group['capacity']);
        $copy->setAttribute('min_capacity', min(\App\Support\GuestCapacity::minOf($type), $group['capacity']));
        $copy->setAttribute('option_key', $key);

        return $copy;
    }

    /** The same copy rebuilt from a SAVED line (its own stored price and capacity, even if the rooms changed since). */
    public static function typeForLine(RoomType $type, object $line): RoomType
    {
        $key = $line->option_key ?? null;
        if (self::isStandard($key)) {
            return self::typeFor($type, null);
        }
        $copy = clone $type;
        $copy->setAttribute('rate', (float) $line->price_per_night);
        $copy->setAttribute('capacity', (int) ($line->capacity ?: $type->capacity));
        $copy->setAttribute('min_capacity', min(\App\Support\GuestCapacity::minOf($type), (int) $copy->capacity));
        $copy->setAttribute('option_key', $key);

        return $copy;
    }

    /** What a booked line is called on bills and receipts: the type's name, plus the capacity for another option ("Deluxe (up to 4 guests)"). */
    public static function lineName(RoomType $type): string
    {
        return self::isStandard(self::keyOf($type)) ? (string) $type->name : $type->name.' (up to '.(int) $type->capacity.' guests)';
    }

    /** The option key of a type copy made by typeFor(). */
    public static function keyOf(RoomType $type): string
    {
        return (string) ($type->getAttribute('option_key') ?: self::STANDARD);
    }

    /** Rooms (not archived) of the type that belong to the option; $withMaintenance false leaves out the ones under maintenance. */
    public static function rooms(RoomType $type, ?string $key, bool $withMaintenance = true): Collection
    {
        $std = self::isStandard($key);

        return Room::notArchived()->where('room_type_id', $type->id)
            ->when(! $withMaintenance, fn ($q) => $q->where('status', '!=', 'maintenance'))
            ->get()
            ->filter(function (Room $room) use ($type, $key, $std) {
                $room->setRelation('roomType', $type);
                $standard = abs((float) $room->room_rate - (float) $type->getOriginal('rate', $type->rate)) < 0.005
                    && (int) $room->guest_capacity === (int) $type->getOriginal('capacity', $type->capacity);

                return $std ? $standard : (! $standard && self::keyFor((float) $room->room_rate, (int) $room->guest_capacity) === $key);
            })->values();
    }

    /** The options other than the standard one: the "Other Deluxe rooms" list (empty when every room is standard). */
    public static function others(RoomType $type, ?Collection $rooms = null): Collection
    {
        return self::forType($type, $rooms)->reject(fn ($g) => $g['is_standard'])->filter(fn ($g) => $g['count'] > 0)->values();
    }
}
