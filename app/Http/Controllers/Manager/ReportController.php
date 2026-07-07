<?php

namespace App\Http\Controllers\Manager;

use App\Http\Controllers\Controller;
use App\Models\Payment;
use App\Models\Reservation;
use App\Models\Room;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;

class ReportController extends Controller
{
    /**
     * Display reports.
     */
    public function index(Request $request): View
    {
        $from = $request->has('from') && $request->from
            ? Carbon::parse($request->from)->startOfDay()
            : Carbon::now()->startOfMonth();
        $to = $request->has('to') && $request->to
            ? Carbon::parse($request->to)->endOfDay()
            : Carbon::now()->endOfDay();

        // Revenue by day
        $revenueByDay = Payment::where('payment_status', 'completed')
            ->whereBetween('payment_date', [$from, $to])
            ->selectRaw('DATE(payment_date) as day, SUM(amount_paid) as total')
            ->groupBy('day')
            ->orderBy('day')
            ->get();

        $totalRevenue = (float) $revenueByDay->sum('total');
        $totalReservations = Reservation::whereBetween('check_in', [$from, $to])->count();
        $averageStay = (float) Reservation::whereBetween('check_in', [$from, $to])
            ->selectRaw('AVG(DATEDIFF(check_out, check_in)) as avg_nights')
            ->value('avg_nights');

        // Top room types
        $topRoomTypes = Room::withCount(['reservations' => function ($q) use ($from, $to) {
            $q->whereBetween('check_in', [$from, $to]);
        }])
            ->orderByDesc('reservations_count')
            ->limit(5)
            ->get();

        // Top guests (by reservation count in range)
        $topGuests = Reservation::select('guest_id', DB::raw('COUNT(*) as reservation_count'))
            ->with('guest.user')
            ->whereBetween('check_in', [$from, $to])
            ->groupBy('guest_id')
            ->orderByDesc('reservation_count')
            ->limit(5)
            ->get();

        return view('manager.reports.index', compact(
            'from',
            'to',
            'revenueByDay',
            'totalRevenue',
            'totalReservations',
            'averageStay',
            'topRoomTypes',
            'topGuests'
        ));
    }
}
