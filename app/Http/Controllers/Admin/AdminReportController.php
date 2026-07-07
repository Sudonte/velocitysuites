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
     * Display the admin reports dashboard.
     */
    public function index(Request $request): View
    {
        // Activity logs (newest first, paginated)
        $activityLogs = ActivityLog::with('user')
            ->latest()
            ->paginate(20);

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

        // Room summary
        $roomReports = [
            'total' => Room::count(),
            'available' => Room::where('status', 'available')->count(),
            'occupied' => Room::where('status', 'occupied')->count(),
            'reserved' => Room::where('status', 'reserved')->count(),
            'maintenance' => Room::where('status', 'maintenance')->count(),
        ];

        // Revenue summary (from completed payments)
        $revenue = Payment::where('payment_status', 'completed')->sum('amount_paid');

        // Recent reservations count for stats
        $reservationsCount = Reservation::count();

        return view('admin.reports.index', compact(
            'activityLogs',
            'loginLogs',
            'userReports',
            'roomReports',
            'revenue',
            'reservationsCount'
        ));
    }
}