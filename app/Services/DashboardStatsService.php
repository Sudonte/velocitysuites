<?php

namespace App\Services;

use App\Models\ActivityLog;
use App\Models\Amenity;
use App\Models\Booking;
use App\Models\Discount;
use App\Models\Payment;
use App\Models\Promotion;
use App\Models\Reservation;
use App\Models\Room;
use App\Models\RoomType;
use App\Models\User;
use Carbon\Carbon;

/**
 * Central place for dashboard statistics so counts stay connected to the
 * actual Reservation/Booking status model as it evolves, instead of being
 * re-derived (and drifting) inline in each dashboard controller.
 */
class DashboardStatsService
{
    public function __construct(private RoomAvailabilityService $availability)
    {
    }

    public function adminStats(): array
    {
        $todayRevenue = (float) Payment::where('payment_status', 'completed')
            ->whereDate('payment_date', today())
            ->sum('amount_paid');
        $yesterdayRevenue = (float) Payment::where('payment_status', 'completed')
            ->whereDate('payment_date', today()->subDay())
            ->sum('amount_paid');

        $monthlyRevenue = (float) Payment::where('payment_status', 'completed')
            ->whereMonth('payment_date', now()->month)
            ->whereYear('payment_date', now()->year)
            ->sum('amount_paid');
        $lastMonthRevenue = (float) Payment::where('payment_status', 'completed')
            ->whereMonth('payment_date', now()->subMonth()->month)
            ->whereYear('payment_date', now()->subMonth()->year)
            ->sum('amount_paid');

        $yearlyRevenue = (float) Payment::where('payment_status', 'completed')
            ->whereYear('payment_date', now()->year)
            ->sum('amount_paid');
        $lastYearRevenue = (float) Payment::where('payment_status', 'completed')
            ->whereYear('payment_date', now()->subYear()->year)
            ->sum('amount_paid');

        // "Active" and "completed" live on Booking (the operational record
        // from conversion onward) - Reservation's own status only covers
        // the pre-booking lifecycle.
        $pendingReservations = Reservation::whereIn('status', Reservation::ACTIVE_STATUSES)->count();
        $activeReservations = Booking::whereIn('booking_status', [Booking::STATUS_ACTIVE, Booking::STATUS_CHECKED_IN])->count();
        $completedReservations = Booking::where('booking_status', Booking::STATUS_COMPLETED)->count();

        $totalReservations = Reservation::count();
        $totalReservationsLastMonth = Reservation::where('created_at', '<=', now()->subMonth())->count();

        $totalBookings = Reservation::whereHas('booking')->count();
        $totalBookingsLastMonth = Reservation::whereHas('booking', fn ($q) => $q->where('confirmed_at', '<=', now()->subMonth()))->count();

        $pendingPaymentVerifications = Payment::where('payment_status', 'pending')->count();

        $totalUsers = User::count();
        $totalUsersLastMonth = User::where('created_at', '<=', now()->subMonth())->count();

        $totalRooms = Room::count();
        $totalRoomsLastMonth = Room::where('created_at', '<=', now()->subMonth())->count();

        return [
            // User stats - only Total Users gets a growth badge; Active/
            // Suspended are current status snapshots, not a running count,
            // so a "vs last month" comparison wouldn't reflect anything
            // real (a user can flip between them any day).
            'totalUsers' => $totalUsers,
            'totalUsersChange' => $this->percentChange($totalUsers, $totalUsersLastMonth),
            'activeUsers' => User::where('status', 'active')->count(),
            'suspendedUsers' => User::where('status', 'suspended')->count(),
            'totalGuests' => User::where('role', 'guest')->count(),
            'totalReceptionists' => User::where('role', 'receptionist')->count(),
            'totalManagers' => User::where('role', 'manager')->count(),
            'totalAdmins' => User::where('role', 'admin')->count(),

            // Room stats - Total Rooms must count every room regardless of
            // status (previously undercounted by omitting maintenance).
            // "Reserved" is no longer a state any code path writes (room
            // assignment now only happens at check-in, straight to
            // "occupied"), so Maintenance replaces it as the fourth card.
            // Available/Occupied/Maintenance change by the hour as guests
            // check in and out, so (like Active/Suspended Users) they don't
            // get a growth badge - only Total Rooms (actual inventory
            // added) does.
            'totalRooms' => $totalRooms,
            'totalRoomsChange' => $this->percentChange($totalRooms, $totalRoomsLastMonth),
            'availableRooms' => Room::where('status', 'available')->count(),
            'occupiedRooms' => Room::where('status', 'occupied')->count(),
            'maintenanceRooms' => Room::where('status', 'maintenance')->count(),

            // Promotions/Discounts/Amenities - plain status counts (not
            // date-range-qualified) so they match exactly what clicking
            // through to each module's own ?status=active|inactive filter
            // shows, instead of silently disagreeing with it.
            'activePromotions' => Promotion::where('status', 'active')->count(),
            'inactivePromotions' => Promotion::where('status', 'inactive')->count(),
            'activeDiscounts' => Discount::where('status', 'active')->count(),
            'inactiveDiscounts' => Discount::where('status', 'inactive')->count(),
            'activeAmenities' => Amenity::where('status', 'active')->count(),
            'inactiveAmenities' => Amenity::where('status', 'inactive')->count(),

            // Revenue - each compared against the equivalent prior period.
            'todayRevenue' => $todayRevenue,
            'todayRevenueChange' => $this->percentChange($todayRevenue, $yesterdayRevenue),
            'monthlyRevenue' => $monthlyRevenue,
            'monthlyRevenueChange' => $this->percentChange($monthlyRevenue, $lastMonthRevenue),
            'yearlyRevenue' => $yearlyRevenue,
            'yearlyRevenueChange' => $this->percentChange($yearlyRevenue, $lastYearRevenue),

            // Reservation stats - Total Reservations/Bookings are running
            // counts (only grow), so they get a growth badge; Pending/
            // Active/Completed/Pending Verifications are queue depths that
            // rise and fall daily, not meaningful to compare to a month ago.
            'totalReservations' => $totalReservations,
            'totalReservationsChange' => $this->percentChange($totalReservations, $totalReservationsLastMonth),
            'pendingReservations' => $pendingReservations,
            'activeReservations' => $activeReservations,
            'completedReservations' => $completedReservations,

            // Booking / payment stats
            'totalBookings' => $totalBookings,
            'totalBookingsChange' => $this->percentChange($totalBookings, $totalBookingsLastMonth),
            'pendingPaymentVerifications' => $pendingPaymentVerifications,

            // recentActivities: ActivityLog is an ever-growing event stream
            // (thousands of rows) - capped at a generous 50 so "Expand"
            // reveals a genuinely comprehensive recent window without
            // rendering the entire historical log into the dashboard card.
            'recentActivities' => ActivityLog::with('user')
                ->latest()
                ->limit(50)
                ->get(),
            // recentReservations: no cap - the reservations table is small
            // enough that "Expand" can show every row, matching the
            // dashboard's "show all available content" requirement exactly.
            'recentReservations' => Reservation::with(['guest.user', 'roomType', 'booking'])
                ->latest()
                ->get(),

            // Last-7-days trend lines for the overview charts.
            'usersTrend' => $this->dailySeries(fn ($date) => User::whereDate('created_at', '<=', $date)->count()),
            'reservationsTrend' => $this->dailySeries(fn ($date) => Reservation::whereDate('created_at', $date)->count()),
            'revenueTrend' => $this->dailySeries(fn ($date) => (float) Payment::where('payment_status', 'completed')->whereDate('payment_date', $date)->sum('amount_paid')),
        ];
    }

