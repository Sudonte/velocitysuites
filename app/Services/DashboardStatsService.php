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
use App\Support\TestAccountScope;
use Carbon\Carbon;
use Illuminate\Support\Facades\Cache;

/**
 * Central place for dashboard statistics so counts stay connected to the
 * actual Reservation/Booking status model as it evolves, instead of being
 * re-derived (and drifting) inline in each dashboard controller.
 *
 * Every business-facing figure here (revenue, occupancy, reservation/
 * booking counts, trend charts) excludes confirmed internal/developer
 * test accounts via App\Support\TestAccountScope - see that class's own
 * doc. pendingPaymentVerifications is deliberately left unfiltered: it's
 * an operational queue depth (a real payment a receptionist still has to
 * review, test account or not), not a business-performance figure.
 * recentActivities (the audit-log feed) is also deliberately left
 * unfiltered - test-account activity must stay fully visible there.
 */
class DashboardStatsService
{
    public function __construct(private RoomAvailabilityService $availability)
    {
    }

    /**
     * Cached (short TTL) - this fires ~25-30 separate aggregate queries
     * (revenue x6 periods, room/user/reservation counts, two 7-point trend
     * series each running its own query per day) and is recomputed on every
     * single admin dashboard visit. A 60s TTL means a dashboard that gets
     * refreshed/re-opened repeatedly (the common case) reuses one computed
     * snapshot instead of re-running all of it every time, while staying
     * close enough to live for figures that only meaningfully change a few
     * times an hour (bookings, payments, check-ins).
     */
    public function adminStats(): array
    {
        return Cache::remember('dashboard_stats:admin', now()->addSeconds(60), fn () => $this->computeAdminStats());
    }

