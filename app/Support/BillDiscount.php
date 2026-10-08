<?php

namespace App\Support;

use App\Models\Discount;

/**
 * THE one calculation of what a discount takes off a bill. Both the guest's estimate
 * (BookingService::quoteRoomCharge) and the receptionist's Billing / Check-out
 * (CheckOutController::applyDiscount) call this, so they can never disagree. The receipt prints the
 * Billing row's stored discount, i.e. the very number this produced.
 *
 * The discount applies to the WHOLE bill: room charges plus everything added on to the stay (paid amenities,
 * extra-guest fees, additional charges) - not the room charge alone. A percentage is taken of that whole,
 * a fixed amount is taken once from it; the result never exceeds the bill. No VAT is involved anywhere here.
 */
final class BillDiscount
{
    public static function amount(?Discount $discount, float $roomCharge, float $addOns): float
    {
        if ($discount === null) {
            return 0.0;
        }

        return $discount->amountOff(max(0.0, $roomCharge) + max(0.0, $addOns));
    }
}
