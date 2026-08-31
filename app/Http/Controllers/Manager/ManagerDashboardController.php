<?php

namespace App\Http\Controllers\Manager;

use App\Http\Controllers\Controller;
use App\Services\DashboardStatsService;
use App\Support\DateRange;
use Illuminate\Http\Request;
use Illuminate\View\View;

class ManagerDashboardController extends Controller
{
    public function __construct(private DashboardStatsService $stats)
    {
    }

    /**
     * Display the manager dashboard. Every period-dependent figure is
     * scoped by the ?period=daily|weekly|monthly|custom (+?from=&to= for
     * custom) filter - see App\Support\DateRange and
     * DashboardStatsService::managerStats() for exactly which figures
     * that covers vs. which stay always-current.
     */
    public function index(Request $request): View
    {
        [$from, $to, $period] = DateRange::resolve($request);

        return view('manager.dashboard', array_merge(
            $this->stats->managerStats($from, $to),
            [
                'periodFrom' => $from,
                'periodTo' => $to,
                'period' => $period,
            ]
        ));
    }
}