    /**
     * Percent change from $previous to $current, guarding the zero-baseline
     * case (nothing to compare against yet, e.g. a brand-new hotel).
     */
    private function percentChange(float $current, float $previous): float
    {
        if ($previous <= 0) {
            return $current > 0 ? 100.0 : 0.0;
        }

        return round((($current - $previous) / $previous) * 100, 1);
    }

    /**
     * One value per day for the last 7 days (oldest first), labeled with
     * short weekday-friendly dates for the trend charts.
     */
    private function dailySeries(callable $valueForDate): array
    {
        $labels = [];
        $values = [];

        for ($i = 6; $i >= 0; $i--) {
            $date = today()->subDays($i);
            $labels[] = $date->format('M d');
            $values[] = $valueForDate($date);
        }

        return ['labels' => $labels, 'values' => $values];
    }

    /**
     * $from/$to scope every period-dependent figure (reservations,
     * bookings, revenue, cancellation/no-show rate, average stay, room
     * utilization, the booking-trend chart) - see App\Support\DateRange.
     * Room-status counts and today's check-in/check-out/in-house figures
     * deliberately stay "right now" regardless of the filter, since
     * "how many rooms are occupied at this exact moment" isn't a
     * date-range question - the view renders these as a visually separate,
     * always-current block so the distinction reads intentionally.
     */
    public function managerStats(Carbon $from, Carbon $to): array
    {
        $totalRooms = Room::count();
        $occupiedRooms = Room::where('status', 'occupied')->count();
        $availableRooms = Room::where('status', 'available')->count();
        $maintenanceRooms = Room::where('status', 'maintenance')->count();
        $occupancyRate = $totalRooms > 0
            ? round(($occupiedRooms / $totalRooms) * 100, 1)
            : 0;

        // Check-in/check-out/in-house state lives on Booking (the
        // operational record from conversion onward), not on Reservation's
        // own status. Always "today", not filter-scoped (see docblock).
        $todayCheckIns = Booking::whereDate('check_in', today())
            ->where('booking_status', Booking::STATUS_ACTIVE)
            ->count();

        $todayCheckOuts = Booking::whereDate('check_out', today())
            ->where('booking_status', Booking::STATUS_CHECKED_IN)
            ->count();

        $inHouseGuests = Booking::where('booking_status', Booking::STATUS_CHECKED_IN)->count();

        $periodReservations = Reservation::whereBetween('check_in', [$from, $to])->count();
        $periodBookings = Reservation::whereBetween('check_in', [$from, $to])->whereHas('booking')->count();
        $periodCancelled = Reservation::whereBetween('check_in', [$from, $to])->where('status', Reservation::STATUS_CANCELLED)->count();

        $periodConfirmedBookings = Booking::whereBetween('check_in', [$from, $to])->count();
        $periodNoShows = Booking::where('booking_status', Booking::STATUS_ACTIVE)
            ->whereBetween('check_in', [$from, $to])
            ->where('check_in', '<', now())
            ->count();

        $periodRevenue = (float) Payment::where('payment_status', 'completed')
            ->whereBetween('payment_date', [$from, $to])
            ->sum('amount_paid');

        $averageStay = (float) (Reservation::whereBetween('check_in', [$from, $to])
            ->selectRaw('AVG(DATEDIFF(check_out, check_in)) as avg_nights')
            ->value('avg_nights') ?? 0);

        return [
            'totalRooms' => $totalRooms,
            'availableRooms' => $availableRooms,
            'occupiedRooms' => $occupiedRooms,
            'maintenanceRooms' => $maintenanceRooms,
            'occupancyRate' => $occupancyRate,
            'todayCheckIns' => $todayCheckIns,
            'todayCheckOuts' => $todayCheckOuts,
            'inHouseGuests' => $inHouseGuests,

            'totalReservations' => $periodReservations,
            'totalBookings' => $periodBookings,
            'pendingPaymentVerifications' => Payment::where('payment_status', 'pending')->count(),
            'periodRevenue' => $periodRevenue,

            // New KPIs - percentages guard against a zero-reservation
            // period (a brand-new hotel, or a custom range with no
            // activity) rather than dividing by zero.
            'cancellationRate' => $periodReservations > 0 ? round($periodCancelled / $periodReservations * 100, 1) : 0.0,
            'noShowRate' => $periodConfirmedBookings > 0 ? round($periodNoShows / $periodConfirmedBookings * 100, 1) : 0.0,
            'averageLengthOfStay' => round($averageStay, 1),
            'roomUtilization' => $this->availability->utilizationByRoomType($from, $to),

            // No cap - period-filtered already by whereBetween() above, and
            // small enough that "Expand" can show every matching row.
            'recentReservations' => Reservation::with(['guest.user', 'roomType', 'booking.room'])
                ->whereBetween('check_in', [$from, $to])
                ->latest()
                ->get(),
            // Ranked by bookings per room TYPE (Booking.room_type_id, set at
            // reservation time) - previously counted per individual physical
            // room instead, which split a popular type's bookings across its
            // rooms and could rank a less-popular type above it.
            // Kept at 5 - this exact set feeds the "Top Room Types" doughnut
            // chart's legend/slices, which isn't meant to grow.
            'topRoomTypes' => RoomType::withCount(['bookings' => fn ($q) => $q->whereBetween('check_in', [$from, $to])])
                ->orderByDesc('bookings_count')
                ->limit(5)
                ->get(),
            // Separate, uncapped query just for the dashboard's expandable
            // "Top Room Types" list card (the full room-type catalog, not
            // just the chart's top-5) - deliberately not reused for the
            // chart above so expanding this list doesn't also add extra
            // doughnut slices.
            'topRoomTypesList' => RoomType::withCount(['bookings' => fn ($q) => $q->whereBetween('check_in', [$from, $to])])
                ->orderByDesc('bookings_count')
                ->get(),

            // Breakdown for the "Bookings by Status" chart card.
            'bookingsByStatus' => Booking::whereBetween('check_in', [$from, $to])
                ->selectRaw('booking_status, count(*) as c')
                ->groupBy('booking_status')
                ->pluck('c', 'booking_status'),

            'bookingTrend' => $this->reservationTrend($from, $to),
        ];
    }

    /**
     * Daily reservation-creation counts across an arbitrary range (not
     * just the last 7 days - generalizes dailySeries() for the Manager
     * dashboard's date-filterable booking-trend chart). Capped at 60
     * points so a wide custom range doesn't render an unreadable chart -
     * beyond that the label simply becomes coarser (weekly).
     */
    private function reservationTrend(Carbon $from, Carbon $to): array
    {
        $totalDays = max(1, $from->diffInDays($to));
        $bucketDays = $totalDays > 60 ? (int) ceil($totalDays / 60) : 1;

        $labels = [];
        $values = [];

        $cursor = $from->copy()->startOfDay();
        while ($cursor->lte($to)) {
            $bucketEnd = $cursor->copy()->addDays($bucketDays - 1)->endOfDay();
            if ($bucketEnd->gt($to)) {
                $bucketEnd = $to->copy();
            }

            $labels[] = $bucketDays > 1
                ? $cursor->format('M d') . ' - ' . $bucketEnd->format('M d')
                : $cursor->format('M d');
            $values[] = Reservation::whereBetween('created_at', [$cursor, $bucketEnd])->count();

            $cursor->addDays($bucketDays);
        }

        return ['labels' => $labels, 'values' => $values];
    }
}
