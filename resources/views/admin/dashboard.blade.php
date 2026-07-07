@extends('layouts.app')

@section('title', 'Admin Dashboard')

@section('content')
<div class="container-fluid py-4">
    <x-page-header icon="fas fa-chart-line" title="Admin Dashboard" subtitle="Welcome, {{ auth()->user()->full_name }}!" />

    <!-- Statistics Cards -->
    <div class="row mb-4">
        <div class="col-md-3 mb-3">
            <x-stat-card icon="fas fa-users" label="Total Users" :value="$totalUsers" color="primary" />
        </div>
        <div class="col-md-3 mb-3">
            <x-stat-card icon="fas fa-user-check" label="Active Users" :value="$activeUsers" color="success" />
        </div>
        <div class="col-md-3 mb-3">
            <x-stat-card icon="fas fa-user-slash" label="Suspended Users" :value="$suspendedUsers" color="danger" />
        </div>
        <div class="col-md-3 mb-3">
            <x-stat-card icon="fas fa-tag" label="Active Promotions" :value="$activePromotions" color="warning" />
        </div>
    </div>

    <!-- Room Statistics -->
    <div class="row mb-4">
        <div class="col-md-3 mb-3">
            <x-stat-card icon="fas fa-door-open" label="Available Rooms" :value="$availableRooms" color="success" />
        </div>
        <div class="col-md-3 mb-3">
            <x-stat-card icon="fas fa-bed" label="Occupied Rooms" :value="$occupiedRooms" color="primary" />
        </div>
        <div class="col-md-3 mb-3">
            <x-stat-card icon="fas fa-calendar-alt" label="Reserved Rooms" :value="$reservedRooms" color="info" />
        </div>
        <div class="col-md-3 mb-3">
            <x-stat-card icon="fas fa-building" label="Total Rooms" :value="$availableRooms + $occupiedRooms + $reservedRooms" color="secondary" />
        </div>
    </div>

    <!-- Content Row -->
    <div class="row">
        <!-- Recent Reservations -->
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
                                <td>{{ $reservation->guest->user->full_name }}</td>
                                <td>{{ $reservation->room->room_number ?? 'Unassigned' }}</td>
                                <td>{{ $reservation->check_in->format('M d, Y') }}</td>
                                <td>{{ $reservation->check_out->format('M d, Y') }}</td>
                                <td><x-status-badge :status="$reservation->status" domain="reservation" /></td>
                                <td>
                                    <a href="#" class="btn btn-sm btn-primary">
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

        <!-- Recent Activities -->
        <div class="col-lg-4">
            <x-card title="Recent Activities" icon="fas fa-history" bodyClass="card-body">
                @forelse($recentActivities as $activity)
                    <div class="d-flex mb-3">
                        <div class="flex-shrink-0">
                            <i class="fas fa-circle text-brand" style="font-size: 0.5rem;"></i>
                        </div>
                        <div class="flex-grow-1 ms-3">
                            <p class="mb-1 text-sm">
                                <strong>{{ $activity->user->full_name }}</strong>
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
                    <x-empty-state icon="fas fa-history" message="No activities yet." />
                @endforelse
            </x-card>
        </div>
    </div>
</div>
@endsection
