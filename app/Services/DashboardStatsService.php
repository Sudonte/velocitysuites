<?php

namespace App\Services;

use App\Models\ActivityLog;
use App\Models\Booking;
use App\Models\Payment;
use App\Models\Promotion;
use App\Models\Reservation;
use App\Models\Room;
use App\Models\User;

/**
 * Central place for dashboard statistics so counts stay connected to the
 * actual Reservation/Booking status model as it evolves, instead of being
 * re-derived (and drifting) inline in each dashboard controller.
 */
class DashboardStatsService
{
    public function adminStats(): array
    {
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

        // "Active" and "completed" live on Booking (the operational record
        // from conversion onward) - Reservation's own status only covers
        // the pre-booking lifecycle.
        $pendingReservations = Reservation::whereIn('status', ['pending_review', 'ready_for_booking'])->count();
        $activeReservations = Booking::whereIn('booking_status', ['confirmed', 'checked_in'])->count();
        $completedReservations = Booking::where('booking_status', 'checked_out')->count();

        $totalBookings = Reservation::whereHas('booking')->count();
        $pendingPaymentVerifications = Payment::where('payment_status', 'pending')->count();

        return [
            // User stats
            'totalUsers' => User::count(),
            'activeUsers' => User::where('status', 'active')->count(),
            'suspendedUsers' => User::where('status', 'suspended')->count(),
            'totalGuests' => User::where('role', 'guest')->count(),
            'totalReceptionists' => User::where('role', 'receptionist')->count(),
            'totalManagers' => User::where('role', 'manager')->count(),

            // Room stats - Total Rooms must count every room regardless of
            // status (previously undercounted by omitting maintenance).
            // "Reserved" is no longer a state any code path writes (room
            // assignment now only happens at check-in, straight to
            // "occupied"), so Maintenance replaces it as the fourth card.
            'totalRooms' => Room::count(),
            'availableRooms' => Room::where('status', 'available')->count(),
            'occupiedRooms' => Room::where('status', 'occupied')->count(),
            'maintenanceRooms' => Room::where('status', 'maintenance')->count(),

            // Revenue
            'todayRevenue' => $todayRevenue,
            'monthlyRevenue' => $monthlyRevenue,
            'yearlyRevenue' => $yearlyRevenue,

            // Reservation stats
            'totalReservations' => Reservation::count(),
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
            'recentReservations' => Reservation::with(['guest.user', 'roomType', 'booking'])
                ->latest()
                ->limit(5)
                ->get(),
        ];
    }

    public function managerStats(): array
    {
        $totalRooms = Room::count();
        $occupiedRooms = Room::where('status', 'occupied')->count();
        $availableRooms = Room::where('status', 'available')->count();
        $maintenanceRooms = Room::where('status', 'maintenance')->count();
        $occupancyRate = $totalRooms > 0
            ? round(($occupiedRooms / $totalRooms) * 100, 1)
            : 0;

        // Check-in/check-out/in-house state lives on Booking (the
        // operational record from conversion onward), not on Reservation's
        // own status.
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

        return [
            'totalRooms' => $totalRooms,
            'availableRooms' => $availableRooms,
            'occupiedRooms' => $occupiedRooms,
            'maintenanceRooms' => $maintenanceRooms,
            'occupancyRate' => $occupancyRate,
            'todayCheckIns' => $todayCheckIns,
            'todayCheckOuts' => $todayCheckOuts,
            'inHouseGuests' => $inHouseGuests,
            'totalReservations' => Reservation::count(),
            'totalBookings' => Reservation::whereHas('booking')->count(),
            'pendingPaymentVerifications' => Payment::where('payment_status', 'pending')->count(),
            'monthlyRevenue' => $monthlyRevenue,
            'recentReservations' => Reservation::with(['guest.user', 'roomType', 'booking.room'])
                ->latest()
                ->limit(8)
                ->get(),
            // Ranked by actual assigned-at-check-in bookings, not the
            // deprecated reservations.room_id relation.
            'topRoomTypes' => Room::withCount('bookings')
                ->orderByDesc('bookings_count')
                ->limit(5)
                ->get(),
        ];
    }
}
