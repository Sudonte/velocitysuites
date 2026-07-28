<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\ActivityLog;
use App\Models\Booking;
use App\Models\Payment;
use App\Models\Promotion;
use App\Models\Reservation;
use App\Models\Room;
use App\Models\User;
use Illuminate\View\View;

class AdminDashboardController extends Controller
{
    /**
     * Display the admin dashboard.
     */
    public function index(): View
    {
        // Revenue statistics
        $todayRevenue = (float) Payment::where('payment_status', 'completed')
            ->whereDate('payment_date', today())
            ->sum('amount_paid');

        $monthlyRevenue = (float) Payment::where('payment_status', 'completed')
            ->whereMonth('payment_date', now()->month)
            ->whereYear('payment_date', now()->year)
            ->sum('amount_paid');

        $yearlyRevenue = (float) Payment::where('payment_status', 'completed')
            ->whereYear('payment_date', now()->year)
            ->sum('amount_paid');

        // Reservation statistics - "active" and "completed" now live on
        // Booking (the operational record from conversion onward), not on
        // Reservation's own status (which only covers the pre-booking
        // lifecycle). Full dashboard redesign against the new model is a
        // later phase; these counts are patched now so they aren't simply
        // wrong on the live site in the meantime.
        $totalReservations = Reservation::count();
        $pendingReservations = Reservation::whereIn('status', ['pending_review', 'ready_for_booking'])->count();
        $activeReservations = Booking::whereIn('booking_status', ['confirmed', 'checked_in'])->count();
        $completedReservations = Booking::where('booking_status', 'checked_out')->count();

        $data = [
            // User stats
            'totalUsers' => User::count(),
            'activeUsers' => User::where('status', 'active')->count(),
            'suspendedUsers' => User::where('status', 'suspended')->count(),
            'totalGuests' => User::where('role', 'guest')->count(),
            'totalReceptionists' => User::where('role', 'receptionist')->count(),
            'totalManagers' => User::where('role', 'manager')->count(),

            // Room stats
            'totalRooms' => Room::count(),
            'availableRooms' => Room::where('status', 'available')->count(),
            'occupiedRooms' => Room::where('status', 'occupied')->count(),
            'reservedRooms' => Room::where('status', 'reserved')->count(),

            // Revenue
            'todayRevenue' => $todayRevenue,
            'monthlyRevenue' => $monthlyRevenue,
            'yearlyRevenue' => $yearlyRevenue,

            // Reservation stats
            'totalReservations' => $totalReservations,
            'pendingReservations' => $pendingReservations,
            'activeReservations' => $activeReservations,
            'completedReservations' => $completedReservations,

            // Other
            'activePromotions' => Promotion::where('status', 'active')->count(),
            'recentActivities' => ActivityLog::with('user')
                ->latest()
                ->limit(10)
                ->get(),
            'recentReservations' => Reservation::with(['guest', 'room'])
                ->latest()
                ->limit(5)
                ->get(),
        ];

        return view('admin.dashboard', $data);
    }
}
