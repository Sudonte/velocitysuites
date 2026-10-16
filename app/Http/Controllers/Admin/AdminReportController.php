<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\ActivityLog;
use App\Models\RoomType;
use App\Services\Dae1aReportService;
use App\Services\HotelReportService;
use App\Support\Dae1aSpreadsheet;
use App\Support\PerPage;
use App\Support\ReportFilters;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\View\View;

class AdminReportController extends Controller
{
    public function __construct(private HotelReportService $reports)
    {
    }

    /**
     * Admin report: occupancy, revenue (room revenue, ADR, RevPAR, other
     * charges, payments collected), a per-day/week/month graph and table,
     * every room type and every guest - all for the selected filters
     * (App\Support\ReportFilters: period, room type, payment method). The
     * screen also lists the activity log for the period.
     */
    public function index(Request $request): View
    {
        $filters = ReportFilters::resolve($request);

        // Audit trail filters: type of action, role and user (date range = the period above).
        $logType = $request->get('log_type');
        $logRole = $request->get('log_role');
        $logUser = $request->filled('log_user') ? (int) $request->get('log_user') : null;
        $activityLogs = ActivityLog::with('user')
            ->where('created_at', '>=', \App\Support\CheckInWindow::rangeBoundsUtc($filters['from'], $filters['to'])[0])
            ->where('created_at', '<', \App\Support\CheckInWindow::rangeBoundsUtc($filters['from'], $filters['to'])[1])
            ->when($logType, fn ($q) => $this->filterLogType($q, $logType))
            ->when(in_array($logRole, ['admin', 'manager', 'receptionist', 'guest'], true), fn ($q) => $q->whereHas('user', fn ($u) => $u->where('role', $logRole)))
            ->when($logUser, fn ($q) => $q->where('user_id', $logUser))
            ->latest()
            ->paginate(PerPage::resolve($request))
            ->withQueryString();

        return view('admin.reports.index', [
            'activityLogs' => $activityLogs,
            'filters' => $filters,
            'roomTypes' => RoomType::orderBy('name')->get(['id', 'name']),
            'report' => $this->reportData($filters),
            'logUsers' => \App\Models\User::whereIn('role', ['admin', 'manager', 'receptionist'])->orderBy('first_name')->get(['id', 'first_name', 'last_name', 'role']),
            'arrivalDate' => $arrivalDate = $this->arrivalDate($request),
            'arrivals' => $this->reports->dailyArrivals($arrivalDate),
        ]);
    }

    /** The on-screen report (same filters, same cached data) as a branded PDF. */
    public function exportPdf(Request $request)
    {
        $filters = ReportFilters::resolve($request);

        $periodLabel = $filters['from']->format('M d, Y') . ' - ' . $filters['to']->format('M d, Y');
        if ($filters['room_type_id']) {
            $periodLabel .= ' | ' . RoomType::whereKey($filters['room_type_id'])->value('name');
        }
        if ($filters['payment_method']) {
            $periodLabel .= ' | ' . ($filters['payment_method'] === 'gcash' ? 'GCash' : 'Cash') . ' payments';
        }

        $pdf = Pdf::loadView('admin.reports.export-pdf', [
            'report' => $this->reportData($filters),
            'periodLabel' => $periodLabel,
            'generatedAt' => now(),
        ])->setPaper('a4', 'portrait');

        return $pdf->download('Velocity-Suites-Admin-Report_' . now()->format('Y-m-d_His') . '.pdf');
    }

    /** Daily Guest Arrivals for ?arrival_date= (default today) as a PDF [REQ065]. */
    public function arrivalsPdf(Request $request)
    {
        $day = $this->arrivalDate($request);

        return Pdf::loadView('reports.arrivals-pdf', [
            'arrivals' => $this->reports->dailyArrivals($day),
            'arrivalDate' => $day,
            'generatedAt' => now(),
        ])->setPaper('a4', 'portrait')->download('Velocity-Suites-Daily-Arrivals_' . $day->toDateString() . '.pdf');
    }

    /** Monthly DAE-1A form for ?month=YYYY-MM (default this month) as an Excel file. */
    public function dae1a(Request $request, Dae1aReportService $dae1a)
    {
        [$year, $month] = Dae1aReportService::monthFrom($request->query('month'));

        return Dae1aSpreadsheet::download($dae1a->build($year, $month));
    }

    private function arrivalDate(Request $request): \Carbon\Carbon
    {
        $raw = (string) $request->get('arrival_date', '');

        return preg_match('/^\d{4}-\d{2}-\d{2}$/', $raw) && strtotime($raw) ? \Carbon\Carbon::parse($raw)->startOfDay() : today();
    }

    /** Activity log "Type" filter - by action wording and what the action was about. */
    private function filterLogType($query, string $type): void
    {
        match ($type) {
            'auth' => $query->whereIn('action', \App\Support\Activity::AUTH_ACTIONS),
            'reservation' => $query->where(fn ($q) => $q->where('subject_type', 'reservation')->orWhere('action', 'like', '%reservation%')),
            'booking' => $query->where(fn ($q) => $q->where('subject_type', 'booking')->orWhere('action', 'like', '%booking%')
                ->orWhere('action', 'like', '%check%')->orWhere('action', 'like', '%no-show%')),
            'payment' => $query->where(fn ($q) => $q->where('action', 'like', '%payment%')->orWhere('action', 'like', '%charge%')->orWhere('action', 'like', '%bill%')),
            'amenity' => $query->where(fn ($q) => $q->where('subject_type', 'amenity')->orWhere('action', 'like', '%amenit%')),
            'admin' => $query->whereIn('subject_type', ['user', 'promotion', 'discount', 'room', 'room_type', 'announcement', 'staff_password_reset_request'])
                ->whereNotIn('action', \App\Support\Activity::AUTH_ACTIONS),
            default => null,
        };
    }

    /** Cached briefly (keyed by every filter) and shared by the screen and the PDF. */
    private function reportData(array $filters): array
    {
        return Cache::remember('admin_report:v4:' . ReportFilters::cacheKey($filters), now()->addSeconds(60),
            fn () => $this->reports->build($filters['from'], $filters['to'], $filters['room_type_id'], $filters['payment_method'], HotelReportService::REVENUE_ALL));
    }
}
