<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\ActivityLog;
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

        // Reservation statistics
        $totalReservations = Reservation::count();
        $pendingReservations = Reservation::where('status', 'pending')->count();
        $activeReservations = Reservation::whereIn('status', ['confirmed', 'checked_in'])->count();
        $completedReservations = Reservation::where('status', 'checked_out')->count();

        $totalBookings = Reservation::whereHas('booking')->count();
        $pendingPaymentVerifications = Payment::where('payment_status', 'pending')->count();

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

            // Booking / payment stats
            'totalBookings' => $totalBookings,
            'pendingPaymentVerifications' => $pendingPaymentVerifications,

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
