<?php

namespace App\Services;

use App\Models\Billing;
use App\Models\Booking;
use App\Models\Guest;
use App\Models\Payment;
use App\Support\PaymentMath;
use Illuminate\Support\Collection;

/**
 * Single source of truth for a Booking's Grand Total / Total Amount Paid /
 * Remaining Balance / Payment Status, its full Payment Transaction History,
 * and its available receipts (Partial, Full-Payment-pre-checkout, and/or
 * Official) - backs the guest-facing API (Api\BookingController,
 * Api\ReservationController, Api\ReceiptController) so Android and the
 * Receptionist Web side both read the exact same backend-computed numbers
 * instead of each recomputing its own. See PAYMENT_RECEIPT_HISTORY_BACKEND_SPEC.md.
 */
class ReceiptService
{
    /**
     * This booking's Billing, if any, with its inverse booking() relation
     * pre-wired to $booking itself - avoids Billing::isOfficialReceiptAvailable()
     * silently issuing an extra lazy-load query for a relation the caller
     * already has in hand (Eloquent doesn't auto-link inverse belongsTo/
     * hasOne pairs). Every method below that needs the Billing goes
     * through this instead of `$booking->billing` directly.
     */
    private function billingOf(Booking $booking): ?Billing
    {
        $billing = $booking->billing;
        $billing?->setRelation('booking', $booking);

        return $billing;
    }

    /**
     * The true grand total for this booking - Billing::total_amount once
     * checkout billing exists (already discount/additional-charges/extra-
     * guest-fee inclusive), Booking::total_amount_due before that (room +
     * amenities only - nothing else is knowable yet).
     */
    public function grandTotal(Booking $booking): float
    {
        $billing = $this->billingOf($booking);

        return $billing ? (float) $billing->total_amount : (float) $booking->total_amount_due;
    }

    /**
     * Every real, non-duplicated payment ever made against this booking,
     * in deterministic chronological order - see Booking::allPayments()
     * for the merge/dedup rule and PaymentMath::sortChronologically() for
     * the ordering rule (effective payment timestamp, then id).
     */
    public function chronologicalPayments(Booking $booking): Collection
    {
        return collect(PaymentMath::sortChronologically($booking->allPayments()->all()))->values();
    }

    public function paymentSummary(Booking $booking): array
    {
        $grandTotal = $this->grandTotal($booking);
        $totalPaid = PaymentMath::totalPaid($this->chronologicalPayments($booking));

        return [
            'grand_total' => $grandTotal,
            'total_amount_paid' => $totalPaid,
            'remaining_balance' => PaymentMath::remainingBalance($grandTotal, $totalPaid),
            'payment_status' => PaymentMath::paymentStatus($grandTotal, $totalPaid),
            'payment_percentage' => PaymentMath::normalizePercentage($booking->selected_payment_percentage),
            'official_receipt_available' => $this->billingOf($booking)?->isOfficialReceiptAvailable() ?? false,
        ];
    }

    /**
     * One entry per real payment attempt, in chronological order, each
     * carrying a running "total paid so far" / "remaining balance after
     * this" snapshot so a Payment Transaction History timeline (mobile or
     * web) never has to recompute those columns itself. Rejected/failed/
     * still-pending attempts are included too (an honest audit trail -
     * see PAYMENT_RECEIPT_HISTORY_BACKEND_SPEC.md's Rejected Payment
     * scenario) but never advance the running total (PaymentMath::totalPaid()'s
     * COUNTS_AS_PAID_STATUS gate).
     */
    public function paymentTransactions(Booking $booking): array
    {
        $grandTotal = $this->grandTotal($booking);
        $runningTotal = 0.0;

        return $this->chronologicalPayments($booking)->map(function (Payment $payment) use ($booking, $grandTotal, &$runningTotal) {
            if ($payment->payment_status === PaymentMath::COUNTS_AS_PAID_STATUS) {
                $runningTotal = round($runningTotal + (float) $payment->amount_paid, 2);
            }

            return [
                'id' => $payment->id,
                'payment_method' => $payment->payment_method,
                'payment_stage' => $payment->payment_stage,
                'transaction_type' => $this->transactionType($payment),
                'amount_paid' => (float) $payment->amount_paid,
                'payment_status' => $payment->payment_status,
                'verification_status' => $payment->verification_status,
                'gcash_number' => $payment->gcash_number,
                'gcash_reference_number' => $payment->payment_method === 'gcash' ? $payment->reference_number : null,
                'reference_number' => $payment->reference_number,
                'payment_percentage' => $payment->payment_stage === 'deposit'
                    ? PaymentMath::normalizePercentage($booking->selected_payment_percentage)
                    : null,
                'verified_by' => $payment->verifierName(),
                'verified_at' => $payment->verified_at?->toIso8601String(),
                'rejection_reason' => $payment->rejection_reason,
                'payment_date' => $payment->payment_date?->toIso8601String(),
                'total_paid_after_transaction' => $runningTotal,
                'remaining_balance_after_transaction' => PaymentMath::remainingBalance($grandTotal, $runningTotal),
                // Pure reads - receiptType()/receipt_number reflect only
                // what's ALREADY stored. A historically-eligible payment
                // that never had a receipt minted (verified before this
                // feature shipped, or its window closed before a read
                // ever triggered issuance) correctly shows null here
                // forever - this method must NEVER call
                // ensureReceiptNumber() (see that method's own doc: write-
                // path only, and PAYMENT_RECEIPT_HISTORY_BACKEND_SPEC.md §8).
                'receipt_type' => $payment->receiptType(),
                'receipt_number' => $payment->receipt_number,
            ];
        })->values()->all();
    }

