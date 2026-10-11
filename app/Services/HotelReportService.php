<?php

namespace App\Services;

use App\Models\Booking;
use App\Models\Payment;
use App\Models\Reservation;
use App\Models\Room;
use App\Models\RoomType;
use App\Support\TestAccountScope;
use Carbon\Carbon;
use Carbon\CarbonPeriod;
use Illuminate\Support\Facades\DB;

/**
 * Hotel performance figures for the Admin and Manager reports (screen and
 * PDF), modelled on a standard daily manager / occupancy / RevPAR report:
 *
 *  - Occupancy comes from actual room assignments (booking_rooms) of stays
 *    that were checked in - a room counts as occupied for every night from
 *    the booking's check-in until that room was checked out (or the planned
 *    check-out while still in house).
 *  - Room revenue is accrued per night at the room's type rate, so ADR
 *    (room revenue / rooms sold) and RevPAR (room revenue / rooms available)
 *    follow the usual definitions. Payments collected (cash basis) is
 *    reported separately.
 *  - Rooms available = rooms in inventory (not archived) x days.
 *
 * Every figure respects the period and room-type filters and excludes
 * confirmed test accounts. The Manager report uses everything except the
 * revenue figures (managers never see revenue).
 */
class HotelReportService
{
    public function build(Carbon $from, Carbon $to, ?int $roomTypeId = null, ?string $paymentMethod = null, bool $withRevenue = true): array
    {
        $from = $from->copy()->startOfDay();
        $to = $to->copy()->startOfDay();
        $days = $from->diffInDays($to) + 1;

        $roomTypes = RoomType::orderBy('name')
            ->when($roomTypeId, fn ($q) => $q->whereKey($roomTypeId))
            ->get(['id', 'name', 'rate']);
        $roomsByType = Room::notArchived()
            ->when($roomTypeId, fn ($q) => $q->where('room_type_id', $roomTypeId))
            ->selectRaw('room_type_id, COUNT(*) as c')->groupBy('room_type_id')->pluck('c', 'room_type_id');
        $inventory = (int) $roomsByType->sum();

        // night (Y-m-d) => [rooms sold, room revenue]; type id => [nights, revenue]
        [$nights, $typeNights] = $this->roomNights($from, $to, $roomTypeId, $roomTypes->pluck('rate', 'id')->map(fn ($r) => (float) $r)->all());

        $buckets = $this->buckets($from, $to, $nights, $inventory);
        $soldTotal = array_sum(array_column($nights, 0));
        $roomRevenue = round(array_sum(array_column($nights, 1)), 2);
        $availableTotal = $inventory * $days;

        $summary = [
            'inventory' => $inventory,
            'out_of_order' => Room::notArchived()->where('status', 'maintenance')
                ->when($roomTypeId, fn ($q) => $q->where('room_type_id', $roomTypeId))->count(),
            'room_nights_available' => $availableTotal,
            'room_nights_sold' => $soldTotal,
            'occupancy' => $availableTotal > 0 ? round($soldTotal / $availableTotal * 100, 1) : 0.0,
            'arrivals' => $this->bookings($roomTypeId)
                ->whereIn('booking_status', [Booking::STATUS_CHECKED_IN, Booking::STATUS_COMPLETED])
                ->whereBetween('check_in', [$from, $to->copy()->endOfDay()])->count(),
            'departures' => $this->bookings($roomTypeId)
                ->where('booking_status', Booking::STATUS_COMPLETED)
                ->whereBetween('check_out', [$from, $to->copy()->endOfDay()])->count(),
            'in_house_rooms' => DB::table('booking_rooms')
                ->join('bookings', 'bookings.id', '=', 'booking_rooms.booking_id')
                ->join('rooms', 'rooms.id', '=', 'booking_rooms.room_id')
                ->where('bookings.booking_status', Booking::STATUS_CHECKED_IN)->whereNull('bookings.deleted_at')
                ->whereNull('booking_rooms.checked_out_at')
                ->whereNotIn('bookings.id', TestAccountScope::testBookingIdsQuery())
                ->when($roomTypeId, fn ($q) => $q->where('rooms.room_type_id', $roomTypeId))
                ->count(),
            'in_house_guests' => (int) $this->bookings($roomTypeId)
                ->where('booking_status', Booking::STATUS_CHECKED_IN)
                ->sum(DB::raw('COALESCE(adults, 0) + COALESCE(children, 0)')),
            'reservations' => $this->reservations($roomTypeId)->whereBetween('check_in', [$from, $to->copy()->endOfDay()])->count(),
            'bookings' => $this->bookings($roomTypeId)->whereNull('reservation_id')->whereBetween('check_in', [$from, $to->copy()->endOfDay()])->count(),
            'cancellations' => $this->reservations($roomTypeId)->where('status', Reservation::STATUS_CANCELLED)
                    ->whereBetween('check_in', [$from, $to->copy()->endOfDay()])->count()
                + $this->bookings($roomTypeId)->where('booking_status', Booking::STATUS_CANCELLED)
                    ->whereBetween('check_in', [$from, $to->copy()->endOfDay()])->count(),
            'average_stay' => round((float) ($this->reservations($roomTypeId)->whereBetween('check_in', [$from, $to->copy()->endOfDay()])
                ->selectRaw('AVG(DATEDIFF(check_out, check_in)) as n')->value('n') ?? 0), 1),
        ];

        if ($withRevenue) {
            $summary += [
                'room_revenue' => $roomRevenue,
                'adr' => $soldTotal > 0 ? round($roomRevenue / $soldTotal, 2) : 0.0,
                'revpar' => $availableTotal > 0 ? round($roomRevenue / $availableTotal, 2) : 0.0,
                'other_charges' => $this->otherCharges($from, $to, $roomTypeId),
                'payments_collected' => $this->paymentsCollected($from, $to, $roomTypeId, $paymentMethod),
            ];
        }

        $reservationsByType = $this->reservations(null)->whereBetween('check_in', [$from, $to->copy()->endOfDay()])
            ->selectRaw('room_type_id, COUNT(*) as c')->groupBy('room_type_id')->pluck('c', 'room_type_id');
        $types = $roomTypes->map(function ($type) use ($roomsByType, $typeNights, $reservationsByType, $days, $withRevenue) {
            $rooms = (int) ($roomsByType[$type->id] ?? 0);
            [$sold, $revenue] = $typeNights[$type->id] ?? [0, 0.0];
            $row = [
                'name' => $type->name,
                'rooms' => $rooms,
                'reservations' => (int) ($reservationsByType[$type->id] ?? 0),
                'nights_sold' => $sold,
                'occupancy' => $rooms * $days > 0 ? round($sold / ($rooms * $days) * 100, 1) : 0.0,
            ];
            if ($withRevenue) {
                $row['room_revenue'] = round($revenue, 2);
                $row['adr'] = $sold > 0 ? round($revenue / $sold, 2) : 0.0;
            }

            return $row;
        })->sortByDesc('nights_sold')->values()->all();

        return [
            'summary' => $summary,
            'buckets' => $buckets,
            'bucketUnit' => $days <= 31 ? 'Day' : ($days <= 182 ? 'Week' : 'Month'),
            'roomTypeRows' => $types,
            'guestRows' => $this->guests($from, $to, $roomTypeId),
        ];
    }

