<?php

namespace App\Support;

use App\Models\AmenityRequest;
use App\Models\Booking;
use App\Models\Stay;
use App\Services\RoomAvailabilityService;
use Illuminate\Support\Facades\DB;

/**
 * A guest may request amenities before check-in, for a room that was BOOKED ("Deluxe (room 1)": a room line and a slot). When the
 * front desk assigns the stay's rooms at check-in, each of those requests moves to the room that took its place: slot 1 of a line
 * is the first room picked for that line, slot 2 the second. Only the guest's own requests are touched, and only those that have
 * no room yet - amenities chosen while booking, and every older row, stay exactly as they are.
 */
final class AmenityRoomCarryOver
{
    /**
     * @param  array<string, array<int, int|string>>  $roomIdsByLine  the rooms picked per room line key ('12' or '12:r4200c4'), in the order picked
     * @return int how many requests were given their room
     */
    public static function apply(Booking $booking, ?Stay $stay, array $roomIdsByLine): int
    {
        $stayId = $stay === null || $stay->isVirtual() ? $booking->stayList()->first()?->id : $stay->id;
        $single = $booking->stayCount() === 1;

        $rows = AmenityRequest::query()
            ->where($booking->reservation_id ? 'reservation_id' : 'booking_id', $booking->reservation_id ?? $booking->id)
            ->where('origin', AmenityRequest::ORIGIN_GUEST_REQUEST)
            ->whereNull('room_id')->whereNotNull('room_line_id')->whereNotNull('room_slot')
            ->where(fn ($q) => $single ? $q : $q->where('stay_id', $stayId))
            ->get();

        $moved = 0;
        foreach ($rows as $row) {
            $line = DB::table($row->reservation_id ? 'reservation_room_lines' : 'booking_room_lines')->where('id', $row->room_line_id)->first(['room_type_id', 'option_key']);
            if (! $line) {
                continue;
            }
            $picked = array_values($roomIdsByLine[RoomAvailabilityService::optionLineKey((int) $line->room_type_id, $line->option_key)] ?? []);
            $roomId = $picked[(int) $row->room_slot - 1] ?? null;
            if ($roomId) {
                $row->update(['room_id' => (int) $roomId]);
                $moved++;
            }
        }

        return $moved;
    }
}
