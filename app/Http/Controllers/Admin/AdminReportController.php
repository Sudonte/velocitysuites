<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\ActivityLog;
use App\Models\RoomType;
use App\Services\HotelReportService;
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

        $activityLogs = ActivityLog::with('user')
            ->whereBetween('created_at', [$filters['from'], $filters['to']])
            ->latest()
            ->paginate(PerPage::resolve($request))
            ->withQueryString();

        return view('admin.reports.index', [
            'activityLogs' => $activityLogs,
            'filters' => $filters,
            'roomTypes' => RoomType::orderBy('name')->get(['id', 'name']),
            'report' => $this->reportData($filters),
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

    /** Daily Guest Arrival (DAE-1A) for ?arrival_date= (default today) as a PDF [REQ065]. */
    public function arrivalsPdf(Request $request)
    {
        $day = $this->arrivalDate($request);

        return Pdf::loadView('reports.arrivals-pdf', [
            'arrivals' => $this->reports->dailyArrivals($day),
            'arrivalDate' => $day,
            'generatedAt' => now(),
        ])->setPaper('a4', 'portrait')->download('Velocity-Suites-Daily-Arrivals_' . $day->toDateString() . '.pdf');
    }

    private function arrivalDate(Request $request): \Carbon\Carbon
    {
        $raw = (string) $request->get('arrival_date', '');

        return preg_match('/^\d{4}-\d{2}-\d{2}$/', $raw) && strtotime($raw) ? \Carbon\Carbon::parse($raw)->startOfDay() : today();
    }

    /** Cached briefly (keyed by every filter) and shared by the screen and the PDF. */
    private function reportData(array $filters): array
    {
        return Cache::remember('admin_report:v4:' . ReportFilters::cacheKey($filters), now()->addSeconds(60),
            fn () => $this->reports->build($filters['from'], $filters['to'], $filters['room_type_id'], $filters['payment_method'], HotelReportService::REVENUE_ALL));
    }
}
