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
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
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

    /** "₱1,400.00" - the one way this controller writes an amount in a message. */
    private function peso(float $amount): string
    {
        return '₱' . number_format($amount, 2);
    }

    /**
     * Why $amount is not an acceptable payment right now, as the 422 body (message + errors.amount_paid), or null
     * if it is. $range comes from ReservationWorkflowService::payableRange().
     */
    private function amountError(array $range, string $paymentType, float $amount): ?array
    {
        $message = null;

        if ($range['is_settled']) {
            $message = 'This reservation is already fully paid. There is nothing left to pay.';
        } elseif ($paymentType === 'full') {
            if (abs($amount - $range['remaining']) > 0.01) {
                $message = 'Full payment must equal the remaining balance (' . $this->peso($range['remaining']) . ').';
            }
        } elseif (! $range['can_partial']) {
            $message = 'Full payment is required: the remaining balance (' . $this->peso($range['remaining'])
                . ') is below the minimum down payment (' . $this->peso($range['min']) . '). Pay the full '
                . $this->peso($range['remaining']) . ' instead.';
        } elseif ($amount < $range['min'] - 0.005 || $amount > $range['max'] + 0.005) {
            $minPercent = (int) round((float) config('hotel.minimum_payment_ratio', 0.20) * 100);
            $maxPercent = (int) round((float) config('hotel.maximum_payment_ratio', 0.50) * 100);
            $message = 'The payment must be between ' . $this->peso($range['min']) . ' and ' . $this->peso($range['max'])
                . " ({$minPercent}%-{$maxPercent}% of the " . $this->peso($range['total']) . ' total, and never more than the '
                . $this->peso($range['remaining']) . ' still owed).';
        }

        return $message === null ? null : ['message' => $message, 'errors' => ['amount_paid' => [$message]]];
    }

    /** 'final' when the payment settles everything still owed (whichever option the guest picked), else 'deposit'. */
    private function stageFor(array $range, float $amount): string
    {
        return abs($amount - $range['remaining']) <= 0.01 ? 'final' : 'deposit';
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

        // Must run BEFORE $request->validate() below, not after - the exact
        // same ordering bug fixed in Api\BookingController::store()/Api\
        // ReservationController::store() (commit 4ed3a3a) also applied here:
        // a dropped-response retry resubmitting the SAME reference_number
        // would otherwise hit the 'reference_number.unique' rule and get a
        // misleading "already used" 422 instead of its own already-created
        // payment back. Scoped to THIS reservation so a stray/replayed key
        // can never leak a different reservation's payment.
        $idempotencyKey = $request->input('idempotency_key');
        if (! empty($idempotencyKey)) {
            $existing = Payment::where('idempotency_key', $idempotencyKey)
                ->where('reservation_id', $reservation->id)
                ->first();
            if ($existing) {
                return response()->json([
                    'payment' => $existing,
                    'reservation' => $reservation->refresh()->loadMissing(['roomType', 'booking'])->append(['total_amount_due', 'amenities']),
                ], 201);
            }
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
        //
        // Aware of what has ALREADY been paid (completed payments, the
        // same definition every payment summary uses), so the rest of the
        // bill can be paid: Full = exactly the remaining balance, Partial
        // = 20%-50% of the original total but never more than the
        // remaining balance. See ReservationWorkflowService::payableRange().
        // Checked again below on the row-locked reservation, so two
        // simultaneous submissions can't both fit the same balance.
        $range = $this->workflow->payableRange($reservation);

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
            // Optional - an older app version that never sends one simply
            // gets no idempotency protection, same as today. See the
            // pre-validate lookup above and MULTI_ROOM_TRANSACTION_BACKEND_SPEC.md
            // section 9b for the shared convention across all 3 payment-
            // bearing endpoints.
            'idempotency_key' => 'nullable|string|max:100',
        ], [
            'reference_number.unique' => 'This GCash reference number has already been used.',
        ]);

        $amountError = $this->amountError($range, $validated['payment_type'], (float) $validated['amount_paid']);
        if ($amountError !== null) {
            return response()->json($amountError, 422);
        }

        // Receipt upload (disk I/O) happens BEFORE the locked transaction
        // below, not inside it - a slow upload should never extend how
        // long another concurrent request for this same reservation has to
        // wait for the row lock.
        $receiptPath = $validated['payment_method'] === 'gcash'
            ? $request->file('receipt')->store('payment-receipts', 'public')
            : null;

        // Everything from here must be atomic with respect to another
        // near-simultaneous submission for this SAME reservation - the
        // pending-payment-exists check, persisting selected_payment_percentage/
        // required_payment_amount, creating the Payment row, and (for GCash)
        // ReservationWorkflowService::tryAutoConvert()'s auto-conversion all
        // used to run as separate, individually-unlocked steps against a
        // Reservation instance fetched before this request's own validation
        // even ran. Two requests could both pass the unlocked
        // "no pending payment yet" check and both pass the unlocked
        // "status === AWAITING_GCASH" check before either committed,
        // letting both create a Payment row and both call
        // createBookingFromReservation() - two Booking rows for one
        // Reservation, confirmed possible by source inspection (no
        // lockForUpdate() anywhere in this path, no unique constraint on
        // bookings.reservation_id at the time). Re-fetching the reservation
        // WITH a row lock here, and re-running both checks against that
        // locked row, means a second request racing in blocks on the lock
        // until the first's entire transaction (including any auto-convert)
        // commits, then correctly sees the first request's own result
        // (a pending payment, or status already CONVERTED_TO_BOOKING) and
        // safely no-ops instead of duplicating it.
        $idempotencyKeyForCreate = $validated['idempotency_key'] ?? null;

        try {
            $outcome = DB::transaction(function () use ($reservation, $validated, $receiptPath, $idempotencyKeyForCreate) {
            $locked = Reservation::whereKey($reservation->id)->lockForUpdate()->first();

            if (! $locked || ! in_array($locked->status, Reservation::ACTIVE_STATUSES, true)) {
                return ['error' => 'not_payable'];
            }

            // The amount rule again, on the row-locked reservation: what was
            // already paid may have changed (a receptionist verifying an
            // earlier payment) between the check above and this lock.
            $lockedRange = $this->workflow->payableRange($locked);
            $amountError = $this->amountError($lockedRange, $validated['payment_type'], (float) $validated['amount_paid']);
            if ($amountError !== null) {
                return ['error' => 'amount', 'body' => $amountError];
            }
            // A payment that settles the whole remaining balance is the FINAL
            // payment whichever option the guest picked (a partial of exactly
            // what is left included); anything less is a deposit.
            $paymentStage = $this->stageFor($lockedRange, (float) $validated['amount_paid']);
            $paymentStageForDupeCheck = $paymentStage;

            // Without this guard, repeated submissions (e.g. cash intent, then GCash, then
            // cash again, all before any of them is verified) would each create a brand-new
            // Payment row with nothing ever superseding the earlier ones - the guest's own
            // existing cancel/void endpoints are the correct way to clear a stuck attempt
            // before trying again.
            $existingPending = $locked->payments()
                ->where('payment_stage', $paymentStageForDupeCheck)
                ->where('payment_status', 'pending')
                ->first();
            if ($existingPending) {
                // A genuinely simultaneous resubmission of the SAME attempt
                // (identical idempotency_key) can still reach here: the
                // pre-validate lookup above ran before either request had
                // committed, so both passed it. Rather than reject the very
                // request that's racing against its own already-committed
                // result, return that result as success - the same outcome
                // a slightly-later retry would get from the pre-validate
                // lookup instead.
                if (! empty($idempotencyKeyForCreate) && $existingPending->idempotency_key === $idempotencyKeyForCreate) {
                    return ['payment' => $existingPending, 'reservation' => $locked];
                }

                return ['error' => 'duplicate_pending'];
            }

            // Persist the percentage/amount for this submission onto the
            // reservation BEFORE recording the payment - overwritten on every
            // new submission (never accumulated), matching the guest-facing
            // contract that these two fields always reflect the most recent
            // payment attempt, not a running history (see Android's
            // renderStoredPaymentPercentageIfPresent() doc: the read-only lock
            // only applies while that latest submission is still pending
            // verification; once verified/rejected, a further submission's own
            // values simply replace these again).
            //
            // Must happen BEFORE recordDepositPayment() below, not after: a
            // GCash payment synchronously auto-converts the reservation into a
            // Booking via ReservationWorkflowService::tryAutoConvert() ->
            // createBookingFromReservation(), which snapshots
            // $locked->selected_payment_percentage/required_payment_amount
            // onto the new Booking row at that exact moment.
            $locked->update([
                'selected_payment_percentage' => $validated['selected_payment_percentage'] ?? null,
                'required_payment_amount' => (float) $validated['amount_paid'],
            ]);

            if ($validated['payment_method'] === 'gcash') {
                $payment = $this->workflow->recordDepositPayment($locked, [
                    'payment_method' => 'gcash',
                    'reference_number' => $validated['reference_number'],
                    'gcash_number' => $validated['gcash_number'],
                    'receipt_path' => $receiptPath,
                    'amount_paid' => $validated['amount_paid'],
                    'idempotency_key' => $idempotencyKeyForCreate,
                ], $paymentStage);
            } else {
                $payment = $this->workflow->recordCashIntent($locked, (float) $validated['amount_paid'], $paymentStage, $idempotencyKeyForCreate);
            }

            return ['payment' => $payment, 'reservation' => $locked];
            });
        } catch (\Illuminate\Database\UniqueConstraintViolationException $e) {
            // Lost a genuine race: another request with the SAME
            // idempotency_key committed its own Payment row microseconds
            // before this one - mirrors Api\BookingController::store()'s
            // identical backstop. The application-level check above already
            // handles the same-reservation case (both requests serialize on
            // the reservation row lock), so this is defense-in-depth only,
            // reachable mainly if idempotency_key were ever reused across
            // different reservations.
            if (! empty($idempotencyKeyForCreate) && str_contains($e->getMessage(), 'idempotency_key')) {
                $winner = Payment::where('idempotency_key', $idempotencyKeyForCreate)
                    ->where('reservation_id', $reservation->id)
                    ->first();
                if ($winner) {
                    return response()->json([
                        'payment' => $winner,
                        'reservation' => $reservation->refresh()->loadMissing(['roomType', 'booking'])->append(['total_amount_due', 'amenities']),
                    ], 201);
                }
            }
            Log::error('Payment submission failed on an unexpected unique constraint violation', [
                'reservation_id' => $reservation->id,
                'idempotency_key' => $idempotencyKeyForCreate,
                'error' => $e->getMessage(),
            ]);
            return response()->json(['message' => 'This payment could not be submitted. Please try again.'], 500);
        }

        if (isset($outcome['error'])) {
            // Nothing was recorded, so the receipt stored up front would be an orphan.
            if ($receiptPath !== null) {
                Storage::disk('public')->delete($receiptPath);
            }

            if ($outcome['error'] === 'amount') {
                return response()->json($outcome['body'], 422);
            }

            $message = $outcome['error'] === 'not_payable'
                ? 'This reservation is not payable.'
                : 'A payment for this reservation is already awaiting verification. Cancel or void it before submitting another.';

            return response()->json(['message' => $message], 422);
        }

        $payment = $outcome['payment'];
        $reservation = $outcome['reservation'];

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