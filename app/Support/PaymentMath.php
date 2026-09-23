<?php

namespace App\Support;

/**
 * Pure, stateless payment/receipt logic - Grand Total math, remaining
 * balance, payment status derivation, safe percentage normalization,
 * cross-source payment de-duplication, and deterministic chronological
 * ordering. Extracted out of ReceiptService/Booking/Reservation
 * specifically so the exact rules every guest/receptionist-facing surface
 * relies on can be unit tested without a database, and so no layer (web,
 * API, Android) ever has to reimplement this logic independently - see
 * PAYMENT_RECEIPT_HISTORY_BACKEND_SPEC.md, "Single source of truth".
 */
class PaymentMath
{
    /**
     * Anything within this many pesos of zero is treated as settled/zero -
     * guards against float rounding noise only, never a real discount.
     */
    public const EPSILON = 0.01;

    /**
     * The only payment_status value that ever counts as money actually
     * received - confirmed against the live schema/code
     * (payments.payment_status is 'pending'|'completed'|'failed'|'rejected';
     * a guest's own cancel()/void() of an in-flight GCash attempt both set
     * 'failed', there is no separate 'cancelled'/'voided' status value).
     * A receptionist-recorded checkout Cash/GCash payment
     * (Receptionist\CheckOutController::recordPayment()) is created
     * already 'completed' with no separate verification step, and
     * correctly counts here on that basis alone - this constant
     * deliberately does NOT also require verified_at, since that column
     * only applies to guest-submitted GCash deposits.
     */
    public const COUNTS_AS_PAID_STATUS = 'completed';

    /**
     * Sum of completed payment amounts only - pending/rejected/failed
     * payments never count as paid, no matter how they got there. Accepts
     * anything array-accessible per item (plain arrays for unit tests, or
     * Eloquent models - Payment implements ArrayAccess via Eloquent, so
     * $payment['payment_status'] works identically either way). Callers
     * are expected to have already de-duplicated $payments (see
     * mergeAndDeduplicate()) - this method does not defend against a
     * repeated id itself, so a caller that merges sources without
     * deduplicating first would double-count, by design (the fix belongs
     * at the merge step, not silently here).
     */
    public static function totalPaid(iterable $payments): float
    {
        $total = 0.0;
        foreach ($payments as $payment) {
            if (($payment['payment_status'] ?? null) === self::COUNTS_AS_PAID_STATUS) {
                $total += (float) ($payment['amount_paid'] ?? 0);
            }
        }

        return round($total, 2);
    }

    /**
     * Remaining balance - never negative. Even a stale grand total or a
     * duplicate-counted payment can only ever report ₱0.00 remaining here,
     * never a negative balance.
     */
    public static function remainingBalance(float $grandTotal, float $totalPaid): float
    {
        return round(max(0, $grandTotal - $totalPaid), 2);
    }

    /**
     * PENDING (nothing paid yet), PARTIALLY_PAID (something paid, balance
     * still outstanding), or PAID (balance fully settled) - the one shared
     * definition every surface (API payment_summary, receipts, Android,
     * Receptionist Web) should read instead of re-deriving its own status
     * string from raw totals.
     */
    public static function paymentStatus(float $grandTotal, float $totalPaid): string
    {
        if ($totalPaid <= self::EPSILON) {
            return 'PENDING';
        }

        return self::remainingBalance($grandTotal, $totalPaid) <= self::EPSILON ? 'PAID' : 'PARTIALLY_PAID';
    }

    /**
     * A whole-number display percentage (20/30/40/50/100), never a
     * fraction and never multiplied twice - the guard against the
     * "50% -> 5000%" class of bug. A value already greater than 1 is
     * assumed to already be a whole percentage (returned as-is, rounded);
     * a value of 1 or less is assumed to be a 0-1 fraction and is
     * converted by x100 exactly once. Returns null for a null/negative
     * input - "no percentage on file" must stay null, never 0.
     */
    public static function normalizePercentage(?float $value): ?int
    {
        if ($value === null || $value < 0) {
            return null;
        }

        $whole = $value > 1 ? $value : $value * 100;

        return (int) round($whole);
    }

    /**
     * Merges any number of payment sources (reservation-scoped,
     * booking-scoped, billing-scoped) into one list, keeping only the
     * FIRST occurrence of any repeated id and discarding later
     * duplicates - the exact rule Booking::allPayments() needs (a
     * deposit-stage payment re-parented onto a Billing still carries its
     * original reservation_id/booking_id, so it would otherwise appear in
     * more than one source). Extracted as a pure function (plain
     * array-accessible items in, plain array out - no Eloquent, no DB) so
     * "duplicate payment ids never inflate Total Amount Paid" is provable
     * without a database - see tests/Unit/PaymentMathTest.php. Items
     * without an 'id' (or with a null one) are never deduplicated against
     * anything and are always kept.
     */
    public static function mergeAndDeduplicate(iterable ...$sources): array
    {
        $seenIds = [];
        $result = [];

        foreach ($sources as $source) {
            foreach ($source as $item) {
                $id = $item['id'] ?? null;

                if ($id !== null) {
                    if (isset($seenIds[$id])) {
                        continue;
                    }
                    $seenIds[$id] = true;
                }

                $result[] = $item;
            }
        }

        return $result;
    }

    /**
     * Deterministic chronological order for a Payment Transaction History -
     * primarily by effective payment timestamp ('payment_date', falling
     * back to 'created_at' for a legacy/malformed row with neither), then
     * by id as a stable tiebreaker, so two payments recorded in the same
     * instant always sort in creation order rather than however Eloquent
     * happened to return them. Comparing DateTimeInterface instances
     * directly (not stringified) via <=> - PHP defines <=> natively for
     * DateTimeInterface, and Carbon (what every 'payment_date'/'created_at'
     * cast actually produces) extends DateTime, so this compares real
     * instants, not lexicographic text.
     */
    public static function sortChronologically(iterable $payments): array
    {
        $items = is_array($payments) ? $payments : iterator_to_array($payments);
        $epoch = new \DateTimeImmutable('@0');

        usort($items, function ($a, $b) use ($epoch) {
            $aTime = $a['payment_date'] ?? $a['created_at'] ?? $epoch;
            $bTime = $b['payment_date'] ?? $b['created_at'] ?? $epoch;

            $timeComparison = $aTime <=> $bTime;

            return $timeComparison !== 0 ? $timeComparison : (($a['id'] ?? 0) <=> ($b['id'] ?? 0));
        });

        return $items;
    }
}
