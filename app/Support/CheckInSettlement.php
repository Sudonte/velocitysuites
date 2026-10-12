<?php

namespace App\Support;

use App\Models\Billing;
use Carbon\Carbon;

/**
 * What was settled when the guest checked in (CheckInController::settleAtCheckIn()),
 * so check-out and the receipts can show "Paid at check-in" apart from what
 * was paid at check-out. Display only - every total still comes from the
 * payments themselves.
 */
final class CheckInSettlement
{
    public static function settledAt(?Billing $billing): ?Carbon
    {
        return $billing?->checkin_settled_at ? Carbon::parse($billing->checkin_settled_at) : null;
    }

    /** True when the item was on the bill when the guest paid at check-in. */
    public static function wasOnCheckInBill(?Billing $billing, $createdAt): bool
    {
        $at = self::settledAt($billing);

        return $at !== null && $createdAt !== null && Carbon::parse($createdAt)->lte($at);
    }

    /** Web label for a payment-history row (the API's transaction_type values are left as they are). */
    public static function label(array $tx, string $default): string
    {
        $ref = (string) ($tx['reference_number'] ?? '');
        if (str_starts_with($ref, 'CHECKIN-')) {
            return 'Paid at Check-in';
        }
        if (str_starts_with($ref, 'CHANGE-RETURNED-')) {
            return 'Change Returned at Check-in';
        }

        return $default;
    }

    /**
     * Completed payments split into "by check-in" and "at check-out", or null
     * when the stay wasn't settled at check-in (checked in before this existed).
     *
     * @return array{at_check_in: float, at_check_out: float}|null
     */
    public static function totals(?Billing $billing, array $transactions): ?array
    {
        $at = self::settledAt($billing);
        if ($at === null) {
            return null;
        }

        $checkIn = 0.0;
        $checkOut = 0.0;
        foreach ($transactions as $tx) {
            if (($tx['payment_status'] ?? null) !== 'completed') {
                continue;
            }
            $date = $tx['payment_date'] ?? null;
            if ($date === null || Carbon::parse($date)->lte($at)) {
                $checkIn += (float) $tx['amount_paid'];
            } else {
                $checkOut += (float) $tx['amount_paid'];
            }
        }

        return ['at_check_in' => round($checkIn, 2), 'at_check_out' => round($checkOut, 2)];
    }
}
