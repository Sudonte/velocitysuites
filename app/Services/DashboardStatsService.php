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
        $yesterdayRevenue = (float) Payment::where('payment_status', 'completed')
            ->whereDate('payment_date', today()->subDay())
            ->sum('amount_paid');

        $monthlyRevenue = (float) Payment::where('payment_status', 'completed')
            ->whereMonth('payment_date', now()->month)
            ->whereYear('payment_date', now()->year)
            ->sum('amount_paid');
        $lastMonthRevenue = (float) Payment::where('payment_status', 'completed')
            ->whereMonth('payment_date', now()->subMonth()->month)
            ->whereYear('payment_date', now()->subMonth()->year)
            ->sum('amount_paid');

        $yearlyRevenue = (float) Payment::where('payment_status', 'completed')
            ->whereYear('payment_date', now()->year)
            ->sum('amount_paid');
        $lastYearRevenue = (float) Payment::where('payment_status', 'completed')
            ->whereYear('payment_date', now()->subYear()->year)
            ->sum('amount_paid');

        // "Active" and "completed" live on Booking (the operational record
        // from conversion onward) - Reservation's own status only covers
        // the pre-booking lifecycle.
        $pendingReservations = Reservation::whereIn('status', ['pending_review', 'ready_for_booking'])->count();
        $activeReservations = Booking::whereIn('booking_status', ['confirmed', 'checked_in'])->count();
        $completedReservations = Booking::where('booking_status', 'checked_out')->count();

        $totalReservations = Reservation::count();
        $totalReservationsLastMonth = Reservation::where('created_at', '<=', now()->subMonth())->count();

        $totalBookings = Reservation::whereHas('booking')->count();
        $totalBookingsLastMonth = Reservation::whereHas('booking', fn ($q) => $q->where('confirmed_at', '<=', now()->subMonth()))->count();

        $pendingPaymentVerifications = Payment::where('payment_status', 'pending')->count();

        $totalUsers = User::count();
        $totalUsersLastMonth = User::where('created_at', '<=', now()->subMonth())->count();

        $totalRooms = Room::count();
        $totalRoomsLastMonth = Room::where('created_at', '<=', now()->subMonth())->count();

        return [
            // User stats - only Total Users gets a growth badge; Active/
            // Suspended are current status snapshots, not a running count,
            // so a "vs last month" comparison wouldn't reflect anything
            // real (a user can flip between them any day).
            'totalUsers' => $totalUsers,
            'totalUsersChange' => $this->percentChange($totalUsers, $totalUsersLastMonth),
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
            // Available/Occupied/Maintenance change by the hour as guests
            // check in and out, so (like Active/Suspended Users) they don't
            // get a growth badge - only Total Rooms (actual inventory
            // added) does.
            'totalRooms' => $totalRooms,
            'totalRoomsChange' => $this->percentChange($totalRooms, $totalRoomsLastMonth),
            'availableRooms' => Room::where('status', 'available')->count(),
            'occupiedRooms' => Room::where('status', 'occupied')->count(),
            'maintenanceRooms' => Room::where('status', 'maintenance')->count(),

            // Revenue - each compared against the equivalent prior period.
            'todayRevenue' => $todayRevenue,
            'todayRevenueChange' => $this->percentChange($todayRevenue, $yesterdayRevenue),
            'monthlyRevenue' => $monthlyRevenue,
            'monthlyRevenueChange' => $this->percentChange($monthlyRevenue, $lastMonthRevenue),
            'yearlyRevenue' => $yearlyRevenue,
            'yearlyRevenueChange' => $this->percentChange($yearlyRevenue, $lastYearRevenue),

            // Reservation stats - Total Reservations/Bookings are running
            // counts (only grow), so they get a growth badge; Pending/
            // Active/Completed/Pending Verifications are queue depths that
            // rise and fall daily, not meaningful to compare to a month ago.
            'totalReservations' => $totalReservations,
            'totalReservationsChange' => $this->percentChange($totalReservations, $totalReservationsLastMonth),
            'pendingReservations' => $pendingReservations,
            'activeReservations' => $activeReservations,
            'completedReservations' => $completedReservations,

            // Booking / payment stats
            'totalBookings' => $totalBookings,
            'totalBookingsChange' => $this->percentChange($totalBookings, $totalBookingsLastMonth),
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

            // Last-7-days trend lines for the overview charts.
            'usersTrend' => $this->dailySeries(fn ($date) => User::whereDate('created_at', '<=', $date)->count()),
            'reservationsTrend' => $this->dailySeries(fn ($date) => Reservation::whereDate('created_at', $date)->count()),
            'revenueTrend' => $this->dailySeries(fn ($date) => (float) Payment::where('payment_status', 'completed')->whereDate('payment_date', $date)->sum('amount_paid')),
        ];
    }

    /**
     * Percent change from $previous to $current, guarding the zero-baseline
     * case (nothing to compare against yet, e.g. a brand-new hotel).
     */
    private function percentChange(float $current, float $previous): float
    {
        if ($previous <= 0) {
            return $current > 0 ? 100.0 : 0.0;
        }

        return round((($current - $previous) / $previous) * 100, 1);
    }

    /**
     * One value per day for the last 7 days (oldest first), labeled with
     * short weekday-friendly dates for the trend charts.
     */
    private function dailySeries(callable $valueForDate): array
    {
        $labels = [];
        $values = [];

        for ($i = 6; $i >= 0; $i--) {
            $date = today()->subDays($i);
            $labels[] = $date->format('M d');
            $values[] = $valueForDate($date);
        }

        return ['labels' => $labels, 'values' => $values];
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
