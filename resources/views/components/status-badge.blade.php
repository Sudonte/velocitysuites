@props(['status', 'domain' => 'reservation'])

@php
$maps = [
    'reservation' => [
        'pending_review' => 'warning', 'ready_for_booking' => 'info', 'rejected' => 'danger',
        'cancelled' => 'danger', 'converted' => 'success',
    ],
    'booking' => [
        'confirmed' => 'success', 'checked_in' => 'primary', 'checked_out' => 'secondary', 'cancelled' => 'danger',
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
    'announcement_status' => [
        'archived' => 'Unpublished',
    ],
];
$color = $maps[$domain][$status] ?? 'secondary';
$label = $labels[$domain][$status] ?? ucfirst(str_replace('_', ' ', $status));
@endphp

<span class="badge bg-{{ $color }}">{{ $label }}</span>