    /**
     * PARTIAL_PAYMENT (deposit-stage, guest-submitted before checkout),
     * FULL_PAYMENT (a guest-submitted 100% payment before checkout - still
     * payment_stage 'final' but never receptionist-recorded), or
     * CHECKOUT_PAYMENT (recorded directly by Receptionist\CheckOutController::
     * recordPayment() - billing_id set, verified_at always null, since that
     * path has no separate verification step). This is a display
     * classification of the payment EVENT itself - distinct from, but
     * related to, receiptType()'s classification of which
     * RECEIPT DOCUMENT (if any) it produces. Derived entirely from
     * existing columns - no new schema.
     */
    private function transactionType(Payment $payment): string
    {
        if ($payment->payment_stage === 'final' && $payment->billing_id !== null && $payment->verified_at === null) {
            return 'CHECKOUT_PAYMENT';
        }

        return $payment->payment_stage === 'final' ? 'FULL_PAYMENT' : 'PARTIAL_PAYMENT';
    }

    /**
     * PURE READ - every receipt that has ALREADY been issued for this
     * booking (a stored receipt_number exists), never one this call
     * itself mints. One Partial or Full-Payment (pre-checkout) Receipt
     * per payment whose receipt_number is already on file (see
     * Payment::receiptType()), plus the Official Receipt if the billing's
     * receipt_number is already on file. A payment/billing that would be
     * "eligible" in principle but has never actually had a number minted
     * (e.g. a historical payment verified before this feature shipped -
     * see PAYMENT_RECEIPT_HISTORY_BACKEND_SPEC.md §8) is correctly
     * omitted, not backfilled. A receipt already issued is never removed
     * from this list just because the Official Receipt now also exists -
     * see Payment::receiptType()'s own "reflects stored state forever"
     * rule.
     */
    public function receiptsList(Booking $booking): array
    {
        $receipts = [];

        foreach ($this->chronologicalPayments($booking) as $payment) {
            $type = $payment->receiptType();
            if ($type === null) {
                continue;
            }

            $receipts[] = [
                'receipt_number' => $payment->receipt_number,
                'receipt_type' => $type,
                'status' => 'VERIFIED',
                'amount' => (float) $payment->amount_paid,
                'payment_percentage' => $type === 'PARTIAL_RECEIPT'
                    ? PaymentMath::normalizePercentage($booking->selected_payment_percentage)
                    : 100,
                'issued_at' => $payment->verified_at?->toIso8601String(),
            ];
        }

        $billing = $this->billingOf($booking);
        if ($billing && $billing->receipt_number !== null) {
            $receipts[] = [
                'receipt_number' => $billing->receipt_number,
                'receipt_type' => 'OFFICIAL_RECEIPT',
                'status' => 'PAID',
                'amount' => (float) $billing->total_amount,
                'payment_percentage' => 100,
                'issued_at' => $billing->updated_at?->toIso8601String(),
            ];
        }

        return $receipts;
    }

