<?php

namespace App\Support;

use Illuminate\Validation\ValidationException;

/**
 * Cash is handed over in notes, so the amount RECEIVED can be more than the amount APPLIED to the bill. The applied
 * amount is what gets recorded as the payment (and is capped by the balance, enforced by each entry point under its
 * row lock); the difference is change handed back and is never booked as money paid.
 */
final class CashTender
{
    /**
     * @return array{cash_received: ?float, change_given: ?float}  both null for a non-cash payment
     *
     * @throws ValidationException when less cash was received than is being applied
     */
    public static function resolve(string $method, float $applied, ?float $received): array
    {
        if ($method !== 'cash') {
            return ['cash_received' => null, 'change_given' => null];
        }

        $applied = round($applied, 2);
        // An older client that only sends the applied amount means "exact cash".
        $received = round($received ?? $applied, 2);

        if ($received + 0.004 < $applied) {
            throw ValidationException::withMessages([
                'amount_received' => 'The cash received (₱'.number_format($received, 2).') is less than the amount applied (₱'.number_format($applied, 2).').',
            ]);
        }

        return ['cash_received' => $received, 'change_given' => round(max(0.0, $received - $applied), 2)];
    }
}
