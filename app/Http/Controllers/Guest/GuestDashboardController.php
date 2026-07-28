<?php

namespace App\Http\Controllers\Guest;

use App\Http\Controllers\Controller;
use App\Models\Billing;
use App\Models\Payment;
use App\Models\Promotion;
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

        // Current active stay: a converted reservation whose Booking is
        // currently checked in.
        $currentReservation = $guest->reservations()
            ->where('status', 'converted')
            ->whereHas('booking', fn ($q) => $q->where('booking_status', 'checked_in'))
            ->with(['roomType', 'booking.room', 'booking.billing'])
            ->first();

        // Upcoming: still awaiting staff action, or converted into a
        // confirmed booking that hasn't checked in yet.
        $upcomingReservations = $guest->reservations()
            ->where(function ($q) {
                $q->whereIn('status', ['pending_review', 'ready_for_booking'])
                  ->orWhere(function ($q2) {
                      $q2->where('status', 'converted')
                         ->whereHas('booking', fn ($b) => $b->where('booking_status', 'confirmed'));
                  });
            })
            ->with(['roomType', 'booking'])
            ->orderBy('check_in')
            ->get();

        // Past: checked out, rejected, or cancelled.
        $pastReservations = $guest->reservations()
            ->where(function ($q) {
                $q->whereIn('status', ['rejected', 'cancelled'])
                  ->orWhere(function ($q2) {
                      $q2->where('status', 'converted')
                         ->whereHas('booking', fn ($b) => $b->where('booking_status', 'checked_out'));
                  });
            })
            ->with(['roomType', 'booking.billing.payments'])
            ->latest('check_out')
            ->limit(10)
            ->get();

        // Pending payments (billing status pending or partial)
        $pendingPayments = Billing::whereHas('booking', function ($query) use ($guest) {
            $query->whereHas('reservation', function ($q) use ($guest) {
                $q->where('guest_id', $guest->id);
            });
        })
        ->whereIn('billing_status', ['pending', 'partial'])
        ->with(['booking.reservation.roomType', 'booking.room'])
        ->get();

        // Recent payments (both deposit and final)
        $reservationIds = $guest->reservations()->pluck('id');
        $recentPayments = Payment::where(function ($q) use ($reservationIds) {
            $q->whereIn('reservation_id', $reservationIds)
              ->orWhereHas('billing.booking.reservation', function ($q2) use ($reservationIds) {
                  $q2->whereIn('id', $reservationIds);
              });
        })
        ->with(['billing.booking.reservation.roomType', 'billing.booking.room', 'reservation.roomType'])
        ->latest('created_at')
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
