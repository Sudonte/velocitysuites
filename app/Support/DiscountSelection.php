<?php

namespace App\Support;

use App\Models\Discount;

/**
 * Resolves the discount a guest picked on the ID-verification step into a
 * Discount row, enforcing server-side that it is one the Discount module
 * currently offers (status = active). Accepts either the new discount_id or,
 * for older app builds that only send the discount's name in id_card_type,
 * that name (first active match). "None"/empty means no discount.
 */
class DiscountSelection
{
    /**
     * @param  int|string|null  $discountId
     * @param  int|null  $keepIfUnchangedId  an edit may keep its current discount even if the admin has since
     *                                       deactivated it or its validity window has ended - it just can't be newly picked
     * @param  bool  $enforceValidity  true (default) when a guest is creating/editing: the discount must be active AND
     *                                 valid today. false for pricing/billing of a booking that already exists - a discount
     *                                 that was valid when the guest booked is still honored after it expires
     * @return array{0: ?Discount, 1: ?string} [discount, error message]
     */
    public static function resolve($discountId, ?string $legacyName, ?int $keepIfUnchangedId = null, bool $enforceValidity = true): array
    {
        if ($discountId !== null && $discountId !== '') {
            $discount = Discount::find((int) $discountId);
            if (! $discount) {
                return [null, 'The selected discount does not exist.'];
            }
            $kept = $discount->id === $keepIfUnchangedId;
            if (! $kept && ($discount->status !== 'active' || ($enforceValidity && ! $discount->isValidOn()))) {
                return [null, 'The selected discount is no longer available.'];
            }

            return [$discount, null];
        }

        if ($legacyName !== null && $legacyName !== '' && strcasecmp($legacyName, 'None') !== 0) {
            $query = $enforceValidity ? Discount::offered() : Discount::where('status', 'active');
            $discount = $query->where('name', $legacyName)->orderBy('id')->first();
            if (! $discount) {
                return [null, 'The selected discount is no longer available.'];
            }

            return [$discount, null];
        }

        return [null, null];
    }
}
