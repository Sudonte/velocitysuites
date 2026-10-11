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

    <!-- Quick-glance summary, scoped to the current filters below -->
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

    <!-- Search and Filter -->
    <x-card bodyClass="card-body" class="mb-4">
        <form method="GET" action="{{ route('manager.reservations.index') }}" class="row g-3">
            <div class="col-sm-6 col-md-4 col-lg-2">
                <label class="form-label small text-muted mb-1">Guest, Booking #, or Reservation #</label>
                <input type="text" name="search" class="form-control" placeholder="Search..." value="{{ request('search') }}">
            </div>
            <input type="hidden" name="tab" value="{{ $tab }}">
            <div class="col-sm-6 col-md-4 col-lg-2">
                <label class="form-label small text-muted mb-1">Status</label>
                <select name="status" class="form-control">
                    <option value="">All Status</option>
                    @if($tab === 'reservations')
                    <option value="PENDING" {{ request('status') === 'PENDING' ? 'selected' : '' }}>Pending (Any Payment Method)</option>
                    <option value="AWAITING_CASH_CONFIRMATION" {{ request('status') === 'AWAITING_CASH_CONFIRMATION' ? 'selected' : '' }}>Awaiting Cash Payment</option>
                    <option value="AWAITING_GCASH_PAYMENT" {{ request('status') === 'AWAITING_GCASH_PAYMENT' ? 'selected' : '' }}>Awaiting GCash Payment</option>
                    <option value="REJECTED_RESERVATION" {{ request('status') === 'REJECTED_RESERVATION' ? 'selected' : '' }}>Rejected</option>
                    <option value="CANCELLED_RESERVATION" {{ request('status') === 'CANCELLED_RESERVATION' ? 'selected' : '' }}>Cancelled</option>
                    @else
                    <option value="ACTIVE_BOOKING" {{ request('status') === 'ACTIVE_BOOKING' ? 'selected' : '' }}>Confirmed (Booked)</option>
                    <option value="CHECKED_IN" {{ request('status') === 'CHECKED_IN' ? 'selected' : '' }}>Checked-In</option>
                    <option value="COMPLETED_BOOKING" {{ request('status') === 'COMPLETED_BOOKING' ? 'selected' : '' }}>Checked-Out</option>
                    <option value="CANCELLED_BOOKING" {{ request('status') === 'CANCELLED_BOOKING' ? 'selected' : '' }}>Cancelled</option>
                    @endif
                </select>
            </div>
            <div class="col-sm-6 col-md-4 col-lg-2">
                <label class="form-label small text-muted mb-1">Payment</label>
                <select name="payment_status" class="form-control">
                    <option value="">All Payments</option>
                    <option value="pending" {{ request('payment_status') === 'pending' ? 'selected' : '' }}>Pending Verification</option>
                    <option value="completed" {{ request('payment_status') === 'completed' ? 'selected' : '' }}>Completed</option>
                    <option value="rejected" {{ request('payment_status') === 'rejected' ? 'selected' : '' }}>Rejected</option>
                    <option value="failed" {{ request('payment_status') === 'failed' ? 'selected' : '' }}>Failed</option>
                </select>
            </div>
            <div class="col-sm-6 col-md-4 col-lg-2">
                <label class="form-label small text-muted mb-1">Payment Method</label>
                <select name="payment_method" class="form-control">
                    <option value="">All Methods</option>
                    <option value="gcash" {{ request('payment_method') === 'gcash' ? 'selected' : '' }}>GCash</option>
                    <option value="cash" {{ request('payment_method') === 'cash' ? 'selected' : '' }}>Cash</option>
                </select>
            </div>
            <div class="col-sm-6 col-md-4 col-lg-2">
                <label class="form-label small text-muted mb-1">Receptionist</label>
                <select name="receptionist" class="form-control">
                    <option value="">All Receptionists</option>
                    @foreach($receptionists as $receptionist)
                        <option value="{{ $receptionist->id }}" {{ (string) request('receptionist') === (string) $receptionist->id ? 'selected' : '' }}>{{ $receptionist->full_name }}</option>
                    @endforeach
                </select>
            </div>
            <div class="col-sm-6 col-md-4 col-lg-2">
                <label class="form-label small text-muted mb-1">From</label>
                <input type="date" name="from" class="form-control" value="{{ request('from') }}">
            </div>
            <div class="col-sm-6 col-md-4 col-lg-2">
                <label class="form-label small text-muted mb-1">To</label>
                <input type="date" name="to" class="form-control" value="{{ request('to') }}">
            </div>
            <div class="col-sm-6 col-md-4 col-lg-2 d-flex align-items-end">
                <button type="submit" class="btn btn-primary w-100">
                    <i class="fas fa-search"></i> Filter
                </button>
            </div>
        </form>
        @if(request('search') || request('type') || request('status') || request('payment_status') || request('payment_method') || request('receptionist') || request('from') || request('to'))
            <div class="mt-3">
                <a href="{{ route('manager.reservations.index', ['tab' => $tab]) }}" class="btn btn-sm btn-outline-secondary">
                    <i class="fas fa-times"></i> Clear Filters
                </a>
            </div>
        @endif
    </x-card>

    <!-- Bookings and Reservations - card list below the md breakpoint,
         full table at md and up. Both render from the same $reservations
         collection, so nothing about the underlying data/pagination
         differs between the two. -->
    @php
        // Switching tabs keeps search/date/payment filters; status values differ per tab, so drop it.
        $tabQuery = fn ($t) => array_merge(request()->except(['page', 'tab', 'type', 'status']), ['tab' => $t]);
    @endphp
    <ul class="nav nav-tabs mb-3" role="tablist">
        <li class="nav-item">
            <a class="nav-link {{ $tab === 'reservations' ? 'active' : '' }}" href="{{ route('manager.reservations.index', $tabQuery('reservations')) }}"
               @if($tab === 'reservations') aria-current="page" @endif>
                <i class="fas fa-calendar-alt"></i> Reservations
                <span class="badge rounded-pill bg-secondary ms-1">{{ $tabCounts['reservations'] }}</span>
            </a>
        </li>
        <li class="nav-item">
            <a class="nav-link {{ $tab === 'bookings' ? 'active' : '' }}" href="{{ route('manager.reservations.index', $tabQuery('bookings')) }}"
               @if($tab === 'bookings') aria-current="page" @endif>
                <i class="fas fa-credit-card"></i> Bookings
                <span class="badge rounded-pill bg-primary ms-1">{{ $tabCounts['bookings'] }}</span>
            </a>
        </li>
    </ul>

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
                    <div class="monitoring-item-row">
                        <span class="text-muted">Handled By</span>
                        <span class="text-end">{{ $item->monitor_handled_by ? implode(', ', $item->monitor_handled_by) : '—' }}</span>
                    </div>
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
                        <th>Handled By</th>
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
                            <td>
                                @forelse($item->monitor_handled_by as $name)
                                    <span class="d-block small">{{ $name }}</span>
                                @empty
                                    <span class="text-muted">&mdash;</span>
                                @endforelse
                            </td>
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
                            <td colspan="9">
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
