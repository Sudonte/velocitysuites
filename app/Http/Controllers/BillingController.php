<?php

namespace App\Http\Controllers;

use App\Models\Billing;
use Illuminate\View\View;

/**
 * Role-agnostic receipt view, reachable from both the guest and
 * receptionist route groups (each with their own auth/role middleware -
 * this controller only adds the extra ownership check a guest needs).
 * Was previously Receptionist\ReceptionistController::receiptShow,
 * staff-only; extracted so a guest who paid can see their own receipt.
 */
class BillingController extends Controller
{
    public function receipt(Billing $billing): View
    {
        $billing->load(['booking.reservation.guest.user', 'booking.reservation.room', 'booking.reservation.roomType', 'payments', 'additionalCharges']);

        $user = auth()->user();
        if ($user->role === 'guest') {
            $reservation = $billing->booking?->reservation;
            if (! $reservation || $reservation->guest_id !== $user->guest->id) {
                abort(403, 'Unauthorized');
            }
        }

        $amountPaid = (float) $billing->payments()
            ->where('payment_status', 'completed')
            ->sum('amount_paid');
        $balance = $billing->balance;

        $reservation = $billing->booking?->reservation;
        $backRoute = null;
        if ($reservation) {
            $backRoute = $user->role === 'guest'
                ? route('guest.reservations.show', $reservation)
                : route('receptionist.reservations.show', $reservation);
        }

        return view('billing.receipt', compact('billing', 'amountPaid', 'balance', 'backRoute'));
    }
}
