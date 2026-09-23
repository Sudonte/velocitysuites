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
        $billing->load([
            'booking.reservation.guest.user', 'booking.guest.user', 'booking.room', 'booking.roomType',
            'booking.payments', 'booking.reservation.payments',
            'payments', 'additionalCharges',
        ]);

        $user = auth()->user();
        $forGuest = $user->role === 'guest';

        if ($forGuest) {
            // Authoritative owner regardless of booking source -
            // reservation-derived (via reservation->guest) or a direct
            // booking (via its own guest() relation) - see
            // Booking::getAccountGuestAttribute(), the exact same accessor
            // ReceiptService::ownedBy() uses for the mobile API's
            // identical check, so both surfaces agree on ownership.
            //
            // Previously this only ever checked $billing->booking?->reservation,
            // which is always null for a direct "New Booking" transaction
            // (reservation_id null by definition) - denying every guest
            // their OWN direct booking's receipt (never a leak, but also
            // never granting legitimate access). This fixes both
            // directions: a real owner now gets in regardless of booking
            // source, and anyone else gets the same abort(403) either way
            // (never a distinguishable "exists but not yours" vs "doesn't
            // exist" response).
            $ownerGuestId = $billing->booking?->account_guest?->id;
            if ($ownerGuestId === null || $ownerGuestId !== $user->guest->id) {
                abort(403, 'Unauthorized');
            }
        }

        // Grand Total / Total Amount Paid / Remaining Balance / Payment
        // Status / Payment Transaction History all come from
        // Booking::paymentSummary()/paymentTransactionsPayload()
        // (ReceiptService) - the same authoritative source the
        // Receptionist checkout Payment Panel and the guest-facing API
        // use, not a second independent calculation here. Both methods
        // are pure reads - no receipt number is ever minted just by
        // viewing this page.
        $booking = $billing->booking;
        $paymentSummary = $booking->paymentSummary();
        $paymentTransactions = $booking->paymentTransactionsPayload();

        $reservation = $billing->booking?->reservation;
        $backRoute = null;
        if ($reservation) {
            // Receptionist detail is a modal on the Bookings list now, not
            // a standalone page - link back to that list rather than a
            // deep link that no longer exists.
            $backRoute = $forGuest
                ? route('guest.reservations.show', $reservation)
                : route('receptionist.bookings.index');
        }

        return view('billing.receipt', compact('billing', 'paymentSummary', 'paymentTransactions', 'backRoute'));
    }
}
