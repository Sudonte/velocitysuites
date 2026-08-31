<?php

namespace App\Http\Controllers\Guest;

use App\Http\Controllers\Controller;
use App\Models\Payment;
use App\Models\Reservation;
use App\Services\ReservationWorkflowService;
use Illuminate\Http\RedirectResponse;

/**
 * Guest self-service actions on their own in-flight payment attempts -
 * the web equivalent of Api\PaymentController::cancel()/void(), same
 * ReservationWorkflowService rules, so a web guest and a mobile guest can
 * clear a stuck GCash submission identically.
 */
class PaymentController extends Controller
{
    public function __construct(private ReservationWorkflowService $workflow)
    {
    }

    /**
     * Resolve the Reservation a Payment belongs to for ownership checks -
     * a deposit-stage payment has reservation_id set directly; a final-
     * stage payment (already re-parented onto a Billing at checkout) only
     * has billing_id set, so its reservation has to be traced through
     * billing -> booking -> reservation instead. Mirrors
     * Api\PaymentController::ownerReservation() exactly.
     */
    private function ownerReservation(Payment $payment): ?Reservation
    {
        if ($payment->reservation_id) {
            return $payment->reservation;
        }

        return $payment->billing?->booking?->reservation;
    }

    /**
     * Guest cancels their own in-flight GCash payment attempt - only
     * allowed while it's still pending_verification. Delegates to
     * ReservationWorkflowService::cancel() for the parent reservation, and
     * additionally marks this specific payment failed. Same rules as
     * Api\PaymentController::cancel().
     */
    public function cancel(Payment $payment): RedirectResponse
    {
        $reservation = $this->ownerReservation($payment);

        if (!$reservation || $reservation->guest_id !== auth()->user()->guest->id) {
            abort(403);
        }

        if (!$payment->isPendingVerification()) {
            return back()->with('error', 'This payment cannot be cancelled.');
        }

        $this->workflow->cancel($reservation);
        $payment->update(['payment_status' => 'failed']);

        return redirect()->route('guest.reservations.show', $reservation)
            ->with('success', 'Payment cancelled and reservation cancelled.');
    }

    /**
     * Guest voids their own in-flight GCash payment attempt - same
     * pending_verification guard as cancel(), but only clears the payment
     * attempt itself; the parent Reservation/Booking stays untouched, so
     * the guest can simply try paying again. Same rules as
     * Api\PaymentController::void().
     */
    public function void(Payment $payment): RedirectResponse
    {
        $reservation = $this->ownerReservation($payment);

        if (!$reservation || $reservation->guest_id !== auth()->user()->guest->id) {
            abort(403);
        }

        if (!$payment->isPendingVerification()) {
            return back()->with('error', 'This payment cannot be voided.');
        }

        $payment->update([
            'reference_number' => null,
            'receipt_path' => null,
            'gcash_number' => null,
            'payment_status' => 'failed',
        ]);

        return redirect()->route('guest.reservations.show', $reservation)
            ->with('success', 'Payment attempt cleared. You can submit a new payment now.');
    }
}