    /**
     * Resolve+authorize a receipt by its receipt_number for
     * Api\ReceiptController::show() - returns null for "not found" AND for
     * "exists but doesn't belong to this guest / isn't actually available
     * yet", deliberately indistinguishable to the caller so an
     * unauthorized guest can never learn whether a given receipt_number
     * exists at all (see PAYMENT_RECEIPT_HISTORY_BACKEND_SPEC.md §19 -
     * the backend must reject this, not just hide an Android button).
     */
    /**
     * PURE LOOKUP ONLY. Looks up an ALREADY-STORED receipt_number,
     * verifies ownership, and returns the receipt if authorized - never
     * generates a missing receipt, regardless of whether the underlying
     * payment/billing would otherwise "qualify". Both branches query
     * Payment/Billing BY their receipt_number column directly (`where
     * ('receipt_number', $receiptNumber)`), so the row returned - if any -
     * is, by construction, one that already has that exact number stored;
     * there is no code path here that could ever mint one.
     */
    public function findReceiptPayload(string $receiptNumber, Guest $guest): ?array
    {
        if (str_starts_with($receiptNumber, 'PR-') || str_starts_with($receiptNumber, 'FR-')) {
            $payment = Payment::where('receipt_number', $receiptNumber)->first();
            if (!$payment) {
                return null;
            }

            // receiptType() here is a pure read of the row we just fetched
            // BY its own receipt_number - always non-null by construction.
            // Kept as an explicit, defensive check rather than assumed, and
            // deliberately calls the read-only accessor, never
            // ensureReceiptNumber().
            $type = $payment->receiptType();
            if ($type === null) {
                return null;
            }

            $booking = $this->resolveBookingForPayment($payment);
            if (!$booking || !$this->ownedBy($booking, $guest)) {
                return null;
            }

            return $this->buildReceiptPayload($booking, $type, $payment);
        }

        if (str_starts_with($receiptNumber, 'OR-')) {
            $billing = Billing::where('receipt_number', $receiptNumber)->first();
            if (!$billing) {
                return null;
            }

            $booking = $billing->booking;
            if (!$booking) {
                return null;
            }
            $billing->setRelation('booking', $booking);

            // $billing->receipt_number is guaranteed non-null (we just
            // queried BY it) - this is a defensive read, not an
            // eligibility computation; isOfficialReceiptAvailable() is
            // deliberately NOT called here (this must be a pure lookup,
            // not a re-derivation of the business rule that originally
            // gated issuance).
            if ($billing->receipt_number === null || !$this->ownedBy($booking, $guest)) {
                return null;
            }

            return $this->buildReceiptPayload($booking, 'OFFICIAL_RECEIPT', null, $billing);
        }

        return null;
    }

    private function ownedBy(Booking $booking, Guest $guest): bool
    {
        return $booking->account_guest?->id === $guest->id;
    }

    /**
     * Resolves the Booking a Payment belongs to regardless of which of the
     * three payment shapes it is - mirrors Receptionist\PaymentController::
     * resolveBooking()'s identical branching (direct booking_id, reservation-
     * derived via reservation->booking, or checkout-collected via
     * billing->booking) so both sides of the app agree on ownership.
     */
    private function resolveBookingForPayment(Payment $payment): ?Booking
    {
        if ($payment->booking_id) {
            return $payment->booking;
        }
        if ($payment->reservation_id) {
            return $payment->reservation->booking ?? null;
        }

        return $payment->billing?->booking;
    }

