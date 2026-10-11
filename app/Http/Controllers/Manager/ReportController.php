<?php

namespace App\Http\Controllers\Manager;

use App\Http\Controllers\Controller;
use App\Models\Booking;
use App\Models\Reservation;
use App\Models\RoomType;
use App\Services\DashboardStatsService;
use App\Support\ReportFilters;
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
     * Manager reports with the shared filters (ReportFilters: period,
     * default Today, plus room type). Never includes revenue.
     */
    public function index(Request $request): View
    {
        $filters = ReportFilters::resolve($request);
        $data = $this->reportData($filters);
        $roomTypes = RoomType::orderBy('name')->get(['id', 'name']);

        return view('manager.reports.index', array_merge([
            'filters' => $filters,
            'roomTypes' => $roomTypes,
            'from' => $filters['from'],
            'to' => $filters['to'],
            'period' => $filters['period'],
        ], $data));
    }

    /**
     * The same figures as the on-screen report (same filters) as a branded
     * dompdf document, plus the dashboard's occupancy rate
     * for the period.
     */
    public function exportPdf(Request $request)
    {
        $filters = ReportFilters::resolve($request);
        $data = $this->reportData($filters);
        $managerStats = $this->stats->managerStats($filters['from'], $filters['to']);
        $periodLabel = $filters['from']->format('M d, Y') . ' - ' . $filters['to']->format('M d, Y');
        if ($filters['room_type_id']) {
            $periodLabel .= ' | ' . RoomType::whereKey($filters['room_type_id'])->value('name');
        }

        $pdf = Pdf::loadView('manager.reports.export-pdf', $data + [
            'periodLabel' => $periodLabel,
            'generatedAt' => now(),
            'managerStats' => $managerStats,
            'monthlyBreakdown' => $this->stats->monthlyReservationBreakdown(),
        ])->setPaper('a4', 'portrait');

        return $pdf->download('Velocity-Suites-Manager-Report_' . now()->format('Y-m-d_His') . '.pdf');
    }

    /**
     * Cached (short TTL, keyed by every filter) and shared by the screen and
     * the PDF so the two never disagree.
     */
    private function reportData(array $filters): array
    {
        return Cache::remember('manager_report:v2:' . ReportFilters::cacheKey($filters), now()->addSeconds(60),
            fn () => $this->computeReport($filters['from'], $filters['to'], $filters['room_type_id']));
    }

    private function computeReport(Carbon $from, Carbon $to, ?int $roomTypeId = null): array
    {
        // Managers never receive revenue figures; revenue reporting is
        // Admin-only (Admin\AdminReportController). Excludes confirmed
        // internal/test accounts (App\Support\TestAccountScope).
        $totalReservations = TestAccountScope::excludeFromReservations(
            Reservation::whereBetween('check_in', [$from, $to])
                ->when($roomTypeId, fn ($q) => $q->where('room_type_id', $roomTypeId))
        )->count();
        // Direct bookings, the same definition as the dashboard's Bookings card.
        $totalBookings = TestAccountScope::excludeFromBookings(
            Booking::whereNull('reservation_id')->whereBetween('check_in', [$from, $to])
                ->when($roomTypeId, fn ($q) => $q->where('room_type_id', $roomTypeId))
        )->count();
        $averageStay = (float) (TestAccountScope::excludeFromReservations(
            Reservation::whereBetween('check_in', [$from, $to])
                ->when($roomTypeId, fn ($q) => $q->where('room_type_id', $roomTypeId))
        )->selectRaw('AVG(DATEDIFF(check_out, check_in)) as avg_nights')->value('avg_nights') ?? 0);

        // Top room types - counted via RoomType's own reservations() relation
        // (Reservation.room_type_id, set at request time), not Room's - a
        // room is only ever assigned to a specific reservation at check-in,
        // so Room::reservations() no longer gets populated and always
        // returned zero here.
        $topRoomTypes = RoomType::withCount(['reservations' => function ($q) use ($from, $to) {
            TestAccountScope::excludeFromReservations($q->whereBetween('check_in', [$from, $to]));
        }])
            ->when($roomTypeId, fn ($q) => $q->whereKey($roomTypeId))
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
                ->when($roomTypeId, fn ($q) => $q->where('room_type_id', $roomTypeId))
        )
            ->groupBy('guest_id')
            ->orderByDesc('reservation_count')
            ->limit(5)
            ->get();

        return compact(
            'totalReservations',
            'totalBookings',
            'averageStay',
            'topRoomTypes',
            'topGuests'
        );
    }
}
