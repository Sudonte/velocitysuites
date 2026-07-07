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
];
