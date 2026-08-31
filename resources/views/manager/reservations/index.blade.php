@extends('layouts.app')

@section('title', 'Booking and Monitoring - Manager')

@section('content')
<div class="container-fluid py-4">
    <x-page-header icon="fas fa-calendar-alt" title="Booking and Monitoring"
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
            <div class="col-sm-6 col-md-4 col-lg-2">
                <label class="form-label small text-muted mb-1">Type</label>
                <select name="type" class="form-control">
                    <option value="">All Types</option>
                    <option value="booking" {{ request('type') === 'booking' ? 'selected' : '' }}>Booking</option>
                    <option value="reservation" {{ request('type') === 'reservation' ? 'selected' : '' }}>Reservation</option>
                </select>
            </div>
            <div class="col-sm-6 col-md-4 col-lg-2">
                <label class="form-label small text-muted mb-1">Status</label>
                <select name="status" class="form-control">
                    <option value="">All Status</option>
                    <option value="pending_review" {{ request('status') === 'pending_review' ? 'selected' : '' }}>Pending Review</option>
                    <option value="ready_for_booking" {{ request('status') === 'ready_for_booking' ? 'selected' : '' }}>Ready for Booking</option>
                    <option value="confirmed" {{ request('status') === 'confirmed' ? 'selected' : '' }}>Confirmed (Booked)</option>
                    <option value="checked_in" {{ request('status') === 'checked_in' ? 'selected' : '' }}>Checked-In</option>
                    <option value="checked_out" {{ request('status') === 'checked_out' ? 'selected' : '' }}>Checked-Out</option>
                    <option value="rejected" {{ request('status') === 'rejected' ? 'selected' : '' }}>Rejected</option>
                    <option value="cancelled" {{ request('status') === 'cancelled' ? 'selected' : '' }}>Cancelled</option>
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
                <a href="{{ route('manager.reservations.index') }}" class="btn btn-sm btn-outline-secondary">
                    <i class="fas fa-times"></i> Clear Filters
                </a>
            </div>
        @endif
    </x-card>

    <!-- Bookings and Reservations - card list below the md breakpoint,
         full table at md and up. Both render from the same $reservations
         collection, so nothing about the underlying data/pagination
         differs between the two. -->
    <x-card title="All Bookings and Reservations" icon="fas fa-list" bodyClass="monitoring-table-wrap">
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
                        <span class="fw-bold">{{ $item->monitor_number_label }}</span>
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
                        <span class="text-muted">Status</span>
                        <x-status-badge :status="$item->monitor_status_value" :domain="$item->monitor_status_domain" />
                    </div>
                    <a href="{{ $item->monitor_show_route }}" class="btn btn-sm btn-primary w-100 mt-2">
                        <i class="fas fa-eye"></i> View Details
                    </a>
                </div>
            @empty
                <x-empty-state icon="fas fa-calendar-alt" message="No bookings or reservations found." />
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
                        <th>Status</th>
                        <th>Action</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse($reservations as $item)
                        <tr>
                            <td class="fw-bold">{{ $item->monitor_number_label }}</td>
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
                                <x-status-badge :status="$item->monitor_status_value" :domain="$item->monitor_status_domain" />
                            </td>
                            <td>
                                <a href="{{ $item->monitor_show_route }}" class="btn btn-sm btn-primary">
                                    <i class="fas fa-eye"></i>
                                </a>
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="8">
                                <x-empty-state icon="fas fa-calendar-alt" message="No bookings or reservations found." />
                            </td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
        <x-slot:footer>
            {{ $reservations->links() }}
        </x-slot:footer>
    </x-card>
</div>
@endsection
