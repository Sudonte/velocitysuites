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
        return view('admin.dashboard', $this->stats->adminStats());
    }
}
