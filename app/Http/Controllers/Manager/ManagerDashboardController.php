<?php

namespace App\Http\Controllers\Manager;

use App\Http\Controllers\Controller;
use App\Models\Payment;
use App\Models\Reservation;
use App\Models\Room;
use Illuminate\View\View;

class ManagerDashboardController extends Controller
{
    /**
     * Display the manager dashboard.
     */
    public function index(): View
    {
        $totalRooms = Room::count();
        $occupiedRooms = Room::where('status', 'occupied')->count();
        $reservedRooms = Room::where('status', 'reserved')->count();
        $availableRooms = Room::where('status', 'available')->count();
        $occupancyRate = $totalRooms > 0
            ? round((($occupiedRooms + $reservedRooms) / $totalRooms) * 100, 1)
            : 0;

        $todayCheckIns = Reservation::whereDate('check_in', today())
            ->where('status', 'confirmed')
            ->count();

        $todayCheckOuts = Reservation::whereDate('check_out', today())
            ->where('status', 'checked_in')
            ->count();

        $inHouseGuests = Reservation::where('status', 'checked_in')->count();

        $totalReservations = Reservation::count();
        $totalBookings = Reservation::whereHas('booking')->count();
        $pendingPaymentVerifications = Payment::where('payment_status', 'pending')->count();

        $monthlyRevenue = (float) Payment::where('payment_status', 'completed')
            ->whereMonth('payment_date', now()->month)
            ->whereYear('payment_date', now()->year)
            ->sum('amount_paid');

        $recentReservations = Reservation::with(['guest.user', 'room'])
            ->latest()
            ->limit(8)
            ->get();

        $topRoomTypes = Room::withCount('reservations')
            ->orderByDesc('reservations_count')
            ->limit(5)
            ->get();

        return view('manager.dashboard', compact(
            'totalRooms',
            'availableRooms',
            'occupiedRooms',
            'reservedRooms',
            'occupancyRate',
            'todayCheckIns',
            'todayCheckOuts',
            'inHouseGuests',
            'totalReservations',
            'totalBookings',
            'pendingPaymentVerifications',
            'monthlyRevenue',
            'recentReservations',
            'topRoomTypes'
        ));
    }
}
