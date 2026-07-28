@extends('layouts.app')

@section('title', 'Manager Dashboard')

@section('content')
<div class="container-fluid py-4">
    <x-page-header icon="fas fa-chart-pie" title="Manager Dashboard" subtitle="Welcome, {{ auth()->user()->full_name }}!" />

    <!-- KPI Cards -->
    <div class="row mb-4">
        <div class="col-md-3 mb-3">
            <x-stat-card icon="fas fa-building" label="Total Rooms" :value="$totalRooms" color="secondary" />
        </div>
        <div class="col-md-3 mb-3">
            <x-stat-card icon="fas fa-bed" label="Occupancy Rate" value="{{ $occupancyRate }}%" color="primary" />
        </div>
        <div class="col-md-3 mb-3">
            <x-stat-card icon="fas fa-sign-in-alt" label="Today's Check-Ins" :value="$todayCheckIns" color="success" />
        </div>
        <div class="col-md-3 mb-3">
            <x-stat-card icon="fas fa-sign-out-alt" label="Today's Check-Outs" :value="$todayCheckOuts" color="info" />
        </div>
    </div>

    <div class="row mb-4">
        <div class="col-md-3 mb-3">
            <x-stat-card icon="fas fa-door-open" label="Available Rooms" :value="$availableRooms" color="success" />
        </div>
        <div class="col-md-3 mb-3">
            <x-stat-card icon="fas fa-bed" label="Occupied Rooms" :value="$occupiedRooms" color="primary" />
        </div>
        <div class="col-md-3 mb-3">
            <x-stat-card icon="fas fa-users" label="In-House Guests" :value="$inHouseGuests" color="warning" />
        </div>
        <div class="col-md-3 mb-3">
            <x-stat-card icon="fas fa-money-bill-wave" label="Monthly Revenue" value="₱{{ number_format($monthlyRevenue, 2) }}" color="success" />
        </div>
    </div>

    <!-- Reservation / Booking KPIs -->
    <div class="row mb-4">
        <div class="col-md-4 mb-3">
            <x-stat-card icon="fas fa-calendar-alt" label="Total Reservations" :value="$totalReservations" color="secondary" />
        </div>
        <div class="col-md-4 mb-3">
            <x-stat-card icon="fas fa-credit-card" label="Total Bookings" :value="$totalBookings" color="primary" />
        </div>
        <div class="col-md-4 mb-3">
            <x-stat-card icon="fas fa-hourglass-half" label="Pending Payment Verifications" :value="$pendingPaymentVerifications" color="warning" />
        </div>
    </div>

    <!-- Recent Reservations -->
    <div class="row">
        <div class="col-lg-8">
            <x-card title="Recent Reservations" icon="fas fa-calendar-alt" bodyClass="table-responsive">
                <table class="table table-hover mb-0">
                    <thead>
                        <tr>
                            <th>Guest</th>
                            <th>Room</th>
                            <th>Check-In</th>
                            <th>Check-Out</th>
                            <th>Status</th>
                            <th>Action</th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse($recentReservations as $reservation)
                            <tr>
                                <td>{{ $reservation->stay_guest_full_name ?? $reservation->guest->user->full_name ?? 'N/A' }}</td>
                                <td>{{ $reservation->room->room_number ?? 'N/A' }}</td>
                                <td>{{ $reservation->check_in->format('M d, Y') }}</td>
                                <td>{{ $reservation->check_out->format('M d, Y') }}</td>
                                <td><x-status-badge :status="$reservation->status" domain="reservation" /></td>
                                <td>
                                    <a href="{{ route('manager.reservations.show', $reservation) }}" class="btn btn-sm btn-primary">
                                        <i class="fas fa-eye"></i>
                                    </a>
                                </td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="6">
                                    <x-empty-state icon="fas fa-calendar-alt" message="No reservations yet." />
                                </td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </x-card>
        </div>

        <!-- Top Room Types -->
        <div class="col-lg-4">
            <x-card title="Top Room Types" icon="fas fa-star" bodyClass="card-body">
                @forelse($topRoomTypes as $room)
                    <div class="d-flex justify-content-between align-items-center mb-3 pb-2" style="border-bottom: 1px solid #f0f0f0;">
                        <div>
                            <strong>{{ $room->room_name }}</strong><br>
                            <small class="text-muted">{{ $room->roomType->name }}</small>
                        </div>
                        <span class="badge badge-brand">{{ $room->reservations_count }} bookings</span>
                    </div>
                @empty
                    <x-empty-state icon="fas fa-star" message="No data yet." />
                @endforelse
            </x-card>
        </div>
    </div>
</div>

@include('components.auto-refresh')
@endsection