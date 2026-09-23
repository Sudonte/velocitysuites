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
                'verified_by' => $payment->verifier?->full_name,
                'verified_at' => $payment->verified_at?->toIso8601String(),
                'rejection_reason' => $payment->rejection_reason,
                'payment_date' => $payment->payment_date?->toIso8601String(),
                'total_paid_after_transaction' => $runningTotal,
                'remaining_balance_after_transaction' => PaymentMath::remainingBalance($grandTotal, $runningTotal),
                'receipt_type' => $payment->preCheckoutReceiptType(),
                'receipt_number' => $payment->preCheckoutReceiptType() !== null ? $payment->ensureReceiptNumber() : null,
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
     * related to, preCheckoutReceiptType()'s classification of which
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
     * Every receipt currently available for this booking - one Partial or
     * Full-Payment (pre-checkout) Receipt per receptionist-verified
     * qualifying payment (see Payment::preCheckoutReceiptType()), plus one
     * Official Receipt once (and permanently once) the booking's checkout
     * has actually completed (see Billing::isOfficialReceiptAvailable()).
     * A receipt already issued is never removed from this list just
     * because the Official Receipt now also exists - see
     * Payment::preCheckoutReceiptType()'s own "already issued stays
     * eligible forever" rule.
     */
    public function receiptsList(Booking $booking): array
    {
        $receipts = [];

        foreach ($this->chronologicalPayments($booking) as $payment) {
            $type = $payment->preCheckoutReceiptType();
            if ($type === null) {
                continue;
            }

            $receipts[] = [
                'receipt_number' => $payment->ensureReceiptNumber(),
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
        if ($billing?->isOfficialReceiptAvailable()) {
            $receipts[] = [
                'receipt_number' => $billing->ensureOfficialReceiptNumber(),
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
    public function findReceiptPayload(string $receiptNumber, Guest $guest): ?array
    {
        if (str_starts_with($receiptNumber, 'PR-') || str_starts_with($receiptNumber, 'FR-')) {
            $payment = Payment::where('receipt_number', $receiptNumber)->first();
            if (!$payment) {
                return null;
            }

            $type = $payment->preCheckoutReceiptType();
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

            if (!$billing->isOfficialReceiptAvailable() || !$this->ownedBy($booking, $guest)) {
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

    public function buildReceiptPayload(Booking $booking, string $receiptType, ?Payment $anchorPayment = null, ?Billing $billing = null): array
    {
        $booking->loadMissing(['roomType', 'rooms', 'reservation']);

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
            'payment_summary' => $this->paymentSummary($booking),
            'payment_transactions' => $this->paymentTransactions($booking),
            'anchor_payment' => $anchorPayment ? [
                'amount_paid' => (float) $anchorPayment->amount_paid,
                'payment_method' => $anchorPayment->payment_method,
                'payment_percentage' => $anchorPayment->payment_stage === 'deposit'
                    ? PaymentMath::normalizePercentage($booking->selected_payment_percentage)
                    : 100,
                'gcash_number' => $anchorPayment->gcash_number,
                'gcash_reference_number' => $anchorPayment->reference_number,
                'verified_at' => $anchorPayment->verified_at?->toIso8601String(),
                'verified_by' => $anchorPayment->verifier?->full_name,
            ] : null,
            'issued_at' => ($anchorPayment?->verified_at ?? $billing?->updated_at)?->toIso8601String(),
        ];
    }
}
