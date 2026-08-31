<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\ActivityLog;
use App\Models\Payment;
use App\Models\Reservation;
use App\Models\Room;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\View\View;

class AdminReportController extends Controller
{
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
        $request->validate([
            'start_date' => ['nullable', 'date'],
            'end_date' => ['nullable', 'date'],
        ]);

        $startDate = $request->filled('start_date') ? \Carbon\Carbon::parse($request->input('start_date'))->startOfDay() : null;
        $endDate = $request->filled('end_date') ? \Carbon\Carbon::parse($request->input('end_date'))->endOfDay() : null;
        if ($startDate && $endDate && $startDate->gt($endDate)) {
            [$startDate, $endDate] = [$endDate->copy()->startOfDay(), $startDate->copy()->endOfDay()];
        }

        // Activity logs (newest first, paginated)
        $activityLogs = ActivityLog::with('user')
            ->when($startDate, fn ($q) => $q->where('created_at', '>=', $startDate))
            ->when($endDate, fn ($q) => $q->where('created_at', '<=', $endDate))
            ->latest()
            ->paginate(20)
            ->withQueryString();

        // Login-style logs: users ordered by last_login_at
        $loginLogs = User::whereNotNull('last_login_at')
            ->orderByDesc('last_login_at')
            ->limit(20)
            ->get();

        // User summary
        $userReports = [
            'total' => User::count(),
            'active' => User::where('status', 'active')->count(),
            'suspended' => User::where('status', 'suspended')->count(),
            'by_role' => User::selectRaw('role, COUNT(*) as count')
                ->groupBy('role')
                ->pluck('count', 'role'),
        ];

        // Room summary - no "reserved" status anymore (room assignment only
        // ever happens at check-in, straight to "occupied" - see the same
        // fix already applied to DashboardStatsService::adminStats()).
        $roomReports = [
            'total' => Room::count(),
            'available' => Room::where('status', 'available')->count(),
            'occupied' => Room::where('status', 'occupied')->count(),
            'maintenance' => Room::where('status', 'maintenance')->count(),
        ];

        // Revenue summary (from completed payments), date-range scoped when set
        $revenue = Payment::where('payment_status', 'completed')
            ->when($startDate, fn ($q) => $q->where('created_at', '>=', $startDate))
            ->when($endDate, fn ($q) => $q->where('created_at', '<=', $endDate))
            ->sum('amount_paid');

        // Reservations/bookings created within the range (or all-time if unset)
        $reservationsCount = Reservation::when($startDate, fn ($q) => $q->where('created_at', '>=', $startDate))
            ->when($endDate, fn ($q) => $q->where('created_at', '<=', $endDate))
            ->count();
        $bookingsCount = Reservation::whereHas('booking')
            ->when($startDate, fn ($q) => $q->where('created_at', '>=', $startDate))
            ->when($endDate, fn ($q) => $q->where('created_at', '<=', $endDate))
            ->count();
        $pendingPaymentVerifications = Payment::where('payment_status', 'pending')->count();

        return view('admin.reports.index', compact(
            'activityLogs',
            'loginLogs',
            'userReports',
            'roomReports',
            'revenue',
            'reservationsCount',
            'bookingsCount',
            'pendingPaymentVerifications'
        ) + [
            'startDateInput' => $startDate?->toDateString(),
            'endDateInput' => $endDate?->toDateString(),
            'isFiltered' => (bool) ($startDate || $endDate),
        ]);
    }
}