    private function computeAdminStats(): array
    {
        $todayRevenue = (float) TestAccountScope::excludeFromPayments(
            Payment::where('payment_status', 'completed')->whereDate('payment_date', today())
        )->sum('amount_paid');
        $yesterdayRevenue = (float) TestAccountScope::excludeFromPayments(
            Payment::where('payment_status', 'completed')->whereDate('payment_date', today()->subDay())
        )->sum('amount_paid');

        $monthlyRevenue = (float) TestAccountScope::excludeFromPayments(
            Payment::where('payment_status', 'completed')
                ->whereMonth('payment_date', now()->month)
                ->whereYear('payment_date', now()->year)
        )->sum('amount_paid');
        $lastMonthRevenue = (float) TestAccountScope::excludeFromPayments(
            Payment::where('payment_status', 'completed')
                ->whereMonth('payment_date', now()->subMonth()->month)
                ->whereYear('payment_date', now()->subMonth()->year)
        )->sum('amount_paid');

        $yearlyRevenue = (float) TestAccountScope::excludeFromPayments(
            Payment::where('payment_status', 'completed')->whereYear('payment_date', now()->year)
        )->sum('amount_paid');
        $lastYearRevenue = (float) TestAccountScope::excludeFromPayments(
            Payment::where('payment_status', 'completed')->whereYear('payment_date', now()->subYear()->year)
        )->sum('amount_paid');

        // "Active" and "completed" live on Booking (the operational record
        // from conversion onward) - Reservation's own status only covers
        // the pre-booking lifecycle.
        $pendingReservations = TestAccountScope::excludeFromReservations(
            Reservation::whereIn('status', Reservation::ACTIVE_STATUSES)
        )->count();
        $activeReservations = TestAccountScope::excludeFromBookings(
            Booking::whereIn('booking_status', [Booking::STATUS_ACTIVE, Booking::STATUS_CHECKED_IN])
        )->count();
        $completedReservations = TestAccountScope::excludeFromBookings(
            Booking::where('booking_status', Booking::STATUS_COMPLETED)
        )->count();

        $totalReservations = TestAccountScope::excludeFromReservations(Reservation::query())->count();
        $totalReservationsLastMonth = TestAccountScope::excludeFromReservations(
            Reservation::where('created_at', '<=', now()->subMonth())
        )->count();

        // Matches exactly what the "Total Bookings" card's own link shows
        // (Admin\ReservationMonitoringController::index()'s type=booking
        // filter: Booking::whereNull('reservation_id'), the standalone/
        // direct "New Booking" pay-first transactions) - previously counted
        // converted Reservations instead (Reservation::whereHas('booking')),
        // a completely different, non-overlapping set from what clicking
        // the card actually showed, so the number never matched the page it
        // linked to. See Manager\ReservationViewController::index()'s
        // identical type=booking semantics for the Manager dashboard's own
        // "Bookings" card fix.
        $totalBookings = TestAccountScope::excludeFromBookings(Booking::whereNull('reservation_id'))->count();
        $totalBookingsLastMonth = TestAccountScope::excludeFromBookings(
            Booking::whereNull('reservation_id')->where('created_at', '<=', now()->subMonth())
        )->count();

        // Matches exactly what the "Pending Payment Verifications" card's
        // own link shows (Admin\ReservationMonitoringController::index()'s
        // payment_status=pending filter: reservations/bookings that HAVE a
        // pending payment) - a raw Payment::count() counts individual
        // payment ATTEMPTS instead, which over-counts the moment a single
        // reservation/booking has more than one pending payment row (e.g.
        // a retried GCash attempt), so the card's number no longer matched
        // the number of rows the page it links to actually shows.
        $pendingPaymentVerifications = Reservation::whereHas('payments', fn ($q) => $q->where('payment_status', 'pending'))->count()
            + Booking::whereNull('reservation_id')->whereHas('payments', fn ($q) => $q->where('payment_status', 'pending'))->count();

        $totalUsers = TestAccountScope::excludeFromUsers(User::query())->count();
        $totalUsersLastMonth = TestAccountScope::excludeFromUsers(
            User::where('created_at', '<=', now()->subMonth())
        )->count();

        $totalRooms = Room::count();
        $totalRoomsLastMonth = Room::where('created_at', '<=', now()->subMonth())->count();

        return [
            // User stats - only Total Users gets a growth badge; Active/
            // Suspended are current status snapshots, not a running count,
            // so a "vs last month" comparison wouldn't reflect anything
            // real (a user can flip between them any day).
            'totalUsers' => $totalUsers,
            'totalUsersChange' => $this->percentChange($totalUsers, $totalUsersLastMonth),
            'activeUsers' => TestAccountScope::excludeFromUsers(User::where('status', 'active'))->count(),
            'suspendedUsers' => TestAccountScope::excludeFromUsers(User::where('status', 'suspended'))->count(),
            'totalGuests' => TestAccountScope::excludeFromUsers(User::where('role', 'guest'))->count(),
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
            // Occupied/available derived from an actual CHECKED_IN booking
            // assignment, not the stored `status` column - that only flips
            // at explicit check-in/check-out events (or a manual admin
            // edit) and can drift from what's really occupied right now.
            // See Room::getEffectiveStatusAttribute()'s docblock. A room
            // currently held by a test-account booking counts as available
            // here (business-facing figure) even though it's physically
            // occupied - see managerStats()'s identical treatment.
            // whereNull('booking_rooms.checked_out_at') - a room
            // individually checked out (CheckOutController::checkOutRoom(),
            // a multi-room booking whose siblings are still checked in) is
            // free again immediately, matching Room::isCurrentlyOccupied().
            'availableRooms' => Room::where('status', '!=', 'maintenance')
                ->whereDoesntHave('assignedBookings', fn ($q) => TestAccountScope::excludeFromBookings(
                    $q->where('booking_status', Booking::STATUS_CHECKED_IN)
                )->whereNull('booking_rooms.checked_out_at'))
                ->count(),
            'occupiedRooms' => Room::where('status', '!=', 'maintenance')
                ->whereHas('assignedBookings', fn ($q) => TestAccountScope::excludeFromBookings(
                    $q->where('booking_status', Booking::STATUS_CHECKED_IN)
                )->whereNull('booking_rooms.checked_out_at'))
                ->count(),
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
            // Not test-excluded - an operational queue depth (a real
            // payment still needing staff review), not a business-
            // performance figure. See this class's own top doc.
            'pendingPaymentVerifications' => $pendingPaymentVerifications,

            // recentActivities: ActivityLog is an ever-growing event stream
            // (thousands of rows) - capped at a generous 50 so "Expand"
            // reveals a genuinely comprehensive recent window without
            // rendering the entire historical log into the dashboard card.
            // Deliberately NOT test-excluded - this is an audit trail, not
            // a business metric; every real action stays visible here.
            'recentActivities' => ActivityLog::with('user')
                ->latest()
                ->limit(50)
                ->get(),
            // recentReservations: no cap - the reservations table is small
            // enough that "Expand" can show every row, matching the
            // dashboard's "show all available content" requirement exactly.
            'recentReservations' => TestAccountScope::excludeFromReservations(
                Reservation::with(['guest.user', 'roomType', 'booking'])
            )->latest()->get(),

            // Last-7-days trend lines for the overview charts.
            'usersTrend' => $this->dailySeries(fn ($date) => TestAccountScope::excludeFromUsers(
                User::whereDate('created_at', '<=', $date)
            )->count()),
            'reservationsTrend' => $this->dailySeries(fn ($date) => TestAccountScope::excludeFromReservations(
                Reservation::whereDate('created_at', $date)
            )->count()),
            'revenueTrend' => $this->dailySeries(fn ($date) => (float) TestAccountScope::excludeFromPayments(
                Payment::where('payment_status', 'completed')->whereDate('payment_date', $date)
            )->sum('amount_paid')),
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
     *
     * Business-facing figures exclude confirmed test accounts (see this
     * class's own top doc / App\Support\TestAccountScope); pendingPayment
     * Verifications does not, for the same operational-queue reasoning as
     * adminStats().
     *
     * Cached (short TTL, keyed by the resolved date range) - same rationale
     * as adminStats(): a couple dozen aggregate queries plus a per-bucket
     * trend chart query, recomputed on every manager dashboard visit/
     * filter-change. Two managers viewing the same period within the TTL
     * window share one computed snapshot instead of each re-running the
     * full set.
     */
    public function managerStats(Carbon $from, Carbon $to): array
    {
        $cacheKey = 'dashboard_stats:manager:' . $from->toDateString() . ':' . $to->toDateString();

        return Cache::remember($cacheKey, now()->addSeconds(60), fn () => $this->computeManagerStats($from, $to));
    }

    private function computeManagerStats(Carbon $from, Carbon $to): array
    {
        $totalRooms = Room::count();
        // See adminStats()'s identical fix above - derived from an actual
        // CHECKED_IN booking assignment, not the stored `status` column.
        $occupiedRooms = Room::where('status', '!=', 'maintenance')
            ->whereHas('assignedBookings', fn ($q) => TestAccountScope::excludeFromBookings(
                $q->where('booking_status', Booking::STATUS_CHECKED_IN)
            )->whereNull('booking_rooms.checked_out_at'))
            ->count();
        $availableRooms = Room::where('status', '!=', 'maintenance')
            ->whereDoesntHave('assignedBookings', fn ($q) => TestAccountScope::excludeFromBookings(
                $q->where('booking_status', Booking::STATUS_CHECKED_IN)
            )->whereNull('booking_rooms.checked_out_at'))
            ->count();
        $maintenanceRooms = Room::where('status', 'maintenance')->count();
        $occupancyRate = $totalRooms > 0
            ? round(($occupiedRooms / $totalRooms) * 100, 1)
            : 0;

        // Check-in/check-out/in-house state lives on Booking (the
        // operational record from conversion onward), not on Reservation's
        // own status. Always "today", not filter-scoped (see docblock).
        $todayCheckIns = TestAccountScope::excludeFromBookings(
            Booking::whereDate('check_in', today())->where('booking_status', Booking::STATUS_ACTIVE)
        )->count();

        $todayCheckOuts = TestAccountScope::excludeFromBookings(
            Booking::whereDate('check_out', today())->where('booking_status', Booking::STATUS_CHECKED_IN)
        )->count();

        $inHouseGuests = TestAccountScope::excludeFromBookings(
            Booking::where('booking_status', Booking::STATUS_CHECKED_IN)
        )->count();

        $periodReservations = TestAccountScope::excludeFromReservations(
            Reservation::whereBetween('check_in', [$from, $to])
        )->count();
        // See adminStats()'s identical fix - matches exactly what the
        // "Bookings" card's own link shows (Manager\ReservationViewController::
        // index()'s type=booking filter: standalone/direct "New Booking"
        // pay-first transactions), not converted Reservations.
        $periodBookings = TestAccountScope::excludeFromBookings(
            Booking::whereNull('reservation_id')->whereBetween('check_in', [$from, $to])
        )->count();
        $periodCancelled = TestAccountScope::excludeFromReservations(
            Reservation::whereBetween('check_in', [$from, $to])->where('status', Reservation::STATUS_CANCELLED)
        )->count();

        $periodConfirmedBookings = TestAccountScope::excludeFromBookings(
            Booking::whereBetween('check_in', [$from, $to])
        )->count();
        $periodNoShows = TestAccountScope::excludeFromBookings(
            Booking::where('booking_status', Booking::STATUS_ACTIVE)
                ->whereBetween('check_in', [$from, $to])
                ->where('check_in', '<', now())
        )->count();

        $periodRevenue = (float) TestAccountScope::excludeFromPayments(
            Payment::where('payment_status', 'completed')->whereBetween('payment_date', [$from, $to])
        )->sum('amount_paid');

        $averageStay = (float) (TestAccountScope::excludeFromReservations(
            Reservation::whereBetween('check_in', [$from, $to])
        )->selectRaw('AVG(DATEDIFF(check_out, check_in)) as avg_nights')->value('avg_nights') ?? 0);

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
            'recentReservations' => TestAccountScope::excludeFromReservations(
                Reservation::with(['guest.user', 'roomType', 'booking.room'])->whereBetween('check_in', [$from, $to])
            )->latest()->get(),
            // Ranked by bookings per room TYPE (Booking.room_type_id, set at
            // reservation time) - previously counted per individual physical
            // room instead, which split a popular type's bookings across its
            // rooms and could rank a less-popular type above it.
            // Kept at 5 - this exact set feeds the "Top Room Types" doughnut
            // chart's legend/slices, which isn't meant to grow.
            'topRoomTypes' => RoomType::withCount(['bookings' => fn ($q) => TestAccountScope::excludeFromBookings(
                $q->whereBetween('check_in', [$from, $to])
            )])
                ->orderByDesc('bookings_count')
                ->limit(5)
                ->get(),
            // Separate, uncapped query just for the dashboard's expandable
            // "Top Room Types" list card (the full room-type catalog, not
            // just the chart's top-5) - deliberately not reused for the
            // chart above so expanding this list doesn't also add extra
            // doughnut slices.
            'topRoomTypesList' => RoomType::withCount(['bookings' => fn ($q) => TestAccountScope::excludeFromBookings(
                $q->whereBetween('check_in', [$from, $to])
            )])
                ->orderByDesc('bookings_count')
                ->get(),

            // Breakdown for the "Bookings by Status" chart card.
            'bookingsByStatus' => TestAccountScope::excludeFromBookings(
                Booking::whereBetween('check_in', [$from, $to])
            )
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
            $values[] = TestAccountScope::excludeFromReservations(
                Reservation::whereBetween('created_at', [$cursor, $bucketEnd])
            )->count();

            $cursor->addDays($bucketDays);
        }

        return ['labels' => $labels, 'values' => $values];
    }

    /**
     * Month-by-month Gross/Cancelled/Net reservation counts for the
     * trailing $months calendar months (oldest first, current month
     * last) - the "seasonal volatility" timeline used by the Admin/Manager
     * PDF reports (AdminReportController::exportPdf(),
     * Manager\ReportController::exportPdf()). Kept here rather than in
     * either controller since it's the same figure regardless of which
     * role is asking for it, same as every other stat in this class.
     * Excludes confirmed test accounts, same as every other business
     * figure here (see this class's own top doc).
     */
    public function monthlyReservationBreakdown(int $months = 6): array
    {
        $start = now()->subMonths($months - 1)->startOfMonth();
        $end = now()->endOfMonth();

        $rows = TestAccountScope::excludeFromReservations(
            Reservation::whereBetween('created_at', [$start, $end])
        )
            ->selectRaw(
                "DATE_FORMAT(created_at, '%Y-%m') as ym, COUNT(*) as gross, SUM(CASE WHEN status = ? THEN 1 ELSE 0 END) as cancelled",
                [Reservation::STATUS_CANCELLED]
            )
            ->groupBy('ym')
            ->get()
            ->keyBy('ym');

        $result = [];
        for ($i = $months - 1; $i >= 0; $i--) {
            $month = now()->subMonths($i);
            $row = $rows->get($month->format('Y-m'));
            $gross = (int) ($row->gross ?? 0);
            $cancelled = (int) ($row->cancelled ?? 0);

            $result[] = [
                'label' => $month->format('M Y'),
                'gross' => $gross,
                'cancelled' => $cancelled,
                'net' => $gross - $cancelled,
                'cancelRate' => $gross > 0 ? round($cancelled / $gross * 100, 1) : 0.0,
            ];
        }

        return $result;
    }
}
