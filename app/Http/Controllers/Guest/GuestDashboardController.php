<?php

namespace App\Http\Controllers\Guest;

use App\Http\Controllers\Controller;
use App\Models\Billing;
use App\Models\Payment;
use App\Models\Promotion;
use App\Models\Reservation;
use Illuminate\View\View;

class GuestDashboardController extends Controller
{
    /**
     * Display guest dashboard - enhanced for guest experience.
     * Guests browse rooms via public routes (/rooms), not separate guest routes.
     */
    public function index(): View
    {
        $user = auth()->user();
        $guest = $user->guest;

        // Get current active reservation (checked in)
        $currentReservation = $guest->reservations()
            ->where('status', 'checked_in')
            ->with(['room', 'booking.billing'])
            ->first();

        // Get upcoming reservations (confirmed)
        $upcomingReservations = $guest->reservations()
            ->whereIn('status', ['confirmed', 'pending'])
            ->with(['room', 'booking'])
            ->orderBy('check_in')
            ->get();

        // Get past reservations (checked out, cancelled)
        $pastReservations = $guest->reservations()
            ->whereIn('status', ['checked_out', 'cancelled'])
            ->with(['room', 'booking.billing.payments'])
            ->latest('check_out')
            ->limit(10)
            ->get();

        // Get pending payments (billing status pending or partial)
        $pendingPayments = Billing::whereHas('booking', function ($query) use ($guest) {
            $query->whereHas('reservation', function ($q) use ($guest) {
                $q->where('guest_id', $guest->id);
            });
        })
        ->whereIn('billing_status', ['pending', 'partial'])
        ->with(['booking.reservation.room'])
        ->get();

        // Get recent payments
        $recentPayments = Payment::whereHas('billing.booking.reservation', function ($query) use ($guest) {
            $query->where('guest_id', $guest->id);
        })
        ->with(['billing.booking.reservation.room'])
        ->latest('payment_date')
        ->limit(5)
        ->get();

        // Unread notifications count
        $unreadNotifications = $user->notifications()
            ->where('is_read', false)
            ->count();

        // Active promotions
        $activePromotions = Promotion::with('amenities')->where('status', 'active')
            ->whereDate('start_date', '<=', now())
            ->whereDate('end_date', '>=', now())
            ->limit(3)
            ->get();

        // Calculate total pending amount
        $totalPendingAmount = $pendingPayments->sum(function ($billing) {
            $paid = $billing->payments()
                ->where('payment_status', 'completed')
                ->sum('amount_paid');
            return max(0, (float) $billing->total_amount - (float) $paid);
        });

        $data = [
            'currentReservation' => $currentReservation,
            'upcomingReservations' => $upcomingReservations,
            'pastReservations' => $pastReservations,
            'pendingPayments' => $pendingPayments,
            'recentPayments' => $recentPayments,
            'unreadNotifications' => $unreadNotifications,
            'activePromotions' => $activePromotions,
            'totalPendingAmount' => $totalPendingAmount,
        ];

        return view('guest.dashboard', $data);
    }
}
