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

    'minimum_payment_ratio' => 0.20,
    'maximum_payment_ratio' => 0.50,

    /*
    |--------------------------------------------------------------------------
    | Reservation Payment Deadline
    |--------------------------------------------------------------------------
    |
    | A freshly-created Pay Later/Pay Now reservation whose check-in date is
    | at least 2 days out must be paid (any amount, cash or GCash) within
    | this many hours of creation, or it's automatically rejected (see
    | Reservation::getPaymentDeadlineAttribute(), ReservationWorkflowService::
    | expireUnpaid(), reservations:expire-unpaid). A reservation checking in
    | tomorrow is exempt - there isn't enough time left for a real 48-hour
    | window, so it never gets a deadline at all and simply waits for a
    | walk-in/receptionist-processed payment like before.
    |
    */

    'payment_deadline_hours' => 48,

    /*
    |--------------------------------------------------------------------------
    | No-Show Cutoff
    |--------------------------------------------------------------------------
    |
    | A reservation that's still unpaid/unconverted once check_in's date has
    | reached this hour of day, plus this many grace hours, is automatically
    | cancelled as a No-Show (see ReservationWorkflowService::processNoShow(),
    | reservations:process-no-shows). Applies to every still-active
    | reservation regardless of whether it ever had a 48-hour payment
    | deadline - short-notice reservations (checking in tomorrow or the day
    | after, exempt from payment_deadline_hours above) are only ever
    | resolved by this check.
    |
    */

    'no_show_checkin_hour' => 14,
    'no_show_grace_hours' => 5,

    /*
    |--------------------------------------------------------------------------
    | Staff Password Reset Request Expiry
    |--------------------------------------------------------------------------
    |
    | How many hours a Manager/Receptionist password-reset request stays
    | "pending" before it's treated as expired (computed at read time, not
    | a scheduled job - see StaffPasswordResetRequest::isExpired()). An
    | admin can still act on an expired request; it's purely a display
    | signal that it's gone stale.
    |
    */

    'staff_password_reset_expiry_hours' => 48,
];