    /** Non-test, non-deleted bookings, optionally of one room type. */
    private function bookings(?int $roomTypeId)
    {
        return TestAccountScope::excludeFromBookings(
            Booking::query()->when($roomTypeId, fn ($q) => $q->where('room_type_id', $roomTypeId))
        );
    }

    private function reservations(?int $roomTypeId)
    {
        return TestAccountScope::excludeFromReservations(
            Reservation::query()->when($roomTypeId, fn ($q) => $q->where('room_type_id', $roomTypeId))
        );
    }

    /**
     * @return array{0: array<string, array{0:int,1:float}>, 1: array<int, array{0:int,1:float}>}
     */
    private function roomNights(Carbon $from, Carbon $to, ?int $roomTypeId, array $rates): array
    {
        $periodEnd = $to->copy()->addDay(); // exclusive
        $rows = DB::table('booking_rooms')
            ->join('bookings', 'bookings.id', '=', 'booking_rooms.booking_id')
            ->join('rooms', 'rooms.id', '=', 'booking_rooms.room_id')
            ->whereIn('bookings.booking_status', [Booking::STATUS_CHECKED_IN, Booking::STATUS_COMPLETED])
            ->whereNull('bookings.deleted_at')
            ->whereNotIn('bookings.id', TestAccountScope::testBookingIdsQuery())
            ->when($roomTypeId, fn ($q) => $q->where('rooms.room_type_id', $roomTypeId))
            ->where('bookings.check_in', '<', $periodEnd)
            ->get(['rooms.room_type_id', 'bookings.check_in', 'bookings.check_out', 'bookings.booking_status', 'booking_rooms.checked_out_at']);

        $nights = [];
        $typeNights = [];
        $today = Carbon::today();
        foreach ($rows as $row) {
            $start = Carbon::parse($row->check_in)->startOfDay();
            $end = $row->checked_out_at
                ? Carbon::parse($row->checked_out_at)->startOfDay()
                // Still in house past the planned check-out: occupied through today.
                : ($row->booking_status === Booking::STATUS_CHECKED_IN
                    ? Carbon::parse($row->check_out)->startOfDay()->max($today->copy()->addDay())
                    : Carbon::parse($row->check_out)->startOfDay());
            if ($end->lte($start)) {
                $end = $start->copy()->addDay(); // same-day stay is billed as one night
            }
            $rangeStart = $start->max($from);
            $rangeEnd = $end->min($periodEnd);
            $rate = $rates[$row->room_type_id] ?? 0.0;
            for ($d = $rangeStart->copy(); $d->lt($rangeEnd); $d->addDay()) {
                $key = $d->toDateString();
                $nights[$key][0] = ($nights[$key][0] ?? 0) + 1;
                $nights[$key][1] = ($nights[$key][1] ?? 0.0) + $rate;
                $typeNights[$row->room_type_id][0] = ($typeNights[$row->room_type_id][0] ?? 0) + 1;
                $typeNights[$row->room_type_id][1] = ($typeNights[$row->room_type_id][1] ?? 0.0) + $rate;
            }
        }

        return [$nights, $typeNights];
    }

