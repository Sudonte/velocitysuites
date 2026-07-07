@extends('layouts.app')

@section('title', 'Admin Dashboard')

@section('content')
<div class="container-fluid py-4">
    <div class="row mb-4">
        <div class="col-12">
            <h1 class="mb-0">
                <i class="fas fa-chart-line"></i> Admin Dashboard
            </h1>
            <p class="text-muted">Welcome, {{ auth()->user()->name }}!</p>
        </div>
    </div>

    <!-- Statistics Cards -->
    <div class="row mb-4">
        <!-- Total Users Card -->
        <div class="col-md-3 mb-3">
            <div class="card border-0 shadow-sm">
                <div class="card-body">
                    <div class="d-flex justify-content-between align-items-center">
                        <div>
                            <p class="text-muted mb-2">Total Users</p>
                            <h3 class="mb-0" style="color: #C1121F;">{{ $totalUsers }}</h3>
                        </div>
                        <div style="font-size: 2.5rem; color: #C1121F; opacity: 0.3;">
                            <i class="fas fa-users"></i>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Active Users Card -->
        <div class="col-md-3 mb-3">
            <div class="card border-0 shadow-sm">
                <div class="card-body">
                    <div class="d-flex justify-content-between align-items-center">
                        <div>
                            <p class="text-muted mb-2">Active Users</p>
                            <h3 class="mb-0" style="color: #28a745;">{{ $activeUsers }}</h3>
                        </div>
                        <div style="font-size: 2.5rem; color: #28a745; opacity: 0.3;">
                            <i class="fas fa-user-check"></i>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Suspended Users Card -->
        <div class="col-md-3 mb-3">
            <div class="card border-0 shadow-sm">
                <div class="card-body">
                    <div class="d-flex justify-content-between align-items-center">
                        <div>
                            <p class="text-muted mb-2">Suspended Users</p>
                            <h3 class="mb-0" style="color: #dc3545;">{{ $suspendedUsers }}</h3>
                        </div>
                        <div style="font-size: 2.5rem; color: #dc3545; opacity: 0.3;">
                            <i class="fas fa-user-slash"></i>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Active Promotions Card -->
        <div class="col-md-3 mb-3">
            <div class="card border-0 shadow-sm">
                <div class="card-body">
                    <div class="d-flex justify-content-between align-items-center">
                        <div>
                            <p class="text-muted mb-2">Active Promotions</p>
                            <h3 class="mb-0" style="color: #ffc107;">{{ $activePromotions }}</h3>
                        </div>
                        <div style="font-size: 2.5rem; color: #ffc107; opacity: 0.3;">
                            <i class="fas fa-tag"></i>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Room Statistics -->
    <div class="row mb-4">
        <!-- Available Rooms Card -->
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

        <!-- Occupied Rooms Card -->
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

        <!-- Reserved Rooms Card -->
        <div class="col-md-3 mb-3">
            <div class="card border-0 shadow-sm">
                <div class="card-body">
                    <div class="d-flex justify-content-between align-items-center">
                        <div>
                            <p class="text-muted mb-2">Reserved Rooms</p>
                            <h3 class="mb-0" style="color: #17a2b8;">{{ $reservedRooms }}</h3>
                        </div>
                        <div style="font-size: 2.5rem; color: #17a2b8; opacity: 0.3;">
                            <i class="fas fa-calendar-alt"></i>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Total Rooms Card -->
        <div class="col-md-3 mb-3">
            <div class="card border-0 shadow-sm">
                <div class="card-body">
                    <div class="d-flex justify-content-between align-items-center">
                        <div>
                            <p class="text-muted mb-2">Total Rooms</p>
                            <h3 class="mb-0" style="color: #6f42c1;">{{ $availableRooms + $occupiedRooms + $reservedRooms }}</h3>
                        </div>
                        <div style="font-size: 2.5rem; color: #6f42c1; opacity: 0.3;">
                            <i class="fas fa-building"></i>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Content Row -->
    <div class="row">
        <!-- Recent Reservations -->
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
                                    <td>{{ $reservation->guest->user->name }}</td>
                                    <td>{{ $reservation->room->room_number }}</td>
                                    <td>{{ $reservation->check_in->format('M d, Y') }}</td>
                                    <td>{{ $reservation->check_out->format('M d, Y') }}</td>
                                    <td>
                                        <span class="badge bg-{{ $reservation->status === 'confirmed' ? 'success' : ($reservation->status === 'cancelled' ? 'danger' : 'warning') }}">
                                            {{ ucfirst($reservation->status) }}
                                        </span>
                                    </td>
                                    <td>
                                        <a href="#" class="btn btn-sm btn-primary">
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

        <!-- Recent Activities -->
        <div class="col-lg-4">
            <div class="card border-0 shadow-sm">
                <div class="card-header" style="background-color: #C1121F; color: white;">
                    <h5 class="mb-0">
                        <i class="fas fa-history"></i> Recent Activities
                    </h5>
                </div>
                <div class="card-body">
                    @forelse($recentActivities as $activity)
                        <div class="d-flex mb-3">
                            <div class="flex-shrink-0">
                                <i class="fas fa-circle" style="color: #C1121F; font-size: 0.5rem;"></i>
                            </div>
                            <div class="flex-grow-1 ms-3">
                                <p class="mb-1 text-sm">
                                    <strong>{{ $activity->user->name }}</strong>
                                </p>
                                <p class="mb-1 text-sm text-muted">
                                    {{ $activity->action }}
                                </p>
                                <small class="text-muted">
                                    {{ $activity->created_at->diffForHumans() }}
                                </small>
                            </div>
                        </div>
                    @empty
                        <p class="text-center text-muted py-4">No activities yet</p>
                    @endforelse
                </div>
            </div>
        </div>
    </div>
</div>
@endsection
