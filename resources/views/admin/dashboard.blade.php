@extends('layouts.app')

@section('title', 'Admin Dashboard')

@section('content')
<div class="container-fluid py-4">
    <x-page-header icon="fas fa-chart-line" title="Admin Dashboard" subtitle="Welcome, {{ auth()->user()->full_name }}!" />

    <!-- Statistics Cards -->
    <div class="row mb-4">
        <div class="col-md-3 mb-3">
            <x-stat-card icon="fas fa-users" label="Total Users" :value="$totalUsers" :change="$totalUsersChange" color="primary" href="{{ route('admin.users.index') }}" />
        </div>
        <div class="col-md-3 mb-3">
            <x-stat-card icon="fas fa-user-check" label="Active Users" :value="$activeUsers" color="success" href="{{ route('admin.users.index', ['status' => 'active']) }}" />
        </div>
        <div class="col-md-3 mb-3">
            <x-stat-card icon="fas fa-user-slash" label="Suspended Users" :value="$suspendedUsers" color="danger" href="{{ route('admin.users.index', ['status' => 'suspended']) }}" />
        </div>
        <div class="col-md-3 mb-3">
            <x-stat-card icon="fas fa-tag" label="Active Promotions" :value="$activePromotions" color="warning" href="{{ route('admin.promotions.index') }}" />
        </div>
    </div>

    <!-- Room Statistics -->
    <div class="row mb-4">
        <div class="col-md-3 mb-3">
            <x-stat-card icon="fas fa-door-open" label="Available Rooms" :value="$availableRooms" color="success" href="{{ route('admin.room-types.index') }}" />
        </div>
        <div class="col-md-3 mb-3">
            <x-stat-card icon="fas fa-bed" label="Occupied Rooms" :value="$occupiedRooms" color="primary" href="{{ route('admin.room-types.index') }}" />
        </div>
        <div class="col-md-3 mb-3">
            <x-stat-card icon="fas fa-tools" label="Maintenance Rooms" :value="$maintenanceRooms" color="warning" href="{{ route('admin.room-types.index') }}" />
        </div>
        <div class="col-md-3 mb-3">
            <x-stat-card icon="fas fa-building" label="Total Rooms" :value="$totalRooms" :change="$totalRoomsChange" color="secondary" href="{{ route('admin.room-types.index') }}" />
        </div>
    </div>

    <!-- Revenue -->
    <div class="row mb-4">
        <div class="col-md-4 mb-3">
            <x-stat-card icon="fas fa-peso-sign" label="Today's Revenue" value="₱{{ number_format($todayRevenue, 2) }}" :change="$todayRevenueChange" color="success" />
        </div>
        <div class="col-md-4 mb-3">
            <x-stat-card icon="fas fa-money-bill-wave" label="Monthly Revenue" value="₱{{ number_format($monthlyRevenue, 2) }}" :change="$monthlyRevenueChange" color="success" />
        </div>
        <div class="col-md-4 mb-3">
            <x-stat-card icon="fas fa-sack-dollar" label="Yearly Revenue" value="₱{{ number_format($yearlyRevenue, 2) }}" :change="$yearlyRevenueChange" color="success" />
        </div>
    </div>

    <!-- Reservation / Booking Status -->
    <div class="row mb-4">
        <div class="col-md-3 mb-3">
            <x-stat-card icon="fas fa-hourglass-half" label="Pending Reservations" :value="$pendingReservations" color="warning" href="{{ route('manager.reservations.index', ['status' => 'pending_review']) }}" />
        </div>
        <div class="col-md-3 mb-3">
            <x-stat-card icon="fas fa-calendar-check" label="Active Reservations" :value="$activeReservations" color="primary" />
        </div>
        <div class="col-md-3 mb-3">
            <x-stat-card icon="fas fa-credit-card" label="Total Bookings" :value="$totalBookings" :change="$totalBookingsChange" color="info" />
        </div>
        <div class="col-md-3 mb-3">
            <x-stat-card icon="fas fa-clock" label="Pending Payment Verifications" :value="$pendingPaymentVerifications" color="danger" />
        </div>
    </div>

    <!-- Trend Charts -->
    <div class="row mb-4">
        <div class="col-lg-4 mb-3">
            <x-card title="Users Overview" icon="fas fa-users" bodyClass="card-body">
                <canvas id="usersTrendChart" height="160"></canvas>
            </x-card>
        </div>
        <div class="col-lg-4 mb-3">
            <x-card title="Reservations Overview" icon="fas fa-calendar-alt" bodyClass="card-body">
                <canvas id="reservationsTrendChart" height="160"></canvas>
            </x-card>
        </div>
        <div class="col-lg-4 mb-3">
            <x-card title="Revenue Overview (₱)" icon="fas fa-chart-line" bodyClass="card-body">
                <canvas id="revenueTrendChart" height="160"></canvas>
            </x-card>
        </div>
    </div>

    <!-- Content Row -->
    <div class="row">
        <!-- Recent Reservations -->
        <div class="col-lg-5">
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
                                <td>{{ $reservation->stay_guest_full_name ?? $reservation->guest->user->full_name }}</td>
                                <td>{{ $reservation->roomType->name ?? 'N/A' }}</td>
                                <td>{{ $reservation->check_in->format('M d, Y') }}</td>
                                <td>{{ $reservation->check_out->format('M d, Y') }}</td>
                                <td>
                                    @if($reservation->booking)
                                        <x-status-badge :status="$reservation->booking->booking_status" domain="booking" />
                                    @else
                                        <x-status-badge :status="$reservation->status" domain="reservation" />
                                    @endif
                                </td>
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

        <!-- Quick Actions -->
        <div class="col-lg-3">
            <x-card title="Quick Actions" icon="fas fa-bolt" bodyClass="card-body">
                <div class="d-grid gap-2">
                    <a href="{{ route('admin.users.create') }}" class="btn btn-primary text-start">
                        <i class="fas fa-user-plus"></i> Add New User
                    </a>
                    <a href="{{ route('admin.room-types.index') }}" class="btn btn-success text-start">
                        <i class="fas fa-door-open"></i> Manage Rooms
                    </a>
                    <a href="{{ route('admin.promotions.create') }}" class="btn btn-warning text-start">
                        <i class="fas fa-tag"></i> Add Promotion
                    </a>
                    <a href="{{ route('admin.reports.index') }}" class="btn btn-info text-start text-white">
                        <i class="fas fa-chart-bar"></i> View Reports
                    </a>
                </div>
            </x-card>
        </div>
    </div>