    /** Per day (<= 31 days), per week (<= 26 weeks) or per month. */
    private function buckets(Carbon $from, Carbon $to, array $nights, int $inventory): array
    {
        $days = $from->diffInDays($to) + 1;
        $buckets = [];
        foreach (CarbonPeriod::create($from, $to) as $day) {
            if ($days <= 31) {
                $key = $day->toDateString();
                $label = $day->format('D, M d');
            } elseif ($days <= 182) {
                $weekStart = $day->copy()->startOfWeek()->max($from);
                $key = $weekStart->toDateString();
                $label = 'Week of ' . $weekStart->format('M d');
            } else {
                $key = $day->format('Y-m');
                $label = $day->format('M Y');
            }
            $buckets[$key] ??= ['label' => $label, 'available' => 0, 'occupied' => 0, 'revenue' => 0.0];
            $buckets[$key]['available'] += $inventory;
            $buckets[$key]['occupied'] += $nights[$day->toDateString()][0] ?? 0;
            $buckets[$key]['revenue'] += $nights[$day->toDateString()][1] ?? 0.0;
        }

        return array_values(array_map(function ($b) {
            $b['revenue'] = round($b['revenue'], 2);
            $b['occupancy'] = $b['available'] > 0 ? round($b['occupied'] / $b['available'] * 100, 1) : 0.0;
            $b['adr'] = $b['occupied'] > 0 ? round($b['revenue'] / $b['occupied'], 2) : 0.0;
            $b['revpar'] = $b['available'] > 0 ? round($b['revenue'] / $b['available'], 2) : 0.0;

            return $b;
        }, $buckets));
    }

