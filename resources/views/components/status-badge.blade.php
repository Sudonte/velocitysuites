@props(['status', 'domain' => 'reservation'])

@php
$maps = [
    'reservation' => [
        'AWAITING_CASH_CONFIRMATION' => 'warning', 'AWAITING_GCASH_PAYMENT' => 'warning',
        'REJECTED_RESERVATION' => 'danger', 'CANCELLED_RESERVATION' => 'danger', 'CONVERTED_TO_BOOKING' => 'success',
    ],
    'booking' => [
        'ACTIVE_BOOKING' => 'success', 'CHECKED_IN' => 'primary', 'COMPLETED_BOOKING' => 'secondary', 'CANCELLED_BOOKING' => 'danger',
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
];
$labels = [
    'reservation' => [
        'AWAITING_CASH_CONFIRMATION' => 'Awaiting Cash Payment', 'AWAITING_GCASH_PAYMENT' => 'Awaiting GCash Payment',
        'REJECTED_RESERVATION' => 'Rejected', 'CANCELLED_RESERVATION' => 'Cancelled',
        'CONVERTED_TO_BOOKING' => 'Converted',
    ],
    'booking' => [
        'ACTIVE_BOOKING' => 'Confirmed', 'CHECKED_IN' => 'Checked In', 'COMPLETED_BOOKING' => 'Checked Out',
        'CANCELLED_BOOKING' => 'Cancelled',
    ],
    'announcement_status' => [
        'archived' => 'Unpublished',
    ],
];
$color = $maps[$domain][$status] ?? 'secondary';
$label = $labels[$domain][$status] ?? ucfirst(str_replace('_', ' ', $status));
@endphp

<span class="badge bg-{{ $color }}">{{ $label }}</span>
