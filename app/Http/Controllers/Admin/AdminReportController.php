<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\ActivityLog;
use App\Models\Booking;
use App\Models\Payment;
use App\Models\Reservation;
use App\Models\Room;
use App\Models\User;
use App\Services\DashboardStatsService;
use App\Support\TestAccountScope;
use Barryvdh\DomPDF\Facade\Pdf;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\View\View;

class AdminReportController extends Controller
{
    public function __construct(private DashboardStatsService $stats)
    {
    }

    /**
     * Display the admin reports dashboard. Accepts an optional start_date/
     * end_date GET filter (validated, order-corrected if reversed) that
     * scopes only the inherently time-based figures - activity logs,
     * revenue, reservations, bookings. User/room counts and Recent Logins
     * stay as live "right now" snapshots regardless of the filter, since
     * "how many rooms are currently available" isn't a historical question.
     */
    public function index(Request $request): View
    {
        [$startDate, $endDate] = $this->resolveDateFilter($request);

        // Activity logs (newest first, paginated) - simplePaginate
        // (Previous/Next only, no numbered page-link boxes): the numbered
        // links render broken/oversized here for reasons that don't trace
        // back to anything in this app's own CSS, same fix already applied
        // everywhere else in the app.
        $activityLogs = ActivityLog::with('user')
            ->when($startDate, fn ($q) => $q->where('created_at', '>=', $startDate))
            ->when($endDate, fn ($q) => $q->where('created_at', '<=', $endDate))
            ->latest()
            ->simplePaginate(20)
            ->withQueryString();

        $data = $this->reportData($startDate, $endDate);

        return view('admin.reports.index', compact('activityLogs') + $data + [
            'startDateInput' => $startDate?->toDateString(),
            'endDateInput' => $endDate?->toDateString(),
            'isFiltered' => (bool) ($startDate || $endDate),
        ]);
    }

    /**
     * A real, formal PDF document (dompdf, same library already used for
     * Guest reservation/payment exports) - the "Print Report" button
     * previously just called window.print() on this dashboard's live HTML,
     * which produced a screenshot-like printout of stat cards/icons rather
     * than an actual report. This renders a dedicated tabular layout
     * (admin.reports.export-pdf) instead, branded with the Velocity Suites
     * logo/name, built from the exact same figures as the on-screen report.
     */
    public function exportPdf(Request $request)
    {
        [$startDate, $endDate] = $this->resolveDateFilter($request);

        $data = $this->reportData($startDate, $endDate);
        $periodLabel = $startDate || $endDate
            ? ($startDate?->format('M d, Y') ?? 'the beginning') . ' - ' . ($endDate?->format('M d, Y') ?? 'today')
            : null;

        $pdf = Pdf::loadView('admin.reports.export-pdf', $data + [
            'periodLabel' => $periodLabel,
            'generatedAt' => now(),
            'monthlyBreakdown' => $this->stats->monthlyReservationBreakdown(),
        ])->setPaper('a4', 'portrait');

        return $pdf->download('Velocity-Suites-Admin-Report_' . now()->format('Y-m-d_His') . '.pdf');
    }

    /**
     * @return array{0: ?Carbon, 1: ?Carbon}
     */
    private function resolveDateFilter(Request $request): array
    {
        $request->validate([
            'start_date' => ['nullable', 'date'],
            'end_date' => ['nullable', 'date'],
        ]);

        $startDate = $request->filled('start_date') ? Carbon::parse($request->input('start_date'))->startOfDay() : null;
        $endDate = $request->filled('end_date') ? Carbon::parse($request->input('end_date'))->endOfDay() : null;
        if ($startDate && $endDate && $startDate->gt($endDate)) {
            [$startDate, $endDate] = [$endDate->copy()->startOfDay(), $startDate->copy()->endOfDay()];
        }

        return [$startDate, $endDate];
    }

