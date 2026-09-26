<?php

namespace App\Http\Controllers\Manager;

use App\Http\Controllers\Controller;
use App\Models\Payment;
use App\Models\Reservation;
use App\Models\RoomType;
use App\Support\TestAccountScope;
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

        // Revenue by day - excludes confirmed internal/test accounts (see
        // App\Support\TestAccountScope) so this reads as real business
        // performance, not development noise.
        $revenueByDay = TestAccountScope::excludeFromPayments(
            Payment::where('payment_status', 'completed')->whereBetween('payment_date', [$from, $to])
        )
            ->selectRaw('DATE(payment_date) as day, SUM(amount_paid) as total')
            ->groupBy('day')
            ->orderBy('day')
            ->get();

        $totalRevenue = (float) $revenueByDay->sum('total');
        $totalReservations = TestAccountScope::excludeFromReservations(
            Reservation::whereBetween('check_in', [$from, $to])
        )->count();
        $totalBookings = TestAccountScope::excludeFromReservations(
            Reservation::whereBetween('check_in', [$from, $to])->whereHas('booking')
        )->count();
        $averageStay = (float) (TestAccountScope::excludeFromReservations(
            Reservation::whereBetween('check_in', [$from, $to])
        )->selectRaw('AVG(DATEDIFF(check_out, check_in)) as avg_nights')->value('avg_nights') ?? 0);

        // Top room types - counted via RoomType's own reservations() relation
        // (Reservation.room_type_id, set at request time), not Room's - a
        // room is only ever assigned to a specific reservation at check-in,
        // so Room::reservations() no longer gets populated and always
        // returned zero here.
        $topRoomTypes = RoomType::withCount(['reservations' => function ($q) use ($from, $to) {
            TestAccountScope::excludeFromReservations($q->whereBetween('check_in', [$from, $to]));
        }])
            ->orderByDesc('reservations_count')
            ->limit(5)
            ->get();

        // Top guests (by reservation count in range) - guest_id is
        // nullable (a receptionist-created walk-in reservation has no
        // Guest account at all), and grouping by it would otherwise
        // collapse every accountless walk-in in the range into one NULL
        // bucket that could outrank, or displace, real repeat guests.
        // Also excludes confirmed test accounts - otherwise the project's
        // own repeated development testing would always rank as the
        // "top guest" ahead of every real repeat customer.
        $topGuests = TestAccountScope::excludeFromReservations(
            Reservation::select('guest_id', DB::raw('COUNT(*) as reservation_count'))
                ->with('guest.user')
                ->whereNotNull('guest_id')
                ->whereBetween('check_in', [$from, $to])
        )
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
            'totalBookings',
            'averageStay',
            'topRoomTypes',
            'topGuests'
        ));
    }
}