    /**
     * The money/history half of a receipt payload - extracted out of
     * buildReceiptPayload() specifically so it can be unit tested without
     * touching room_lines/roomType/rooms (which need a real DB connection
     * to resolve even when cached - see tests/Unit/ReceiptServiceReadOnlyTest.php).
     * Returns [paymentSummary, paymentTransactions].
     *
     * For OFFICIAL_RECEIPT ($anchorPayment null), both are the booking's
     * LIVE, current totals and complete history - correct, since the
     * Official Receipt exists specifically to represent the final,
     * fully-settled checkout state.
     *
     * For PARTIAL_RECEIPT/FULL_PAYMENT_RECEIPT ($anchorPayment set),
     * paymentSummary is instead a POINT-IN-TIME SNAPSHOT of what was true
     * the moment THIS SPECIFIC payment was made - reusing
     * total_paid_after_transaction/remaining_balance_after_transaction
     * already computed for that exact row by paymentTransactions(), never
     * the booking's current live totals. Without this, re-opening an old
     * Partial Receipt after the guest later pays the remaining balance (or
     * after checkout completes) would silently rewrite its own numbers
     * into today's totals - e.g. a 50%-paid receipt suddenly claiming
     * "Total Paid ₱10,000 / Remaining ₱0" just because checkout happened
     * LATER. paymentTransactions is likewise trimmed to only the rows up
     * to and including the anchor payment - "the history as it stood at
     * that point," not payments that happened afterward. grand_total is
     * derived as total_paid_after_transaction + remaining_balance_after_transaction
     * for that same row (self-consistent by construction) rather than a
     * separately-recomputed live figure, which could have since changed
     * (e.g. an additional charge added at checkout) and would otherwise
     * disagree with the frozen paid/remaining figures on the same receipt.
     * official_receipt_available is deliberately still the LIVE flag - it
     * answers "is a separate Official Receipt also available now", not a
     * frozen fact about this receipt's own finances (see
     * PAYMENT_RECEIPT_HISTORY_BACKEND_SPEC.md §4/§5/§9 - PR/FR/OR must be
     * able to coexist as distinct, independently-accurate documents).
     */
    public function summaryAndHistoryForReceipt(Booking $booking, ?Payment $anchorPayment): array
    {
        $allTransactions = $this->paymentTransactions($booking);
        $liveSummary = $this->paymentSummary($booking);

        if ($anchorPayment === null) {
            return [$liveSummary, $allTransactions];
        }

        $anchorIndex = null;
        foreach ($allTransactions as $index => $row) {
            if ($row['id'] === $anchorPayment->id) {
                $anchorIndex = $index;
                break;
            }
        }

        $paymentTransactionsForReceipt = $anchorIndex !== null
            ? array_slice($allTransactions, 0, $anchorIndex + 1)
            : $allTransactions;

        $anchorRow = $anchorIndex !== null ? $allTransactions[$anchorIndex] : null;
        $snapshotPaid = $anchorRow['total_paid_after_transaction'] ?? (float) $anchorPayment->amount_paid;
        $snapshotRemaining = $anchorRow['remaining_balance_after_transaction'] ?? 0.0;
        $snapshotGrandTotal = round($snapshotPaid + $snapshotRemaining, 2);

        $paymentSummary = [
            'grand_total' => $snapshotGrandTotal,
            'total_amount_paid' => $snapshotPaid,
            'remaining_balance' => $snapshotRemaining,
            'payment_status' => PaymentMath::paymentStatus($snapshotGrandTotal, $snapshotPaid),
            'payment_percentage' => $anchorRow['payment_percentage'] ?? null,
            'official_receipt_available' => $liveSummary['official_receipt_available'],
        ];

        return [$paymentSummary, $paymentTransactionsForReceipt];
    }

    public function buildReceiptPayload(Booking $booking, string $receiptType, ?Payment $anchorPayment = null, ?Billing $billing = null): array
    {
        // Deliberately no loadMissing() here (removed - see git history):
        // Eloquent's Collection::loadMissing() unconditionally builds a
        // throwaway query object via newQueryWithoutRelationships() BEFORE
        // it ever checks relationLoaded(), so it needs a live DB
        // connection regardless of whether the relations are already
        // cached. Eager-loading roomType/rooms/reservation is the
        // caller's responsibility where it matters for performance
        // (every current caller already does - Api\BookingController/
        // ReservationController's show(), the Receptionist/Guest Blade
        // controllers) - a caller that forgets just gets a normal
        // lazy-load per relation instead of a batched one, never a
        // correctness issue.
        [$paymentSummary, $paymentTransactionsForReceipt] = $this->summaryAndHistoryForReceipt($booking, $anchorPayment);

        return [
            'receipt_type' => $receiptType,
            'receipt_number' => $anchorPayment?->receipt_number ?? $billing?->receipt_number,
            'booking_id' => $booking->id,
            'reservation_id' => $booking->reservation_id,
            'guest_account_name' => $booking->account_guest_full_name,
            'representative_name' => $booking->stay_guest_full_name,
            'room_type' => $booking->roomType?->name,
            'room_lines' => $booking->room_lines,
            'check_in' => $booking->check_in?->toIso8601String(),
            'check_out' => $booking->check_out?->toIso8601String(),
            'number_of_nights' => $booking->number_of_nights,
            'assigned_room_numbers' => $booking->rooms->pluck('room_number')->values()->all(),
            'payment_summary' => $paymentSummary,
            'payment_transactions' => $paymentTransactionsForReceipt,
            'anchor_payment' => $anchorPayment ? [
                'amount_paid' => (float) $anchorPayment->amount_paid,
                'payment_method' => $anchorPayment->payment_method,
                'payment_percentage' => $anchorPayment->payment_stage === 'deposit'
                    ? PaymentMath::normalizePercentage($booking->selected_payment_percentage)
                    : 100,
                'gcash_number' => $anchorPayment->gcash_number,
                'gcash_reference_number' => $anchorPayment->reference_number,
                'verified_at' => $anchorPayment->verified_at?->toIso8601String(),
                'verified_by' => $anchorPayment->verifierName(),
            ] : null,
            'issued_at' => ($anchorPayment?->verified_at ?? $billing?->updated_at)?->toIso8601String(),
        ];
    }
}
