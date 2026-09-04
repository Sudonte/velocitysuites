<?php

namespace App\Http\Controllers\Receptionist;

use App\Http\Controllers\Controller;
use App\Models\AmenityRequest;
use App\Models\Booking;
use App\Models\Payment;
use App\Services\NotificationService;
use App\Services\ReservationWorkflowService;
use App\Support\Activity;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * Payment-level verification/rejection - separate from the existing
 * reservation-level and booking-level verified_at/verified_by gates
 * (Receptionist\ReservationController::verify(),
 * Receptionist\BookingController::verify()). This acts on an individual
 * GCash payment/receipt (e.g. a specific deposit payment attached to a
 * reservation), letting a receptionist reject just that receipt (bad
 * screenshot, mismatched reference number, etc.) without touching the
 * parent Reservation/Booking status at all.
 *
 * Note: Receptionist\BookingController::verify() itself now refuses to
 * verify a booking whose most recent GCash payment isn't verified yet -
 * so this is also the gate that has to be cleared before a GCash-paid
 * booking can be marked fully verified.
 */
class PaymentController extends Controller
{
    public function __construct(
        private NotificationService $notificationService,
        private ReservationWorkflowService $workflow,
    ) {
    }

    /**
     * Marks a payment Verified and, in the same action, completes its
     * parent Booking too (sets Booking::verified_at/verified_by - moving
     * it from the Active Booking List to the Confirmed Bookings) if one
     * exists and isn't already verified. Previously a receptionist had to
     * verify the payment here and then separately click "Verify Booking"
     * on Receptionist\BookingController::verify() - now verifying the
     * payment is the one action that both marks it verified and completes
     * the booking, since a GCash booking's own verify button was already
     * unconditionally gated on this payment being verified first (nothing
     * else could ever block it at that point).
     */
    public function verify(Payment $payment): RedirectResponse
    {
        abort_if($payment->isVerified(), 422, 'This payment is already verified.');

        // Can't call a GCash payment verified without something to have
        // actually verified - the registered number and the receipt both
        // need to be on file first.
        if ($payment->payment_method === 'gcash' && (!$payment->gcash_number || !$payment->receipt_path)) {
            abort(422, 'Cannot verify: the GCash number and receipt must both be on file first.');
        }

        $payment->update(['verified_at' => now(), 'verified_by' => auth()->id()]);

        $this->logAndNotify($payment, verified: true);
        $this->autoCompleteBooking($payment);

        return back()->with('success', 'Payment verified and booking completed.');
    }

    /**
     * Completes the payment's parent Booking (if any, and not already
     * verified) as part of verifying the payment itself - see verify()'s
     * docblock. Booking::verify() is not reused directly since its own
     * gcashPaymentNeedsVerification() check would now always be false
     * here (we just verified the payment that check looks at) and its own
     * abort_if/abort_unless guards would be redundant, but the same
     * update + Activity::log shape is mirrored exactly.
     */
    private function autoCompleteBooking(Payment $payment): void
    {
        $booking = $this->resolveBooking($payment);
        if (!$booking || $booking->verified_at !== null || $booking->booking_status !== Booking::STATUS_ACTIVE) {
            return;
        }

        $booking->update(['verified_at' => now(), 'verified_by' => auth()->id()]);

        // Pending amenity requests on a direct booking have no separate
        // "verify the transaction" step the way a Reservation does
        // (Receptionist\ReservationController::verify()) - this payment
        // verification IS that step for a direct booking, so bulk-flip its
        // pending amenity requests to approved here too, same as that
        // method does for a reservation.
        if ($booking->reservation_id === null) {
            AmenityRequest::where('booking_id', $booking->id)
                ->where('status', 'pending')
                ->update(['status' => 'approved']);
        }

        Activity::log(
            'Verified booking',
            "Booking #{$booking->id} - {$booking->guest_display_name} (auto-completed after payment verification)",
            $booking
        );
    }

