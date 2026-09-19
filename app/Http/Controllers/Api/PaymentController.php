<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Payment;
use App\Models\Reservation;
use App\Services\NotificationService;
use App\Services\ReservationWorkflowService;
use App\Support\Activity;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Submit a guest-side payment (Partial/deposit or Full) against an
 * existing Reservation - the mobile equivalent of the website's Pay Now
 * (GCash) / Pay Later (Cash) flow. Uses the exact same
 * ReservationWorkflowService the website uses. A successful GCash
 * payment (partial or full) now automatically converts the reservation
 * into a Booking (see ReservationWorkflowService::recordDepositPayment/
 * tryAutoConvert) - Cash never auto-converts, it always waits for a
 * receptionist to collect/confirm it in person.
 */
class PaymentController extends Controller
{
    public function __construct(
        private NotificationService $notificationService,
        private ReservationWorkflowService $workflow,
    ) {
    }

    public function store(Request $request, Reservation $reservation): JsonResponse
    {
        if ($reservation->guest_id !== auth()->user()->guest->id) {
            return response()->json(['message' => 'Unauthorized'], 403);
        }

        if (!in_array($reservation->status, Reservation::ACTIVE_STATUSES, true)) {
            return response()->json(['message' => 'This reservation is not payable.'], 422);
        }

        // The payment method is fixed at reservation creation (see
        // Api\ReservationController::store()) and can only be changed via
        // the explicit one-time switchToGcash()/switchToCash() endpoints -
        // never trust a mismatched value from this submission itself, even
        // though the Android client already locks this choice on its own
        // Pay Now screen. Skip the check for legacy rows created before
        // payment_method was required (null) so old data isn't broken.
        if ($reservation->payment_method !== null && $request->input('payment_method') !== $reservation->payment_method) {
            return response()->json(['message' => "This reservation's payment method is fixed and cannot be changed here."], 422);
        }

        $reservation->loadMissing('roomType');
        // Room-lines-aware, amenities-inclusive - see Reservation::
        // getTotalAmountDueAttribute()'s own doc. The old depositRange()
        // call this replaced only ever priced roomType->rate (the FIRST
        // room-type line) x rooms_requested (the SUM of every line's
        // quantity) x nights, with no amenities at all - wrong the moment
        // more than one room type or any paid amenity was involved, and
        // this is the one call site that actually gates what amount the
        // guest is allowed to submit, so that error directly caused a
        // guest-visible over/under-payment requirement, not just a display
        // bug.
        $range = $this->workflow->depositRangeForTotal($reservation->total_amount_due);

        $validated = $request->validate([
            'payment_method' => 'required|in:cash,gcash',
            'payment_type' => 'required|in:partial,full',
            'reference_number' => [
                'required_if:payment_method,gcash',
                'nullable',
                'string',
                'max:100',
                // Reject a GCash reference number already used in another
                // submission that wasn't voided/cancelled (void()/cancel()
                // above null this column out on failure, so a genuinely
                // abandoned attempt never blocks reuse of its own number).
                Rule::unique('payments', 'reference_number')
                    ->where(fn ($q) => $q->where('payment_method', 'gcash')->where('payment_status', '!=', 'failed')),
            ],
            'amount_paid' => ['required', 'numeric', 'min:0'],
            // GCash-only, multipart fields - the mobile equivalent of the
            // website's payDeposit()/store() gcash_receipt upload.
            'gcash_number' => 'required_if:payment_method,gcash|regex:/^9\d{9}$/',
            // 50MB, per the guest-facing GCash receipt upload requirement -
            // deliberately not the same 5120 (5MB) cap other image uploads
            // (id card, profile picture) in this app use.
            'receipt' => 'required_if:payment_method,gcash|image|mimes:jpeg,png,jpg|max:51200',
            // The exact tier (20/30/40/50/100) the guest picked on the
            // mobile payment screen - the Android client already sends this
            // (PaymentRequest#selected_payment_percentage/
            // ApiService#submitGcashPayment()'s own doc), but until now
            // nothing here validated or persisted it, so
            // reservations.selected_payment_percentage/required_payment_amount
            // stayed null for every real submission - every screen that
            // reads it back (Booking Details, Payment Receipt, Billing
            // Summary) silently hid that row. Purely a display label - the
            // actual money is independently, strictly validated below
            // against $range/full-total regardless of what's sent here, so
            // accepting any of the 5 known values (rather than cross-checking
            // it against payment_type) can't be used to under/overpay.
            'selected_payment_percentage' => 'nullable|numeric|in:20,30,40,50,100',
        ], [
            'reference_number.unique' => 'This GCash reference number has already been used.',
        ]);

        $paymentStageForDupeCheck = $validated['payment_type'] === 'full' ? 'final' : 'deposit';

        // Without this guard, repeated submissions (e.g. cash intent, then GCash, then
        // cash again, all before any of them is verified) would each create a brand-new
        // Payment row with nothing ever superseding the earlier ones - the guest's own
        // existing cancel/void endpoints are the correct way to clear a stuck attempt
        // before trying again.
        if ($reservation->payments()->where('payment_stage', $paymentStageForDupeCheck)->where('payment_status', 'pending')->exists()) {
            return response()->json([
                'message' => 'A payment for this reservation is already awaiting verification. Cancel or void it before submitting another.',
            ], 422);
        }

        if ($validated['payment_type'] === 'full') {
            if (abs((float) $validated['amount_paid'] - $range['total']) > 0.01) {
                return response()->json([
                    'message' => "Full payment must equal the total amount due (₱{$range['total']}).",
                    'errors' => ['amount_paid' => ["Full payment must equal ₱{$range['total']}."]],
                ], 422);
            }
        } else {
            if ((float) $validated['amount_paid'] < $range['min'] || (float) $validated['amount_paid'] > $range['max']) {
                return response()->json([
                    'message' => "The down payment must be between ₱{$range['min']} and ₱{$range['max']} (20%-50% of the total amount).",
                    'errors' => ['amount_paid' => ["The down payment must be between ₱{$range['min']} and ₱{$range['max']}."]],
                ], 422);
            }
        }

        $paymentStage = $validated['payment_type'] === 'full' ? 'final' : 'deposit';

        if ($validated['payment_method'] === 'gcash') {
            $path = $request->file('receipt')->store('payment-receipts', 'public');

            $payment = $this->workflow->recordDepositPayment($reservation, [
                'payment_method' => 'gcash',
                'reference_number' => $validated['reference_number'],
                'gcash_number' => $validated['gcash_number'],
                'receipt_path' => $path,
                'amount_paid' => $validated['amount_paid'],
            ], $paymentStage);
        } else {
            $payment = $this->workflow->recordCashIntent($reservation, (float) $validated['amount_paid'], $paymentStage);
        }

        // Persist the percentage/amount for this submission onto the
        // reservation - overwritten on every new submission (never
        // accumulated), matching the guest-facing contract that these two
        // fields always reflect the most recent payment attempt, not a
        // running history (see Android's renderStoredPaymentPercentageIfPresent()
        // doc: the read-only lock only applies while that latest submission
        // is still pending verification; once verified/rejected, a further
        // submission's own values simply replace these again).
        $reservation->update([
            'selected_payment_percentage' => $validated['selected_payment_percentage'] ?? null,
            'required_payment_amount' => (float) $validated['amount_paid'],
        ]);

        $user = auth()->user();
        $reservation->refresh()->loadMissing(['roomType', 'booking']);
        $this->notificationService->notifyPaymentSubmitted($user, (float) $validated['amount_paid'], $reservation->roomType->name, $reservation->id);

        Activity::log(
            'Submitted payment (mobile)',
            ucfirst($validated['payment_method']) . ' ' . $validated['payment_type'] . ' payment of ₱'
                . number_format((float) $validated['amount_paid'], 2) . " for Reservation #{$reservation->id}",
            $reservation->booking ?? $reservation
        );

        return response()->json([
            'payment' => $payment,
            'reservation' => $reservation->append(['total_amount_due', 'amenities']),
        ], 201);
    }

