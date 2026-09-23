@props(['status', 'domain' => 'reservation'])

@php
$maps = [
    'reservation' => [
        'AWAITING_CASH_CONFIRMATION' => 'warning', 'AWAITING_GCASH_PAYMENT' => 'warning',
        'REJECTED_RESERVATION' => 'danger', 'CANCELLED_RESERVATION' => 'danger', 'CONVERTED_TO_BOOKING' => 'success',
    ],
    'booking' => [
        'ACTIVE_BOOKING' => 'success', 'CHECKED_IN' => 'primary', 'COMPLETED_BOOKING' => 'secondary', 'CANCELLED_BOOKING' => 'danger',
        'AWAITING_VERIFICATION' => 'warning',
    ],
    'discount_verification' => [
        'not_requested' => 'secondary', 'pending' => 'warning', 'approved' => 'success', 'rejected' => 'danger',
    ],
    'billing' => [
        'pending' => 'secondary', 'partial' => 'warning', 'paid' => 'success',
    ],
    'payment' => [
        // 'rejected' (a receptionist-declined GCash receipt, distinct from
        // 'failed') was missing here - fell back to the generic gray
        // 'secondary' badge instead of reading as a clear negative state.
        'pending' => 'warning', 'completed' => 'success', 'failed' => 'danger', 'rejected' => 'danger',
    ],
    'amenity_request' => [
        // 'approved' distinctly not 'success' yet - staff has accepted the
        // request but hasn't fulfilled it; 'completed' is the true done
        // state (mirrors the staff_password_reset_request convention above).
        'pending' => 'warning', 'approved' => 'info', 'in_progress' => 'primary', 'completed' => 'success', 'rejected' => 'danger',
    ],
    'room' => [
        'available' => 'success', 'occupied' => 'primary', 'reserved' => 'warning', 'maintenance' => 'secondary',
    ],
    'user' => [
        'active' => 'success', 'suspended' => 'danger',
    ],
    'staff_password_reset_request' => [
        // 'approved' distinctly not 'success' - the temp password has been
        // assigned but the staff member hasn't actually completed the
        // change yet; 'completed' is the true done state.
        'pending' => 'warning', 'approved' => 'info', 'rejected' => 'danger', 'completed' => 'success',
    ],
    'active_flag' => [
        // generic active/inactive used by promotions and amenities catalog
        'active' => 'success', 'inactive' => 'secondary',
    ],
    'announcement_status' => [
        'draft' => 'secondary', 'published' => 'success', 'archived' => 'dark',
    ],
    // The Booking-level Payment Summary status (PaymentMath::paymentStatus())
    // - distinct from the 'billing'/'payment' domains above, which are the
    // raw DB enum values on billings.billing_status/payments.payment_status.
    'booking_payment_status' => [
        'PENDING' => 'secondary', 'PARTIALLY_PAID' => 'warning', 'PAID' => 'success',
    ],
    // Which receipt document a payment/billing qualifies for - see
    // Payment::preCheckoutReceiptType()/Billing::isOfficialReceiptAvailable().
    // Deliberately 3 distinct colors so a receptionist never mistakes a
    // pre-checkout Partial/Full-Payment receipt for the checkout-only
    // Official one.
    'receipt_type' => [
        'PARTIAL_RECEIPT' => 'info', 'FULL_PAYMENT_RECEIPT' => 'primary', 'OFFICIAL_RECEIPT' => 'success',
    ],
    // Payment::$appends['verification_status'] - only meaningful for a
    // guest-submitted GCash payment; a receptionist-recorded checkout
    // payment has none (falls back to the 'payment' domain's plain
    // payment_status badge instead - see payment-history.blade.php).
    'verification_status' => [
        'verified' => 'success', 'pending_verification' => 'warning', 'rejected' => 'danger',
    ],
];
$labels = [
    'reservation' => [
        'AWAITING_CASH_CONFIRMATION' => 'Awaiting Cash Payment', 'AWAITING_GCASH_PAYMENT' => 'Awaiting GCash Payment',
        'REJECTED_RESERVATION' => 'Rejected', 'CANCELLED_RESERVATION' => 'Cancelled',
        'CONVERTED_TO_BOOKING' => 'Converted',
    ],
    'booking' => [
        'ACTIVE_BOOKING' => 'Confirmed', 'CHECKED_IN' => 'Checked In', 'COMPLETED_BOOKING' => 'Checked Out',
        'CANCELLED_BOOKING' => 'Cancelled', 'AWAITING_VERIFICATION' => 'For Verification',
    ],
    'announcement_status' => [
        'archived' => 'Unpublished',
    ],
    'booking_payment_status' => [
        'PENDING' => 'Pending', 'PARTIALLY_PAID' => 'Partially Paid', 'PAID' => 'Paid',
    ],
    // Required labels per PAYMENT_RECEIPT_HISTORY_BACKEND_SPEC.md - a
    // verified 100% payment made before checkout is a "Payment Receipt",
    // NEVER "Official Payment Receipt" (that label is checkout-only).
    'receipt_type' => [
        'PARTIAL_RECEIPT' => 'Partial Payment Receipt',
        'FULL_PAYMENT_RECEIPT' => 'Payment Receipt',
        'OFFICIAL_RECEIPT' => 'Official Payment Receipt',
    ],
    'verification_status' => [
        'verified' => 'Verified', 'pending_verification' => 'Pending Verification', 'rejected' => 'Rejected',
    ],
];
$color = $maps[$domain][$status] ?? 'secondary';
$label = $labels[$domain][$status] ?? ucfirst(str_replace('_', ' ', $status));
@endphp

<span class="badge bg-{{ $color }}">{{ $label }}</span>
