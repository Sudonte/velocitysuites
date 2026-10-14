<?php

namespace App\Services;

use App\Models\Booking;
use App\Models\Room;
use App\Support\CheckInWindow;
use App\Support\TestAccountScope;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * DAE-1A (Monthly Recording Format for Accommodation Establishment) for the
 * Admin and Manager reports - the figures behind the Excel form.
 *
 * Built from actual room assignments (booking_rooms) of stays that were
 * checked in: a guest is in a room every night from the day they actually
 * checked in (bookings.checked_in_at, else the planned check-in) until that
 * room was checked out (else the planned check-out, or today while still in
 * house); a same-day stay counts as one night. Timestamps are stored in UTC
 * and read as hotel (Asia/Manila) calendar days. A booking's guests are
 * split across its rooms (the system records guests per booking, not per
 * room).
 *
 * Per day: guests in each room (and whether they checked in that day),
 * (5) guests checked in, (6) guests staying overnight, (7) rooms occupied.
 */
class Dae1aReportService
{
    /** [year, month] from ?month=YYYY-MM, falling back to the current month. */
    public static function monthFrom(?string $raw): array
    {
        if (is_string($raw) && preg_match('/^(\d{4})-(\d{2})$/', $raw, $m) && (int) $m[2] >= 1 && (int) $m[2] <= 12 && (int) $m[1] >= 2000) {
            return [(int) $m[1], (int) $m[2]];
        }

        return [(int) now()->year, (int) now()->month];
    }

    public function build(int $year, int $month): array
    {
        $first = Carbon::create($year, $month, 1)->startOfDay();
        $daysInMonth = $first->daysInMonth;
        $periodEnd = $first->copy()->addDays($daysInMonth); // exclusive

        $stays = DB::table('booking_rooms')
            ->join('bookings', 'bookings.id', '=', 'booking_rooms.booking_id')
            ->whereIn('bookings.booking_status', [Booking::STATUS_CHECKED_IN, Booking::STATUS_COMPLETED])
            ->whereNull('bookings.deleted_at')
            ->whereNotIn('bookings.id', TestAccountScope::testBookingIdsQuery())
            ->where(fn ($q) => $q->where('bookings.check_in', '<', $periodEnd)
                ->orWhere('bookings.checked_in_at', '<', $periodEnd->copy()->shiftTimezone(CheckInWindow::TIMEZONE)->utc()))
            ->get(['booking_rooms.booking_id', 'booking_rooms.room_id', 'booking_rooms.checked_out_at',
                'bookings.check_in', 'bookings.check_out', 'bookings.checked_in_at', 'bookings.booking_status',
                'bookings.adults', 'bookings.children', 'bookings.number_of_guests']);

        $stays = $stays->sortBy(['booking_id', 'room_id'])->values();
        $roomsPerBooking = $stays->countBy('booking_id');
        $roomIndex = [];
        $today = Carbon::parse(now(CheckInWindow::TIMEZONE)->toDateString());

        // cells[room_id][day] = ['guests' => int, 'checked_in' => bool]
        $cells = [];
        foreach ($stays as $row) {
            $start = $row->checked_in_at ? self::hotelDay($row->checked_in_at) : Carbon::parse($row->check_in)->startOfDay();
            $end = $row->checked_out_at
                ? self::hotelDay($row->checked_out_at)
                : ($row->booking_status === Booking::STATUS_CHECKED_IN
                    ? Carbon::parse($row->check_out)->startOfDay()->max($today->copy()->addDay())
                    : Carbon::parse($row->check_out)->startOfDay());
            if ($end->lte($start)) {
                $end = $start->copy()->addDay();
            }
            if ($end->lte($first)) {
                continue;
            }

            $guests = $this->guestsInRoom($row, $roomsPerBooking[$row->booking_id], $roomIndex[$row->booking_id] = ($roomIndex[$row->booking_id] ?? -1) + 1);
            $from = $start->max($first);
            $to = $end->min($periodEnd);
            for ($d = $from->copy(); $d->lt($to); $d->addDay()) {
                $cell = &$cells[$row->room_id][$d->day];
                $cell['guests'] = ($cell['guests'] ?? 0) + $guests;
                $cell['checked_in'] = ($cell['checked_in'] ?? false) || $d->eq($start);
                unset($cell);
            }
        }

        // Every room in inventory, plus any archived room that was occupied this month.
        $rooms = Room::query()
            ->where(fn ($q) => $q->notArchived()->orWhereIn('id', array_keys($cells)))
            ->get(['id', 'room_number'])
            ->sortBy('room_number', SORT_NATURAL)
            ->values();

        $days = [];
        for ($day = 1; $day <= $daysInMonth; $day++) {
            $date = $first->copy()->day($day);
            $checkedIn = $overnight = $occupied = 0;
            $roomCells = [];
            foreach ($rooms as $room) {
                $cell = $cells[$room->id][$day] ?? null;
                $roomCells[$room->id] = $cell;
                if ($cell) {
                    $overnight += $cell['guests'];
                    $occupied++;
                    if ($cell['checked_in']) {
                        $checkedIn += $cell['guests'];
                    }
                }
            }
            $days[] = [
                'day' => $day,
                'weekday' => $date->format('D'),
                'rooms' => $roomCells,
                'checked_in' => $checkedIn,
                'overnight' => $overnight,
                'rooms_occupied' => $occupied,
            ];
        }

        $totalRooms = Room::notArchived()->count();
        $totals = [
            'checked_in' => array_sum(array_column($days, 'checked_in')),
            'overnight' => array_sum(array_column($days, 'overnight')),
            'rooms_occupied' => array_sum(array_column($days, 'rooms_occupied')),
            'rooms_available' => $totalRooms * $daysInMonth,
        ];

        return [
            'year' => $year,
            'month' => $month,
            'monthName' => $first->format('F'),
            'totalRooms' => $totalRooms,
            'rooms' => $rooms,
            'days' => $days,
            'totals' => $totals,
            'averages' => [
                'guest_nights' => $totals['checked_in'] > 0 ? round($totals['overnight'] / $totals['checked_in'], 2) : 0.0,
                'occupancy_rate' => $totals['rooms_available'] > 0 ? round($totals['rooms_occupied'] / $totals['rooms_available'] * 100, 2) : 0.0,
                'guests_per_room' => $totals['rooms_occupied'] > 0 ? round($totals['overnight'] / $totals['rooms_occupied'], 2) : 0.0,
            ],
        ];
    }

    /** A stored (UTC) timestamp as the hotel's calendar day. */
    private static function hotelDay(string $utc): Carbon
    {
        return Carbon::parse(Carbon::parse($utc, 'UTC')->setTimezone(CheckInWindow::TIMEZONE)->toDateString());
    }

    /** The booking's guests split across its rooms; the first rooms take any remainder. */
    private function guestsInRoom(object $row, int $roomCount, int $index): int
    {
        $guests = ((int) $row->adults + (int) $row->children) ?: ((int) $row->number_of_guests ?: 1);

        return max(1, intdiv($guests, $roomCount) + ($index < $guests % $roomCount ? 1 : 0));
    }
}
