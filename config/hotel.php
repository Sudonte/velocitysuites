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
    | Minimum Payment Ratio
    |--------------------------------------------------------------------------
    |
    | Minimum fraction of the quoted total a guest must pay on the Book &
    | Pay flow (website + mobile app), enforced server-side. Replaces the
    | old Partial/Full toggle with a single amount field the guest can pay
    | anywhere between this minimum and the full total - simpler, and
    | avoids a second payment-type concept the Billing/Payment modules
    | would otherwise need to understand downstream.
    |
    */

    'minimum_payment_ratio' => 0.5,
];
