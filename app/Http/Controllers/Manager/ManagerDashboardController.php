<?php

namespace App\Http\Controllers\Manager;

use App\Http\Controllers\Controller;
use App\Services\DashboardStatsService;
use Illuminate\View\View;

class ManagerDashboardController extends Controller
{
    public function __construct(private DashboardStatsService $stats)
    {
    }

    /**
     * Display the manager dashboard.
     */
    public function index(): View
    {
        return view('manager.dashboard', $this->stats->managerStats());
    }
}