</div>

@push('scripts')
<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.0/dist/chart.umd.min.js"></script>
<script>
(function () {
    const dashboardCharts = [];

    function lineChart(canvasId, labels, values, color) {
        const canvas = document.getElementById(canvasId);
        if (!canvas) return;
        dashboardCharts.push(new Chart(canvas.getContext('2d'), {
            type: 'line',
            data: {
                labels: labels,
                datasets: [{
                    data: values,
                    borderColor: color,
                    backgroundColor: color + '22',
                    fill: true,
                    tension: 0.35,
                    pointRadius: 3,
                    pointBackgroundColor: color,
                }],
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                plugins: { legend: { display: false } },
                scales: {
                    y: { beginAtZero: true, ticks: { precision: 0 } },
                },
            },
        }));
    }

    function initDashboardCharts() {
        // The auto-refresh component replaces <main>'s innerHTML wholesale
        // (including these <canvas> elements) without re-running scripts,
        // so any chart instances bound to the old nodes are now orphaned -
        // destroy them before drawing fresh ones on the new nodes.
        dashboardCharts.splice(0).forEach(chart => chart.destroy());

        lineChart('usersTrendChart', @json($usersTrend['labels']), @json($usersTrend['values']), '#c1121f');
        lineChart('reservationsTrendChart', @json($reservationsTrend['labels']), @json($reservationsTrend['values']), '#28a745');
        lineChart('revenueTrendChart', @json($revenueTrend['labels']), @json($revenueTrend['values']), '#6f42c1');
    }

    document.addEventListener('DOMContentLoaded', initDashboardCharts);
    window.addEventListener('auto-refresh:swapped', initDashboardCharts);
})();
</script>
@endpush

@include('components.auto-refresh')
@endsection