    /** Amenities, extra-guest fees and additional charges on bills of stays that checked out in the period. */
    private function otherCharges(Carbon $from, Carbon $to, ?int $roomTypeId): float
    {
        $billings = DB::table('billings')
            ->join('bookings', 'bookings.id', '=', 'billings.booking_id')
            ->where('bookings.booking_status', Booking::STATUS_COMPLETED)->whereNull('bookings.deleted_at')
            ->whereBetween('bookings.check_out', [$from, $to->copy()->endOfDay()])
            ->whereNotIn('bookings.id', TestAccountScope::testBookingIdsQuery())
            ->when($roomTypeId, fn ($q) => $q->where('bookings.room_type_id', $roomTypeId));

        $fees = (float) (clone $billings)->sum(DB::raw('COALESCE(billings.amenity_charge, 0) + COALESCE(billings.additional_guest_fee, 0)'));
        $extras = (float) DB::table('additional_charges')->whereIn('billing_id', (clone $billings)->select('billings.id'))->sum('amount');

        return round($fees + $extras, 2);
    }

    /** Cash basis: completed payments (GCash once verified) received in the period. */
    private function paymentsCollected(Carbon $from, Carbon $to, ?int $roomTypeId, ?string $paymentMethod): float
    {
        return round((float) TestAccountScope::excludeFromPayments(
            Payment::countedAsRevenue()
                ->whereBetween('created_at', [$from, $to->copy()->endOfDay()])
                ->when($paymentMethod, fn ($q) => $q->where('payment_method', $paymentMethod))
                ->when($roomTypeId, fn ($q) => $q->where(function ($w) use ($roomTypeId) {
                    $w->whereHas('reservation', fn ($r) => $r->where('room_type_id', $roomTypeId))
                        ->orWhereHas('booking', fn ($b) => $b->where('room_type_id', $roomTypeId))
                        ->orWhereHas('billing.booking', fn ($b) => $b->where('room_type_id', $roomTypeId));
                }))
        )->sum('amount_paid'), 2);
    }

    /** Every guest with a stay checking in during the period (reservations + direct bookings). */
    private function guests(Carbon $from, Carbon $to, ?int $roomTypeId): array
    {
        $end = $to->copy()->endOfDay();
        $rows = [];
        $add = function ($guestId, $name, $nights) use (&$rows) {
            $rows[$guestId] ??= ['name' => $name, 'stays' => 0, 'nights' => 0];
            $rows[$guestId]['stays']++;
            $rows[$guestId]['nights'] += max(1, (int) $nights);
        };

        $this->reservations($roomTypeId)->with('guest.user')->whereNotNull('guest_id')
            ->whereBetween('check_in', [$from, $end])
            ->whereNotIn('status', [Reservation::STATUS_CANCELLED, Reservation::STATUS_REJECTED])
            ->get(['id', 'guest_id', 'check_in', 'check_out'])
            ->each(fn ($r) => $add($r->guest_id, $r->guest?->user?->full_name ?? 'N/A', $r->check_in->diffInDays($r->check_out)));
        $this->bookings($roomTypeId)->with('guest.user')->whereNull('reservation_id')->whereNotNull('guest_id')
            ->whereBetween('check_in', [$from, $end])
            ->where('booking_status', '!=', Booking::STATUS_CANCELLED)
            ->get(['id', 'guest_id', 'check_in', 'check_out'])
            ->each(fn ($b) => $add($b->guest_id, $b->guest?->user?->full_name ?? 'N/A', $b->check_in->diffInDays($b->check_out)));

        return collect($rows)->sortBy([['stays', 'desc'], ['nights', 'desc'], ['name', 'asc']])->values()->all();
    }
}
