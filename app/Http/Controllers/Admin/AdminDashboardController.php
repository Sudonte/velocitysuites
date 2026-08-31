<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Services\DashboardStatsService;
use Illuminate\View\View;

class AdminDashboardController extends Controller
{
    public function __construct(private DashboardStatsService $stats)
    {
    }

    /**
     * Display the admin dashboard.
     */
    public function index(): View
    {
        // No cap - per-admin-user notifications are naturally small, so
        // "Expand" can show every one of this admin's own notifications.
        $systemNotifications = auth()->user()->notifications()->latest()->get();

        return view('admin.dashboard', array_merge(
            $this->stats->adminStats(),
            compact('systemNotifications')
        ));
    }
}
