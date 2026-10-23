<?php

namespace App\Support;

use App\Models\Booking;
use App\Models\BookingRoomLine;
use App\Models\Reservation;
use App\Models\ReservationRoomLine;
use Illuminate\Support\Facades\DB;

/**
 * Which booked room an amenity row is for. A row carries `room_line_id` (a room line of its own transaction: booking_room_lines for a
 * booking's row, reservation_room_lines when the row has a reservation_id) and `room_slot` (which room of that line, 1..quantity). Both
 * NULL = booked for the whole stay, which is what every older row is.
 */
final class RoomLineRef
{
    /** @var array<string,?array{type:string,quantity:int,type_id:int}> */
    private static array $lines = [];

    /**
     * The line and slot an incoming amenity entry names ('room_type_id' + 'room_slot'), checked against the stay's own rooms.
     *
     * @return array{room_line_id: ?int, room_slot: ?int}
     */
    public static function resolve(Booking|Reservation $transaction, ?int $stayId, ?int $roomTypeId, ?int $slot): array
    {
        $none = ['room_line_id' => null, 'room_slot' => null];
        if (! $roomTypeId) {
            return $none;
        }
        $query = $transaction instanceof Booking ? BookingRoomLine::query() : ReservationRoomLine::query();
        $query->where($transaction instanceof Booking ? 'booking_id' : 'reservation_id', $transaction->id)->where('room_type_id', $roomTypeId);
        if ($stayId) {
            $query->where('stay_id', $stayId);
        }
        $line = $query->orderBy('id')->first();
        if (! $line) {
            return $none;
        }
        $slot = $slot && $slot >= 1 && $slot <= (int) $line->quantity ? $slot : null;

        return ['room_line_id' => $line->id, 'room_slot' => $slot];
    }

    /** "Deluxe (room 2)" / "Deluxe" (no slot named) / null (no room recorded, or its line is gone). */
    public static function label(object $row): ?string
    {
        $lineId = $row->room_line_id ?? null;
        if (! $lineId) {
            return null;
        }
        $isReservation = ! empty($row->reservation_id) || ($row instanceof \App\Models\ReservationAmenity);
        $table = $isReservation ? 'reservation_room_lines' : 'booking_room_lines';
        $key = $table . ':' . $lineId;
        if (! array_key_exists($key, self::$lines)) {
            $line = DB::table($table)->where('id', $lineId)->first(['room_type_id', 'room_type_name', 'quantity']);
            self::$lines[$key] = $line ? ['type' => (string) $line->room_type_name, 'quantity' => (int) $line->quantity, 'type_id' => (int) $line->room_type_id] : null;
        }
        $line = self::$lines[$key];
        if ($line === null) {
            return null;
        }
        $slot = $row->room_slot ?? null;

        return $slot ? "{$line['type']} (room {$slot})" : $line['type'];
    }

    /** The room type of the row's line (what a client names it by when it sends the amenity back on an edit). */
    public static function roomTypeId(object $row): ?int
    {
        self::label($row);
        $lineId = $row->room_line_id ?? null;
        if (! $lineId) {
            return null;
        }
        $isReservation = ! empty($row->reservation_id) || ($row instanceof \App\Models\ReservationAmenity);
        return self::$lines[($isReservation ? 'reservation_room_lines' : 'booking_room_lines') . ':' . $lineId]['type_id'] ?? null;
    }

    /** The fields a payload carries for a row. */
    public static function payload(object $row): array
    {
        return [
            'room_type_id' => self::roomTypeId($row),
            'room_line_id' => $row->room_line_id ?? null,
            'room_slot' => $row->room_slot ?? null,
            'room_label' => self::label($row),
        ];
    }

    public static function forget(): void
    {
        self::$lines = [];
    }
}
