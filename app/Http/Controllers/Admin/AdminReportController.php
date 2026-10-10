<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\ActivityLog;
use App\Models\Booking;
use App\Models\Payment;
use App\Models\Reservation;
use App\Models\Room;
use App\Models\User;
use App\Models\RoomType;
use App\Services\DashboardStatsService;
use App\Support\PerPage;
use App\Support\ReportFilters;
use App\Support\TestAccountScope;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\View\View;

class AdminReportController extends Controller
{
    public function __construct(private DashboardStatsService $stats)
    {
    }

    /**
     * Admin reports. Filters (App\Support\ReportFilters, default Today)
     * scope the time-based figures - activity logs, revenue (optionally by
     * payment method), reservations and bookings, the last two plus revenue
     * optionally by room type. User/room snapshots stay "right now", room
     * counts narrowed to the chosen room type.
     */
    public function index(Request $request): View
    {
        $filters = ReportFilters::resolve($request);

        // Activity logs, newest first.
        $activityLogs = ActivityLog::with('user')
            ->whereBetween('created_at', [$filters['from'], $filters['to']])
            ->latest()
            ->paginate(PerPage::resolve($request))
            ->withQueryString();

        $data = $this->reportData($filters);
        $roomTypes = RoomType::orderBy('name')->get(['id', 'name']);

        return view('admin.reports.index', compact('activityLogs', 'filters', 'roomTypes') + $data);
    }

    /**
     * The same figures as the on-screen report (same filters, same cached
     * data) as a branded dompdf document.
     */
    public function exportPdf(Request $request)
    {
        $filters = ReportFilters::resolve($request);
        $data = $this->reportData($filters);

        $periodLabel = $filters['from']->format('M d, Y') . ' - ' . $filters['to']->format('M d, Y');
        if ($filters['room_type_id']) {
            $periodLabel .= ' | ' . RoomType::whereKey($filters['room_type_id'])->value('name');
        }
        if ($filters['payment_method']) {
            $periodLabel .= ' | ' . ($filters['payment_method'] === 'gcash' ? 'GCash' : 'Cash') . ' payments';
        }

        $pdf = Pdf::loadView('admin.reports.export-pdf', $data + [
            'periodLabel' => $periodLabel,
            'generatedAt' => now(),
            'monthlyBreakdown' => $this->stats->monthlyReservationBreakdown(),
        ])->setPaper('a4', 'portrait');

        return $pdf->download('Velocity-Suites-Admin-Report_' . now()->format('Y-m-d_His') . '.pdf');
    }

    /**
     * Cached (short TTL, keyed by every filter) and shared by the screen
     * and the PDF so the two never disagree.
     */
    private function reportData(array $filters): array
    {
        return Cache::remember('admin_report:v2:' . ReportFilters::cacheKey($filters), now()->addSeconds(60), fn () => $this->computeReportData($filters));
    }

    private function computeReportData(array $filters): array
    {
        ['from' => $from, 'to' => $to, 'room_type_id' => $roomTypeId, 'payment_method' => $paymentMethod] = $filters;

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
            'total' => Room::notArchived()->when($roomTypeId, fn ($q) => $q->where('room_type_id', $roomTypeId))->count(),
            'available' => Room::notArchived()->when($roomTypeId, fn ($q) => $q->where('room_type_id', $roomTypeId))->where('status', '!=', 'maintenance')
                ->whereDoesntHave('assignedBookings', fn ($q) => TestAccountScope::excludeFromBookings(
                    $q->where('booking_status', Booking::STATUS_CHECKED_IN)
                )->whereNull('booking_rooms.checked_out_at'))
                ->count(),
            'occupied' => Room::notArchived()->when($roomTypeId, fn ($q) => $q->where('room_type_id', $roomTypeId))->where('status', '!=', 'maintenance')
                ->whereHas('assignedBookings', fn ($q) => TestAccountScope::excludeFromBookings(
                    $q->where('booking_status', Booking::STATUS_CHECKED_IN)
                )->whereNull('booking_rooms.checked_out_at'))
                ->count(),
            'maintenance' => Room::notArchived()->when($roomTypeId, fn ($q) => $q->where('room_type_id', $roomTypeId))->where('status', 'maintenance')->count(),
        ];

        // Revenue: completed payments in range, optionally one payment
        // method and/or payments for one room type.
        $revenue = TestAccountScope::excludeFromPayments(
            Payment::countedAsRevenue()
                ->whereBetween('created_at', [$from, $to])
                ->when($paymentMethod, fn ($q) => $q->where('payment_method', $paymentMethod))
                ->when($roomTypeId, fn ($q) => $q->where(function ($w) use ($roomTypeId) {
                    $w->whereHas('reservation', fn ($r) => $r->where('room_type_id', $roomTypeId))
                      ->orWhereHas('booking', fn ($b) => $b->where('room_type_id', $roomTypeId))
                      ->orWhereHas('billing.booking', fn ($b) => $b->where('room_type_id', $roomTypeId));
                }))
        )->sum('amount_paid');

        // Created in range. "Bookings" are direct bookings (not converted
        // reservations), the same definition as the dashboard's Bookings card.
        $reservationsCount = TestAccountScope::excludeFromReservations(
            Reservation::whereBetween('created_at', [$from, $to])
                ->when($roomTypeId, fn ($q) => $q->where('room_type_id', $roomTypeId))
        )->count();
        $bookingsCount = TestAccountScope::excludeFromBookings(
            Booking::whereNull('reservation_id')
                ->whereBetween('created_at', [$from, $to])
                ->when($roomTypeId, fn ($q) => $q->where('room_type_id', $roomTypeId))
        )->count();
        $pendingPaymentVerifications = $this->stats->pendingPaymentVerificationCount();

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