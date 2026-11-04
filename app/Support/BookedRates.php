<?php

namespace App\Support;

use App\Models\Booking;
use App\Models\Room;
use App\Models\Stay;
use Illuminate\Support\Collection;

/**
 * The nightly price each ASSIGNED room is billed at: the price the guest BOOKED (the matching room line's frozen price_per_night), never
 * the room's or the room type's current rate. Without this, assigning a room at check-in - or the admin changing a price after the booking was
 * made - would silently change what the guest pays.
 *
 * Rooms are matched to the stay's room lines by room type and option (a room with its own price or capacity is its own option); a room that
 * matches no line keeps its own rate (nothing was booked for it). A COMPLETED booking is a settled fact: its bill is left exactly as it was
 * settled, so no rates are returned for it.
 */
final class BookedRates
{
    /**
     * @param  Collection<int,Room>  $rooms  the rooms assigned to the stay
     * @return array<int,float> room id => booked nightly rate (only for rooms that matched a booked line)
     */
    public static function forStay(Booking $booking, Stay $stay, Collection $rooms, ?int $firstStayId): array
    {
        if ($booking->booking_status === Booking::STATUS_COMPLETED || $rooms->isEmpty()) {
            return [];
        }
        // read once per booking object (never put on the model's relations: a loaded roomLines relation would leak into its JSON)
        static $memo = null;
        $memo ??= new \WeakMap();
        $all = $booking->relationLoaded('roomLines') ? $booking->getRelation('roomLines') : ($memo[$booking] ??= $booking->roomLines()->get());
        $lines = $all
            ->filter(fn ($l) => ($l->stay_id ?? $firstStayId) === $stay->id)
            ->map(fn ($l) => ['type' => (int) $l->room_type_id, 'option' => RoomOptions::isStandard($l->option_key ?? null) ? RoomOptions::STANDARD : (string) $l->option_key,
                'rate' => (float) $l->price_per_night, 'left' => max(1, (int) $l->quantity)])
            ->values()->all();
        if ($lines === [] && $booking->stayCount() === 1 && $booking->room_type_id) {
            // from before room lines existed: the one room type at its rate, as the unassigned bill already reads it
            $lines = [['type' => (int) $booking->room_type_id, 'option' => RoomOptions::STANDARD, 'rate' => (float) ($booking->roomType->rate ?? 0), 'left' => max(1, (int) $booking->rooms_requested)]];
        }

        $rates = [];
        foreach ($rooms->sortBy('id') as $room) {
            $key = $room->roomType ? RoomOptions::keyForRoom($room) : RoomOptions::STANDARD;
            $pick = null;
            foreach ([true, false] as $sameOption) {
                foreach ($lines as $i => $line) {
                    if ($line['left'] > 0 && $line['type'] === (int) $room->room_type_id && (! $sameOption || $line['option'] === $key)) {
                        $pick = $i;
                        break 2;
                    }
                }
            }
            if ($pick !== null) {
                $lines[$pick]['left']--;
                $rates[(int) $room->id] = $lines[$pick]['rate'];
            }
        }

        return $rates;
    }
}
