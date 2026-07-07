@props(['status', 'domain' => 'reservation'])

@php
$maps = [
    'reservation' => [
        'pending' => 'warning', 'confirmed' => 'success', 'checked_in' => 'primary',
        'checked_out' => 'secondary', 'cancelled' => 'danger',
    ],
    'billing' => [
        'pending' => 'secondary', 'partial' => 'warning', 'paid' => 'success',
    ],
    'payment' => [
        'pending' => 'warning', 'completed' => 'success', 'failed' => 'danger',
    ],
    'amenity_request' => [
        'pending' => 'warning', 'approved' => 'success', 'rejected' => 'danger',
    ],
    'room' => [
        'available' => 'success', 'occupied' => 'primary', 'reserved' => 'warning', 'maintenance' => 'secondary',
    ],
    'user' => [
        'active' => 'success', 'suspended' => 'danger',
    ],
    'active_flag' => [
        // generic active/inactive used by promotions and amenities catalog
        'active' => 'success', 'inactive' => 'secondary',
    ],
];
$color = $maps[$domain][$status] ?? 'secondary';
@endphp

<span class="badge bg-{{ $color }}">{{ ucfirst(str_replace('_', ' ', $status)) }}</span>
