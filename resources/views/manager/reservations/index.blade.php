@extends('layouts.app')

@section('title', 'Reservation and Booking Monitoring - Manager')

@section('content')
<div class="container-fluid py-4">
    <x-page-header icon="fas fa-calendar-alt" title="Reservation and Booking Monitoring"
        subtitle="Monitor guest bookings and reservations - status, dates, and rooms." />

    @if (session('success'))
        <div class="alert alert-success alert-dismissible fade show" role="alert">
            <i class="fas fa-check-circle"></i> {{ session('success') }}
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
    @endif

    <!-- Hotel-wide summary (not affected by the tab filters below) -->
    <div class="row g-3 mb-4">
        <div class="col-6 col-md-3">
            <x-stat-card icon="fas fa-list" label="Total" :value="$summaryTotal" color="secondary" />
        </div>
        <div class="col-6 col-md-3">
            <x-stat-card icon="fas fa-credit-card" label="Bookings" :value="$summaryBookingCount" color="primary" />
        </div>
        <div class="col-6 col-md-3">
            <x-stat-card icon="fas fa-calendar-alt" label="Reservations" :value="$summaryReservationCount" color="info" />
        </div>
        <div class="col-6 col-md-3">
            <x-stat-card icon="fas fa-hourglass-half" label="Active / Pending" :value="$summaryPendingCount" color="warning" />
        </div>
    </div>

    @include('monitoring.partials.tabs-and-filters', ['routeName' => 'manager.reservations.index', 'tab' => $tab, 'tabCounts' => $tabCounts,
        'filters' => $filters, 'statusOptions' => $statusOptions, 'receptionists' => $receptionists])

    <x-card :title="$tab === 'bookings' ? 'Bookings' : 'Reservations'" :icon="$tab === 'bookings' ? 'fas fa-credit-card' : 'fas fa-calendar-alt'" bodyClass="monitoring-table-wrap">
        <div class="d-md-none monitoring-card-list">
            @forelse($reservations as $item)
                <div class="monitoring-item-card">
                    <div class="d-flex justify-content-between align-items-start gap-2 mb-2">
                        <div class="d-flex align-items-center gap-2">
                            <div class="monitoring-avatar monitoring-avatar-sm">
                                {{ strtoupper(substr($item->monitor_guest_name, 0, 1)) }}
                            </div>
                            <div>
                                <div class="fw-bold">{{ $item->monitor_guest_name }}</div>
                                <small class="text-muted">{{ $item->monitor_guest_email }}</small>
                            </div>
                        </div>
                        @if($item->monitor_badge === 'Booking')
                            <span class="badge bg-primary"><i class="fas fa-credit-card"></i> Booking</span>
                        @else
                            <span class="badge bg-secondary"><i class="fas fa-calendar-alt"></i> Reservation</span>
                        @endif
                    </div>
                    <div class="monitoring-item-row">
                        <span class="text-muted">Ref</span>
                        <span class="fw-bold text-end">{{ $item->monitor_number_label }}@if($item->monitor_origin_label)<small class="d-block text-muted fw-normal">{{ $item->monitor_origin_label }}</small>@endif</span>
                    </div>
                    <div class="monitoring-item-row">
                        <span class="text-muted">Room</span>
                        <span>{{ $item->monitor_room_label }}{{ $item->monitor_assigned_room ? ' &middot; Room '.$item->monitor_assigned_room : '' }}</span>
                    </div>
                    <div class="monitoring-item-row">
                        <span class="text-muted">Dates</span>
                        <span>{{ $item->check_in->format('M d') }}&ndash;{{ $item->check_out->format('M d, Y') }} ({{ $item->number_of_nights }}n)</span>
                    </div>
                    @if($tab === 'bookings')
                    <div class="monitoring-item-row">
                        <span class="text-muted">Handled By</span>
                        <span class="text-end">{{ $item->monitor_handled_by ? implode(', ', $item->monitor_handled_by) : '—' }}</span>
                    </div>
                    @endif
                    <div class="monitoring-item-row">
                        <span class="text-muted">Status</span>
                        <x-status-badge :status="$item->monitor_status_value" :domain="$item->monitor_status_domain" />
                    </div>
                    <a href="{{ $item->monitor_show_route }}" class="btn btn-outline-primary btn-sm w-100 mt-2 btn-icon" title="View Details" aria-label="View Details">
                        <i class="fas fa-eye"></i></a>
                </div>
            @empty
                <x-empty-state icon="fas fa-calendar-alt" :message="$tab === 'bookings' ? 'No bookings found.' : 'No reservations found.'" />
            @endforelse
        </div>

        <div class="d-none d-md-block table-responsive">
            <table class="table table-hover mb-0 monitoring-table">
                <thead>
                    <tr>
                        <th>#</th>
                        <th>Guest</th>
                        <th>Type</th>
                        <th class="d-none d-md-table-cell">Room</th>
                        <th>Dates</th>
                        <th class="d-none d-lg-table-cell">Guests</th>
                        {{-- Same staff the Receptionist filter matches (who verified the stay or its payments). --}}
                        @if($tab === 'bookings')<th>Handled By</th>@endif
                        <th>Status</th>
                        <th>Action</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse($reservations as $item)
                        <tr>
                            <td class="fw-bold">
                                {{ $item->monitor_number_label }}
                                @if($item->monitor_origin_label)
                                    <small class="d-block text-muted fw-normal">{{ $item->monitor_origin_label }}</small>
                                @endif
                            </td>
                            <td>
                                <div class="d-flex align-items-center gap-2">
                                    <div class="monitoring-avatar monitoring-avatar-sm">
                                        {{ strtoupper(substr($item->monitor_guest_name, 0, 1)) }}
                                    </div>
                                    <div>
                                        {{ $item->monitor_guest_name }}
                                        <small class="d-block text-muted">{{ $item->monitor_guest_email }}</small>
                                    </div>
                                </div>
                            </td>
                            <td>
                                {{-- monitor_badge distinguishes a genuinely independent direct Booking
                                     (reservation_id null) from a Reservation (which may itself have
                                     already converted into a Booking) - see monitor_type/monitor_badge,
                                     set in the controller so this view never has to branch on model class. --}}
                                @if($item->monitor_badge === 'Booking')
                                    <span class="badge bg-primary"><i class="fas fa-credit-card"></i> Booking</span>
                                @else
                                    <span class="badge bg-secondary"><i class="fas fa-calendar-alt"></i> Reservation</span>
                                @endif
                            </td>
                            <td class="d-none d-md-table-cell">
                                {{ $item->monitor_room_label }}
                                @if($item->monitor_assigned_room)
                                    <br><small class="text-muted">Room {{ $item->monitor_assigned_room }}</small>
                                @endif
                            </td>
                            <td>
                                {{ $item->check_in->format('M d') }} &ndash; {{ $item->check_out->format('M d, Y') }}<br>
                                <small class="text-muted">{{ $item->number_of_nights }} night{{ $item->number_of_nights === 1 ? '' : 's' }}</small>
                            </td>
                            <td class="d-none d-lg-table-cell">{{ $item->monitor_type === 'booking' ? $item->adults + $item->children : $item->number_of_guests }}</td>
                            @if($tab === 'bookings')
                            <td>
                                @forelse($item->monitor_handled_by as $name)
                                    <span class="d-block small">{{ $name }}</span>
                                @empty
                                    <span class="text-muted">&mdash;</span>
                                @endforelse
                            </td>
                            @endif
                            <td>
                                <x-status-badge :status="$item->monitor_status_value" :domain="$item->monitor_status_domain" />
                            </td>
                            <td>
                                <a href="{{ $item->monitor_show_route }}" class="btn btn-outline-primary btn-sm btn-icon" title="View" aria-label="View">
                                    <i class="fas fa-eye"></i></a>
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="{{ $tab === 'bookings' ? 9 : 8 }}">
                                <x-empty-state icon="fas fa-calendar-alt" :message="$tab === 'bookings' ? 'No bookings found.' : 'No reservations found.'" />
                            </td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
        <x-slot:footer>
            <x-pagination :paginator="$reservations" />
        </x-slot:footer>
    </x-card>
</div>
@endsection
