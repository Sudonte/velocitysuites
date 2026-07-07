@extends('layouts.app')

@section('title', 'Manager Dashboard')

@section('content')
<div class="container-fluid py-4">
    <div class="row mb-4">
        <div class="col-12">
            <h1 class="mb-0">
                <i class="fas fa-chart-pie"></i> Manager Dashboard
            </h1>
            <p class="text-muted">Welcome, {{ auth()->user()->name }}!</p>
        </div>
    </div>

    <!-- KPI Cards -->
    <div class="row mb-4">
        <div class="col-md-3 mb-3">
            <div class="card border-0 shadow-sm">
                <div class="card-body">
                    <div class="d-flex justify-content-between align-items-center">
                        <div>
                            <p class="text-muted mb-2">Total Rooms</p>
                            <h3 class="mb-0" style="color: #6f42c1;">{{ $totalRooms }}</h3>
                        </div>
                        <div style="font-size: 2.5rem; color: #6f42c1; opacity: 0.3;">
                            <i class="fas fa-building"></i>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <div class="col-md-3 mb-3">
            <div class="card border-0 shadow-sm">
                <div class="card-body">
                    <div class="d-flex justify-content-between align-items-center">
                        <div>
                            <p class="text-muted mb-2">Occupancy Rate</p>
                            <h3 class="mb-0" style="color: #C1121F;">{{ $occupancyRate }}%</h3>
                        </div>
                        <div style="font-size: 2.5rem; color: #C1121F; opacity: 0.3;">
                            <i class="fas fa-bed"></i>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <div class="col-md-3 mb-3">
            <div class="card border-0 shadow-sm">
                <div class="card-body">
                    <div class="d-flex justify-content-between align-items-center">
                        <div>
                            <p class="text-muted mb-2">Today's Check-Ins</p>
                            <h3 class="mb-0" style="color: #28a745;">{{ $todayCheckIns }}</h3>
                        </div>
                        <div style="font-size: 2.5rem; color: #28a745; opacity: 0.3;">
                            <i class="fas fa-sign-in-alt"></i>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <div class="col-md-3 mb-3">
            <div class="card border-0 shadow-sm">
                <div class="card-body">
                    <div class="d-flex justify-content-between align-items-center">
                        <div>
                            <p class="text-muted mb-2">Today's Check-Outs</p>
                            <h3 class="mb-0" style="color: #17a2b8;">{{ $todayCheckOuts }}</h3>
                        </div>
                        <div style="font-size: 2.5rem; color: #17a2b8; opacity: 0.3;">
                            <i class="fas fa-sign-out-alt"></i>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <div class="row mb-4">
        <div class="col-md-3 mb-3">
            <div class="card border-0 shadow-sm">
                <div class="card-body">
                    <div class="d-flex justify-content-between align-items-center">
                        <div>
                            <p class="text-muted mb-2">Available Rooms</p>
                            <h3 class="mb-0" style="color: #28a745;">{{ $availableRooms }}</h3>
                        </div>
                        <div style="font-size: 2.5rem; color: #28a745; opacity: 0.3;">
                            <i class="fas fa-door-open"></i>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <div class="col-md-3 mb-3">
            <div class="card border-0 shadow-sm">
                <div class="card-body">
                    <div class="d-flex justify-content-between align-items-center">
                        <div>
                            <p class="text-muted mb-2">Occupied Rooms</p>
                            <h3 class="mb-0" style="color: #C1121F;">{{ $occupiedRooms }}</h3>
                        </div>
                        <div style="font-size: 2.5rem; color: #C1121F; opacity: 0.3;">
                            <i class="fas fa-bed"></i>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <div class="col-md-3 mb-3">
            <div class="card border-0 shadow-sm">
                <div class="card-body">
                    <div class="d-flex justify-content-between align-items-center">
                        <div>
                            <p class="text-muted mb-2">In-House Guests</p>
                            <h3 class="mb-0" style="color: #ffc107;">{{ $inHouseGuests }}</h3>
                        </div>
                        <div style="font-size: 2.5rem; color: #ffc107; opacity: 0.3;">
                            <i class="fas fa-users"></i>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <div class="col-md-3 mb-3">
            <div class="card border-0 shadow-sm">
                <div class="card-body">
                    <div class="d-flex justify-content-between align-items-center">
                        <div>
                            <p class="text-muted mb-2">Monthly Revenue</p>
                            <h3 class="mb-0" style="color: #28a745;">₱{{ number_format($monthlyRevenue, 2) }}</h3>
                        </div>
                        <div style="font-size: 2.5rem; color: #28a745; opacity: 0.3;">
                            <i class="fas fa-money-bill-wave"></i>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Recent Reservations -->
    <div class="row">
        <div class="col-lg-8">
            <div class="card border-0 shadow-sm">
                <div class="card-header" style="background-color: #C1121F; color: white;">
                    <h5 class="mb-0">
                        <i class="fas fa-calendar-alt"></i> Recent Reservations
                    </h5>
                </div>
                <div class="table-responsive">
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
                                    <td>{{ $reservation->guest->user->name ?? 'N/A' }}</td>
                                    <td>{{ $reservation->room->room_number ?? 'N/A' }}</td>
                                    <td>{{ $reservation->check_in->format('M d, Y') }}</td>
                                    <td>{{ $reservation->check_out->format('M d, Y') }}</td>
                                    <td>
                                        <span class="badge bg-{{ $reservation->status === 'confirmed' ? 'success' : ($reservation->status === 'checked_in' ? 'primary' : ($reservation->status === 'checked_out' ? 'secondary' : ($reservation->status === 'cancelled' ? 'danger' : 'warning'))) }}">
                                            {{ ucfirst(str_replace('_', '-', $reservation->status)) }}
                                        </span>
                                    </td>
                                    <td>
                                        <a href="{{ route('manager.reservations.show', $reservation) }}" class="btn btn-sm btn-primary">
                                            <i class="fas fa-eye"></i>
                                        </a>
                                    </td>
                                </tr>
                            @empty
                                <tr>
                                    <td colspan="6" class="text-center text-muted py-4">
                                        No reservations yet
                                    </td>
                                </tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>
            </div>
        </div>

        <!-- Top Room Types -->
        <div class="col-lg-4">
            <div class="card border-0 shadow-sm">
                <div class="card-header" style="background-color: #C1121F; color: white;">
                    <h5 class="mb-0">
                        <i class="fas fa-star"></i> Top Room Types
                    </h5>
                </div>
                <div class="card-body">
                    @forelse($topRoomTypes as $room)
                        <div class="d-flex justify-content-between align-items-center mb-3 pb-2" style="border-bottom: 1px solid #f0f0f0;">
                            <div>
                                <strong>{{ $room->room_name }}</strong><br>
                                <small class="text-muted">{{ $room->room_type }}</small>
                            </div>
                            <span class="badge" style="background-color: #C1121F;">{{ $room->reservations_count }} bookings</span>
                        </div>
                    @empty
                        <p class="text-center text-muted py-4">No data yet</p>
                    @endforelse
                </div>
            </div>
        </div>
    </div>
</div>
@endsection