    /**
     * Cached (short TTL, keyed by the date filter) - everything here
     * (user/room summaries, revenue, reservation/booking counts, login
     * logs) is a dozen-plus aggregate queries; shared by both the on-screen
     * report and the PDF export so the two never disagree with each other.
     */
    private function reportData(?Carbon $startDate, ?Carbon $endDate): array
    {
        $cacheKey = 'admin_report:' . ($startDate?->toDateString() ?? 'all') . ':' . ($endDate?->toDateString() ?? 'all');

        return Cache::remember($cacheKey, now()->addSeconds(60), fn () => $this->computeReportData($startDate, $endDate));
    }

    private function computeReportData(?Carbon $startDate, ?Carbon $endDate): array
    {
        // Login-style logs: users ordered by last_login_at
        $loginLogs = User::whereNotNull('last_login_at')
            ->orderByDesc('last_login_at')
            ->limit(20)
            ->get();

        // User summary - excludes confirmed internal/test accounts (see
        // App\Support\TestAccountScope) so this reads as real business
        // headcount, not development noise.
        $userReports = [
            'total' => TestAccountScope::excludeFromUsers(User::query())->count(),
            'active' => TestAccountScope::excludeFromUsers(User::where('status', 'active'))->count(),
            'suspended' => TestAccountScope::excludeFromUsers(User::where('status', 'suspended'))->count(),
            'by_role' => TestAccountScope::excludeFromUsers(User::query())
                ->selectRaw('role, COUNT(*) as count')
                ->groupBy('role')
                ->pluck('count', 'role'),
        ];

        // Room summary - no "reserved" status anymore (room assignment only
        // ever happens at check-in, straight to "occupied" - see the same
        // fix already applied to DashboardStatsService::adminStats()).
        // Occupied/available derived from an actual CHECKED_IN booking
        // assignment, not the stored `status` column - see
        // Room::getEffectiveStatusAttribute()'s docblock for why that
        // column can drift from what's really occupied right now. A room
        // held only by a test-account booking counts as available here
        // (business-facing figure) - see TestAccountScope's own doc.
        $roomReports = [
            'total' => Room::count(),
            'available' => Room::where('status', '!=', 'maintenance')
                ->whereDoesntHave('assignedBookings', fn ($q) => TestAccountScope::excludeFromBookings(
                    $q->where('booking_status', Booking::STATUS_CHECKED_IN)
                )->whereNull('booking_rooms.checked_out_at'))
                ->count(),
            'occupied' => Room::where('status', '!=', 'maintenance')
                ->whereHas('assignedBookings', fn ($q) => TestAccountScope::excludeFromBookings(
                    $q->where('booking_status', Booking::STATUS_CHECKED_IN)
                )->whereNull('booking_rooms.checked_out_at'))
                ->count(),
            'maintenance' => Room::where('status', 'maintenance')->count(),
        ];

        // Revenue summary (from completed payments), date-range scoped when set
        $revenue = TestAccountScope::excludeFromPayments(
            Payment::where('payment_status', 'completed')
                ->when($startDate, fn ($q) => $q->where('created_at', '>=', $startDate))
                ->when($endDate, fn ($q) => $q->where('created_at', '<=', $endDate))
        )->sum('amount_paid');

        // Reservations/bookings created within the range (or all-time if unset)
        $reservationsCount = TestAccountScope::excludeFromReservations(
            Reservation::when($startDate, fn ($q) => $q->where('created_at', '>=', $startDate))
                ->when($endDate, fn ($q) => $q->where('created_at', '<=', $endDate))
        )->count();
        $bookingsCount = TestAccountScope::excludeFromReservations(
            Reservation::whereHas('booking')
                ->when($startDate, fn ($q) => $q->where('created_at', '>=', $startDate))
                ->when($endDate, fn ($q) => $q->where('created_at', '<=', $endDate))
        )->count();
        // Not test-excluded - an operational queue depth (a real payment
        // still needing staff review), not a business-performance figure.
        $pendingPaymentVerifications = Payment::where('payment_status', 'pending')->count();

        return compact(
            'loginLogs',
            'userReports',
            'roomReports',
            'revenue',
            'reservationsCount',
            'bookingsCount',
            'pendingPaymentVerifications'
        );
    }
}