    /**
     * Resolves the Booking a payment belongs to, regardless of which of
     * the three payment shapes it is: a direct booking's payment
     * (booking_id set directly), a reservation-derived deposit-stage
     * payment (reservation_id set, reservation->booking once converted),
     * or a final-stage payment re-parented onto a Billing at checkout
     * (billing->booking). Returns null if no Booking exists yet (e.g. a
     * still-pending, not-yet-converted reservation's deposit payment).
     */
    private function resolveBooking(Payment $payment): ?Booking
    {
        if ($payment->booking_id) {
            return $payment->booking;
        }

        $reservation = $payment->reservation_id
            ? $payment->reservation
            : $payment->billing?->booking?->reservation;

        return $reservation?->booking;
    }

    /**
     * Rejects a payment - always requires a reason (e.g. receipt doesn't
     * match the declared amount, reference number can't be verified).
     * Same shape as Receptionist\ReservationController::reject(), but at
     * the individual payment level.
     */
    public function reject(Request $request, Payment $payment): RedirectResponse
    {
        $validated = $request->validate(['reason' => 'required|string|max:500']);

        $payment->update([
            'rejected_at' => now(),
            'rejected_by' => auth()->id(),
            'rejection_reason' => $validated['reason'],
            'payment_status' => 'rejected',
        ]);

        $this->logAndNotify($payment, verified: false, reason: $validated['reason']);

        // A rejected deposit has no resubmission path once a Booking
        // exists for it (Booking::gcashPaymentNeedsVerification() stays
        // true forever for a rejected payment) - cancel the booking now
        // rather than stranding it with no Verify action and no way
        // forward. Applies identically to a direct booking's payment
        // (resolveBooking() finds it via booking_id directly) and a
        // reservation-derived one (via reservation->booking).
        if ($booking = $this->resolveBooking($payment)) {
            $booking->loadMissing('roomType');
            $this->workflow->reconcileGcashBookingPayment($booking);

            // Mirrors autoCompleteBooking()'s pending->approved sync for a
            // direct booking - a rejected payment means the transaction
            // itself gets cancelled (reconcileGcashBookingPayment() above),
            // so its pending amenity requests are rejected in lockstep too.
            if ($booking->reservation_id === null) {
                AmenityRequest::where('booking_id', $booking->id)
                    ->where('status', 'pending')
                    ->update(['status' => 'rejected']);
            }
        }

        return back()->with('success', 'Payment rejected.');
    }

    /**
     * Records the decision in the activity feed and notifies the guest -
     * resolves via resolveBooking() first (covers a direct booking's
     * payment and any reservation-derived payment that's already
     * converted), falling back to the reservation directly for the rare
     * not-yet-converted case where no Booking exists yet at all.
     */
    private function logAndNotify(Payment $payment, bool $verified, ?string $reason = null): void
    {
        $booking = $this->resolveBooking($payment);
        $reservation = $booking?->reservation ?? ($payment->reservation_id ? $payment->reservation : null);

        $guest = $booking ? $booking->account_guest?->user : $reservation?->guest?->user;
        if (!$guest) {
            return;
        }

        $roomType = $booking?->roomType ?? $reservation?->roomType;
        $roomName = $roomType->name ?? 'your stay';
        $amount = (float) $payment->amount_paid;
        // Prefer the Booking id (the receptionist reviews/acts on this
        // from the Bookings module in practice, same reasoning as the
        // Activity::log() call below), falling back to the Reservation id
        // for the rare not-yet-converted case. notifications.reference_id
        // has no FK constraint (relax_notifications_reference_id_constraint
        // migration) precisely so it can hold either.
        $referenceId = $booking?->id ?? $reservation?->id;

        // Logged against the Booking when one exists (the receptionist
        // reviews/acts on this from the Bookings module in practice) so
        // the activity feed's "View" link goes somewhere useful, falling
        // back to the Reservation for the rare not-yet-converted case.
        Activity::log(
            $verified ? 'Verified payment' : 'Rejected payment',
            '₱' . number_format($amount, 2) . ' GCash payment for ' . $guest->full_name . ($reason ? " - {$reason}" : ''),
            $booking ?? $reservation
        );

        if ($verified) {
            $this->notificationService->notifyPaymentVerified($guest, $amount, $roomName, $referenceId);
        } else {
            $this->notificationService->notifyPaymentRejected($guest, $amount, $roomName, $reason, $referenceId);
        }
    }
}

