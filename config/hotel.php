<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Extra Guest Fee Rate
    |--------------------------------------------------------------------------
    |
    | Per-guest charge applied on a billing record when the reservation's
    | `number_of_guests` exceeds the room's `room_capacity`. Multiplied by
    | the number of extra guests when a bill is generated at check-out.
    |
    */

    'extra_guest_fee_rate' => 500,

    /*
    |--------------------------------------------------------------------------
    | Deposit Payment Range
    |--------------------------------------------------------------------------
    |
    | A guest can never pay the full quoted total upfront - discounts
    | aren't applied until a receptionist verifies them at billing, and
    | the final amount (extra guest fees, amenities, additional charges)
    | isn't known until checkout. The initial deposit (website Pay Now/
    | Pay Later amount, mobile app payment screen) is capped to a small
    | range of the undiscounted room total instead: 10% minimum,
    | 20% maximum. Enforced server-side (Guest\ReservationController,
    | Api\PaymentController) so it can't be bypassed by either client.
    |
    */

    'minimum_payment_ratio' => 0.10,
    'maximum_payment_ratio' => 0.20,
];
