<?php

namespace App\Http\Controllers\Manager;

use App\Http\Controllers\Controller;
use App\Models\Booking;
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

        // Check-in/check-out/in-house state now lives on Booking (the
        // operational record from conversion onward), not on Reservation's
        // own status. Full dashboard redesign against the new model is a
        // later phase; these counts are patched now for correctness.
        $todayCheckIns = Booking::whereDate('check_in', today())
            ->where('booking_status', 'confirmed')
            ->count();

        $todayCheckOuts = Booking::whereDate('check_out', today())
            ->where('booking_status', 'checked_in')
            ->count();

        $inHouseGuests = Booking::where('booking_status', 'checked_in')->count();

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
            'monthlyRevenue',
            'recentReservations',
            'topRoomTypes'
        ));
    }
}
