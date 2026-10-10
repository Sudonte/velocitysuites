<?php

namespace App\Support;

use App\Models\Booking;
use App\Models\Reservation;
use App\Models\RoomType;

/**
 * Guest capacity is defined per room type as a range (min_capacity..capacity).
 * A stay's maximum guest count (adults + children) is the sum of each selected
 * type's capacity times its quantity. The minimum only counts types whose
 * min_capacity was explicitly raised above 1, so the default (1) keeps the
 * long-standing behavior of e.g. one guest booking several rooms. One rule for
 * every web and API entry point that sets guest counts.
 */
class GuestCapacity
{
    /**
     * @param iterable<array{room_type: ?RoomType, quantity: int}> $lines
     * @return array{min: int, max: int}
     */
    public static function range(iterable $lines): array
    {
        $min = 0;
        $max = 0;
        foreach ($lines as $line) {
            $type = $line['room_type'] ?? null;
            $qty = max(1, (int) ($line['quantity'] ?? 1));
            if (! $type) {
                continue;
            }
            $typeMin = self::minOf($type);
            if ($typeMin > 1) {
                $min += $typeMin * $qty;
            }
            $max += (int) $type->capacity * $qty;
        }

        return ['min' => max(1, $min), 'max' => $max];
    }

    /** Guest-facing error, or null when $totalGuests fits the range. */
    public static function error(iterable $lines, int $totalGuests): ?string
    {
        ['min' => $min, 'max' => $max] = self::range($lines);

        if ($totalGuests > $max) {
            return "Adults and children combined ({$totalGuests}) exceed the total capacity ({$max}) of the selected room(s).";
        }
        if ($totalGuests < $min) {
            return "The selected room(s) require at least {$min} guest(s); you entered {$totalGuests}.";
        }

        return null;
    }

    public static function minOf(RoomType $type): int
    {
        return max(1, (int) ($type->min_capacity ?? 1));
    }

    public static function linesForBooking(Booking $booking): array
    {
        return self::linesFrom($booking->roomLines()->get(), $booking->room_type_id, $booking->rooms_requested);
    }

    public static function linesForReservation(Reservation $reservation): array
    {
        return self::linesFrom($reservation->roomLines()->get(), $reservation->room_type_id, $reservation->rooms_requested);
    }

    private static function linesFrom($lineModels, ?int $legacyTypeId, ?int $legacyQty): array
    {
        if ($lineModels->isNotEmpty()) {
            $types = RoomType::whereIn('id', $lineModels->pluck('room_type_id'))->get()->keyBy('id');

            return $lineModels->map(fn ($l) => [
                'room_type' => $types->get($l->room_type_id),
                'quantity' => (int) $l->quantity,
            ])->all();
        }

        return [['room_type' => $legacyTypeId ? RoomType::find($legacyTypeId) : null, 'quantity' => max(1, (int) $legacyQty)]];
    }
}