    /**
     * Resolve the Reservation a Payment belongs to for ownership checks -
     * a deposit-stage payment has reservation_id set directly; a final-
     * stage payment (already re-parented onto a Billing at checkout) only
     * has billing_id set, so its reservation has to be traced through
     * billing -> booking -> reservation instead.
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
     * allowed while it's still pending_verification (payment recorded,
     * not yet verified/rejected by a receptionist). Delegates to
     * ReservationWorkflowService::cancel() for the parent reservation
     * (same before-check-in / not-paid-in-full rules already enforced
     * there - see cancel()/cancelConvertedBooking()), and additionally
     * marks this specific payment failed.
     */
    public function cancel(Payment $payment): JsonResponse
    {
        $reservation = $this->ownerReservation($payment);

        if (!$reservation || $reservation->guest_id !== auth()->user()->guest->id) {
            return response()->json(['message' => 'Unauthorized'], 403);
        }

        if (!$payment->isPendingVerification()) {
            return response()->json(['message' => 'This payment cannot be cancelled.'], 422);
        }

        $this->workflow->cancel($reservation);
        $payment->update(['payment_status' => 'failed']);

        return response()->json([
            'payment' => $payment->fresh(),
            'reservation' => $reservation->fresh(['roomType', 'booking.room', 'payments'])->append(['total_amount_due', 'amenities']),
        ]);
    }

    /**
     * Guest voids their own in-flight GCash payment attempt - same
     * pending_verification guard as cancel(), but this only clears the
     * payment attempt itself (reference number, receipt, gcash number)
     * and marks it failed; unlike cancel(), it never touches the parent
     * Reservation/Booking - it stays exactly as it was, still active and
     * still unpaid, so the guest can simply try paying again.
     */
    public function void(Payment $payment): JsonResponse
    {
        $reservation = $this->ownerReservation($payment);

        if (!$reservation || $reservation->guest_id !== auth()->user()->guest->id) {
            return response()->json(['message' => 'Unauthorized'], 403);
        }

        if (!$payment->isPendingVerification()) {
            return response()->json(['message' => 'This payment cannot be voided.'], 422);
        }

        $payment->update([
            'reference_number' => null,
            'receipt_path' => null,
            'gcash_number' => null,
            'payment_status' => 'failed',
        ]);

        return response()->json(['payment' => $payment->fresh()]);
    }
}