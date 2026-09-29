<?php

namespace App\Http\Controllers\Manager;

use App\Http\Controllers\Controller;
use App\Models\Payment;
use App\Models\Reservation;
use App\Models\RoomType;
use App\Services\DashboardStatsService;
use App\Support\DateRange;
use App\Support\TestAccountScope;
use Barryvdh\DomPDF\Facade\Pdf;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;

class ReportController extends Controller
{
    public function __construct(private DashboardStatsService $stats)
    {
    }

    /**
     * Display reports. Accepts the same ?period=daily|weekly|monthly|custom
     * (+from/to) quick-period selector as the Manager dashboard (see
     * App\Support\DateRange) instead of this page's own previous from/to-only,
     * always-"this month"-by-default filter - a manager/admin can now pick
     * Today/This Week/This Month/Custom before generating a report instead of
     * only ever getting an implicit "current month" unless they manually typed
     * both date fields.
     */
    public function index(Request $request): View
    {
        [$from, $to, $period] = $this->resolveDateRange($request);

        $data = $this->reportData($from, $to);

        return view('manager.reports.index', array_merge(compact('from', 'to', 'period'), $data));
    }

    /**
     * A real, formal PDF document (dompdf, same library already used for
     * Guest reservation/payment exports) - the "Print Report" button
     * previously just called window.print() on this dashboard's live HTML,
     * which produced a screenshot-like printout of stat cards/icons rather
     * than an actual report. This renders a dedicated tabular layout
     * (manager.reports.export-pdf) instead, branded with the Velocity
     * Suites logo/name, built from the exact same figures as the on-screen
     * report plus the occupancy/cancellation/no-show rates already computed
     * by DashboardStatsService::managerStats() for the Manager dashboard.
     */
    public function exportPdf(Request $request)
    {
        [$from, $to] = $this->resolveDateRange($request);

        $data = $this->reportData($from, $to);
        $managerStats = $this->stats->managerStats($from, $to);
        $periodLabel = $from->format('M d, Y') . ' - ' . $to->format('M d, Y');

        $pdf = Pdf::loadView('manager.reports.export-pdf', $data + [
            'periodLabel' => $periodLabel,
            'generatedAt' => now(),
            'managerStats' => $managerStats,
            'monthlyBreakdown' => $this->stats->monthlyReservationBreakdown(),
        ])->setPaper('a4', 'portrait');

        return $pdf->download('Velocity-Suites-Manager-Report_' . now()->format('Y-m-d_His') . '.pdf');
    }

    /**
     * @return array{0: Carbon, 1: Carbon, 2: string}
     */
    private function resolveDateRange(Request $request): array
    {
        // Defaults to Today, not DateRange::resolve()'s own 'monthly'
        // default - a report should open scoped to today unless the
        // manager/admin picks a wider period, whereas the Manager
        // Dashboard (DateRange's other caller) keeps its own separate
        // "current month" default untouched.
        if (! $request->filled('period')) {
            $request->merge(['period' => 'daily']);
        }

        return DateRange::resolve($request);
    }

    /**
     * Cached (short TTL, keyed by the resolved date range) - shared by both
     * the on-screen report and the PDF export so the two never disagree.
     */
    private function reportData(Carbon $from, Carbon $to): array
    {
        $cacheKey = 'manager_report:' . $from->toDateString() . ':' . $to->toDateString();

        return Cache::remember($cacheKey, now()->addSeconds(60), fn () => $this->computeReport($from, $to));
    }

    private function computeReport(Carbon $from, Carbon $to): array
    {
        // Revenue by day - excludes confirmed internal/test accounts (see
        // App\Support\TestAccountScope) so this reads as real business
        // performance, not development noise.
        $revenueByDay = TestAccountScope::excludeFromPayments(
            Payment::where('payment_status', 'completed')->whereBetween('payment_date', [$from, $to])
        )
            ->selectRaw('DATE(payment_date) as day, SUM(amount_paid) as total')
            ->groupBy('day')
            ->orderBy('day')
            ->get();

        $totalRevenue = (float) $revenueByDay->sum('total');
        $totalReservations = TestAccountScope::excludeFromReservations(
            Reservation::whereBetween('check_in', [$from, $to])
        )->count();
        $totalBookings = TestAccountScope::excludeFromReservations(
            Reservation::whereBetween('check_in', [$from, $to])->whereHas('booking')
        )->count();
        $averageStay = (float) (TestAccountScope::excludeFromReservations(
            Reservation::whereBetween('check_in', [$from, $to])
        )->selectRaw('AVG(DATEDIFF(check_out, check_in)) as avg_nights')->value('avg_nights') ?? 0);

        // Top room types - counted via RoomType's own reservations() relation
        // (Reservation.room_type_id, set at request time), not Room's - a
        // room is only ever assigned to a specific reservation at check-in,
        // so Room::reservations() no longer gets populated and always
        // returned zero here.
        $topRoomTypes = RoomType::withCount(['reservations' => function ($q) use ($from, $to) {
            TestAccountScope::excludeFromReservations($q->whereBetween('check_in', [$from, $to]));
        }])
            ->orderByDesc('reservations_count')
            ->limit(5)
            ->get();

        // Top guests (by reservation count in range) - guest_id is
        // nullable (a receptionist-created walk-in reservation has no
        // Guest account at all), and grouping by it would otherwise
        // collapse every accountless walk-in in the range into one NULL
        // bucket that could outrank, or displace, real repeat guests.
        // Also excludes confirmed test accounts - otherwise the project's
        // own repeated development testing would always rank as the
        // "top guest" ahead of every real repeat customer.
        $topGuests = TestAccountScope::excludeFromReservations(
            Reservation::select('guest_id', DB::raw('COUNT(*) as reservation_count'))
                ->with('guest.user')
                ->whereNotNull('guest_id')
                ->whereBetween('check_in', [$from, $to])
        )
            ->groupBy('guest_id')
            ->orderByDesc('reservation_count')
            ->limit(5)
            ->get();

        return compact(
            'revenueByDay',
            'totalRevenue',
            'totalReservations',
            'totalBookings',
            'averageStay',
            'topRoomTypes',
            'topGuests'
        );
    }
}
