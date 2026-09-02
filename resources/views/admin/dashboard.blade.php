@extends('layouts.app')

@section('title', 'Admin Dashboard')

@section('content')
<div class="container-fluid py-4">
    <x-page-header icon="fas fa-chart-line" title="Welcome, {{ auth()->user()->full_name }}!" subtitle="Here's what's happening across Velocity Suites today." :showClock="true" />

    <!-- Statistics Cards -->
    <div class="detail-section-title"><i class="fas fa-users"></i> User Overview</div>
    <div class="row mb-3">
        <div class="col-md-6 col-lg-3 mb-3">
            <x-stat-card icon="fas fa-users" label="Total Users" :value="$totalUsers" :change="$totalUsersChange" color="primary" href="{{ route('admin.users.index') }}" />
        </div>
        <div class="col-md-6 col-lg-3 mb-3">
            <x-stat-card icon="fas fa-user-check" label="Active Users" :value="$activeUsers" color="success" href="{{ route('admin.users.index', ['status' => 'active']) }}" />
        </div>
        <div class="col-md-6 col-lg-3 mb-3">
            <x-stat-card icon="fas fa-user-slash" label="Suspended Users" :value="$suspendedUsers" color="danger" href="{{ route('admin.users.index', ['status' => 'suspended']) }}" />
        </div>
        <div class="col-md-6 col-lg-3 mb-3">
            <x-stat-card icon="fas fa-user-friends" label="Total Guests" :value="$totalGuests" color="secondary" href="{{ route('admin.users.index', ['role' => 'guest']) }}" />
        </div>
    </div>

    <!-- Promotions & Discounts -->
    <div class="detail-section-title"><i class="fas fa-tags"></i> Promotions &amp; Discounts</div>
    <div class="row mb-3">
        <div class="col-md-6 col-lg-3 mb-3">
            <x-stat-card icon="fas fa-tag" label="Active Promotions" :value="$activePromotions" color="success" href="{{ route('admin.promotions.index', ['status' => 'active']) }}" />
        </div>
        <div class="col-md-6 col-lg-3 mb-3">
            <x-stat-card icon="fas fa-tag" label="Inactive Promotions" :value="$inactivePromotions" color="secondary" href="{{ route('admin.promotions.index', ['status' => 'inactive']) }}" />
        </div>
        <div class="col-md-6 col-lg-3 mb-3">
            <x-stat-card icon="fas fa-percent" label="Active Discounts" :value="$activeDiscounts" color="success" href="{{ route('admin.discounts.index', ['status' => 'active']) }}" />
        </div>
        <div class="col-md-6 col-lg-3 mb-3">
            <x-stat-card icon="fas fa-percent" label="Inactive Discounts" :value="$inactiveDiscounts" color="secondary" href="{{ route('admin.discounts.index', ['status' => 'inactive']) }}" />
        </div>
    </div>

    <!-- Room Statistics -->
    <div class="detail-section-title"><i class="fas fa-door-open"></i> Room Overview</div>
    <div class="row mb-3">
        <div class="col-md-6 col-lg-3 mb-3">
            <x-stat-card icon="fas fa-door-open" label="Available Rooms" :value="$availableRooms" color="success" href="{{ route('admin.room-types.index') }}" />
        </div>
        <div class="col-md-6 col-lg-3 mb-3">
            <x-stat-card icon="fas fa-bed" label="Occupied Rooms" :value="$occupiedRooms" color="primary" href="{{ route('admin.room-types.index') }}" />
        </div>
        <div class="col-md-6 col-lg-3 mb-3">
            <x-stat-card icon="fas fa-tools" label="Maintenance Rooms" :value="$maintenanceRooms" color="warning" href="{{ route('admin.room-types.index') }}" />
        </div>
        <div class="col-md-6 col-lg-3 mb-3">
            <x-stat-card icon="fas fa-building" label="Total Rooms" :value="$totalRooms" :change="$totalRoomsChange" color="secondary" href="{{ route('admin.room-types.index') }}" />
        </div>
    </div>

    <!-- Amenities -->
    <div class="detail-section-title"><i class="fas fa-concierge-bell"></i> Amenities</div>
    <div class="row mb-3">
        <div class="col-md-6 col-lg-6 mb-3">
            <x-stat-card icon="fas fa-concierge-bell" label="Active Amenities" :value="$activeAmenities" color="success" href="{{ route('admin.amenities.index', ['status' => 'active']) }}" />
        </div>
        <div class="col-md-6 col-lg-6 mb-3">
            <x-stat-card icon="fas fa-concierge-bell" label="Inactive Amenities" :value="$inactiveAmenities" color="secondary" href="{{ route('admin.amenities.index', ['status' => 'inactive']) }}" />
        </div>
    </div>

    <!-- Revenue -->
    <div class="detail-section-title"><i class="fas fa-peso-sign"></i> Revenue</div>
    <div class="row mb-3">
        <div class="col-md-4 mb-3">
            <x-stat-card icon="fas fa-peso-sign" label="Today's Revenue" value="₱{{ number_format($todayRevenue, 2) }}" :change="$todayRevenueChange" color="success" href="{{ route('admin.reports.index', ['start_date' => today()->toDateString(), 'end_date' => today()->toDateString()]) }}" />
        </div>
        <div class="col-md-4 mb-3">
            <x-stat-card icon="fas fa-money-bill-wave" label="Monthly Revenue" value="₱{{ number_format($monthlyRevenue, 2) }}" :change="$monthlyRevenueChange" color="success" href="{{ route('admin.reports.index', ['start_date' => now()->startOfMonth()->toDateString(), 'end_date' => now()->toDateString()]) }}" />
        </div>
        <div class="col-md-4 mb-3">
            <x-stat-card icon="fas fa-sack-dollar" label="Yearly Revenue" value="₱{{ number_format($yearlyRevenue, 2) }}" :change="$yearlyRevenueChange" color="success" href="{{ route('admin.reports.index', ['start_date' => now()->startOfYear()->toDateString(), 'end_date' => now()->toDateString()]) }}" />
        </div>
    </div>

    <!-- Reservation / Booking Status -->
    <div class="detail-section-title"><i class="fas fa-calendar-check"></i> Reservations &amp; Payments</div>
    <div class="row mb-3">
        <div class="col-md-6 col-lg-3 mb-3">
            <x-stat-card icon="fas fa-hourglass-half" label="Pending Reservations" :value="$pendingReservations" color="warning" href="{{ route('admin.reservations.index', ['status' => 'AWAITING_CASH_CONFIRMATION']) }}" />
        </div>
        <div class="col-md-6 col-lg-3 mb-3">
            <x-stat-card icon="fas fa-calendar-check" label="Active Reservations" :value="$activeReservations" color="primary" href="{{ route('admin.reservations.index', ['status' => 'ACTIVE_BOOKING']) }}" />
        </div>
        <div class="col-md-6 col-lg-3 mb-3">
            <x-stat-card icon="fas fa-credit-card" label="Total Bookings" :value="$totalBookings" :change="$totalBookingsChange" color="info" href="{{ route('admin.reservations.index', ['type' => 'booking']) }}" />
        </div>
        <div class="col-md-6 col-lg-3 mb-3">
            <x-stat-card icon="fas fa-clock" label="Pending Payment Verifications" :value="$pendingPaymentVerifications" color="danger" href="{{ route('admin.reservations.index', ['payment_status' => 'pending']) }}" />
        </div>
    </div>

    <!-- Category Breakdown Charts -->
    @php
        $usersByRoleLegend = [
            ['label' => 'Guests', 'value' => $totalGuests, 'color' => '#D6414B'],
            ['label' => 'Staff', 'value' => $totalAdmins + $totalManagers + $totalReceptionists, 'color' => '#D4AF37'],
        ];
        $usersByStatusLegend = [
            ['label' => 'Active', 'value' => $activeUsers, 'color' => '#28a745'],
            ['label' => 'Suspended', 'value' => $suspendedUsers, 'color' => '#dc3545'],
        ];
        $roomsByStatusLegend = [
            ['label' => 'Available', 'value' => $availableRooms, 'color' => '#28a745'],
            ['label' => 'Occupied', 'value' => $occupiedRooms, 'color' => '#D6414B'],
            ['label' => 'Maintenance', 'value' => $maintenanceRooms, 'color' => '#ffc107'],
        ];
    @endphp
    <div class="row mb-4">
        <div class="col-lg-4 mb-3">
            <x-chart-card
                icon="fas fa-users"
                title="Users by Role"
                canvasId="usersByRoleChart"
                href="{{ route('admin.users.index') }}"
                :legend="$usersByRoleLegend" />
        </div>
        <div class="col-lg-4 mb-3">
            <x-chart-card
                icon="fas fa-user-check"
                title="Users by Status"
                canvasId="usersByStatusChart"
                href="{{ route('admin.users.index') }}"
                :legend="$usersByStatusLegend" />
        </div>
        <div class="col-lg-4 mb-3">
            <x-chart-card
                icon="fas fa-door-open"
                title="Rooms by Status"
                canvasId="roomsByStatusChart"
                href="{{ route('admin.room-types.index') }}"
                :legend="$roomsByStatusLegend" />
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

    <!-- Recent Booking & Reservations -->
    <div class="row mb-4">
        <div class="col-12">
            <x-collapsible-card id="adminRecentBookingReservations" title="Recent Booking & Reservations" icon="fas fa-calendar-alt" bodyClass="table-responsive">
                <div id="adminRecentBookingReservations-list" data-preview-list data-preview-persist-key="dash-preview-adminRecentBookingReservations">
                    <table class="table table-hover mb-0">
                        <thead>
                            <tr>
                                <th>Guest</th>
                                <th>Type</th>
                                <th>Room</th>
                                <th>Check-In</th>
                                <th>Check-Out</th>
                                <th>Status</th>
                                <th>Action</th>
                            </tr>
                        </thead>
                        <tbody>
                            @forelse($recentReservations as $reservation)
                                <tr class="{{ $loop->index >= 5 ? 'preview-extra d-none' : '' }}">
                                    <td>{{ $reservation->guest_display_name }}</td>
                                    <td>
                                        @if($reservation->booking)
                                            <span class="badge bg-primary">Booking</span>
                                        @else
                                            <span class="badge bg-secondary">Reservation</span>
                                        @endif
                                    </td>
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
                                        <a href="{{ route('admin.reservations.show', $reservation) }}" class="btn btn-sm btn-primary">
                                            <i class="fas fa-eye"></i>
                                        </a>
                                    </td>
                                </tr>
                            @empty
                                <tr>
                                    <td colspan="7">
                                        <x-empty-state icon="fas fa-calendar-alt" message="No bookings or reservations yet." />
                                    </td>
                                </tr>
                            @endforelse
                        </tbody>
                    </table>
                    @if($recentReservations->count() > 5)
                        <button type="button" class="btn btn-sm btn-outline-secondary w-100 mt-2 preview-toggle-btn" aria-expanded="false" aria-controls="adminRecentBookingReservations-list">
                            <i class="fas fa-chevron-down"></i> Expand
                        </button>
                    @endif
                </div>
            </x-collapsible-card>
        </div>
    </div>

    <!-- Recent Activities -->
    <div class="row mb-4">
        <div class="col-12">
            <x-collapsible-card id="adminRecentActivities" title="Recent Activities" icon="fas fa-history" bodyClass="card-body">
                <div id="adminRecentActivities-list" data-preview-list data-preview-persist-key="dash-preview-adminRecentActivities">
                    @forelse($recentActivities as $activity)
                        <div class="d-flex mb-3 {{ $loop->index >= 5 ? 'preview-extra d-none' : '' }}">
                            <div class="flex-shrink-0">
                                <i class="fas fa-circle text-brand" style="font-size: 0.5rem;"></i>
                            </div>
                            <div class="flex-grow-1 ms-3">
                                <p class="mb-1 text-sm">
                                    <strong>{{ $activity->user->full_name }}</strong>
                                    <span class="text-muted">({{ $activity->user->role_label ?? ucfirst($activity->user->role) }})</span>
                                </p>
                                <p class="mb-1 text-sm text-muted">
                                    {{ $activity->action }}
                                    @if($activity->description)
                                        &mdash; {{ $activity->description }}
                                    @endif
                                </p>
                                <small class="text-muted">
                                    {{ $activity->created_at->diffForHumans() }}
                                    @if($activity->subjectUrl())
                                        &middot; <a href="{{ $activity->subjectUrl() }}">View</a>
                                    @endif
                                </small>
                            </div>
                        </div>
                    @empty
                        <x-empty-state icon="fas fa-history" message="No activities yet." />
                    @endforelse
                    @if($recentActivities->count() > 5)
                        <button type="button" class="btn btn-sm btn-outline-secondary w-100 mt-2 preview-toggle-btn" aria-expanded="false" aria-controls="adminRecentActivities-list">
                            <i class="fas fa-chevron-down"></i> Expand
                        </button>
                    @endif
                </div>
            </x-collapsible-card>
        </div>
    </div>

    <!-- System Notifications & Alerts -->
    <div class="row mb-4">
        <div class="col-12">
            <x-collapsible-card id="adminSystemNotifications" title="System Notifications & Alerts" icon="fas fa-bell" bodyClass="card-body">
                <div id="adminSystemNotifications-list" data-preview-list data-preview-persist-key="dash-preview-adminSystemNotifications">
                    @forelse($systemNotifications as $notification)
                        <div class="d-flex mb-3 {{ $loop->index >= 5 ? 'preview-extra d-none' : '' }}">
                            <div class="flex-shrink-0">
                                <i class="fas fa-circle {{ $notification->is_read ? 'text-muted' : 'text-brand' }}" style="font-size: 0.5rem;"></i>
                            </div>
                            <div class="flex-grow-1 ms-3">
                                <p class="mb-1 text-sm"><strong>{{ $notification->title }}</strong></p>
                                <p class="mb-1 text-sm text-muted">{{ $notification->message }}</p>
                                <small class="text-muted">{{ $notification->created_at->diffForHumans() }}</small>
                            </div>
                        </div>
                    @empty
                        <x-empty-state icon="fas fa-bell" message="No notifications yet." />
                    @endforelse
                    @if($systemNotifications->count() > 5)
                        <button type="button" class="btn btn-sm btn-outline-secondary w-100 mt-2 preview-toggle-btn" aria-expanded="false" aria-controls="adminSystemNotifications-list">
                            <i class="fas fa-chevron-down"></i> Expand
                        </button>
                    @endif
                </div>
                <a href="{{ route('notifications.index') }}" class="btn btn-sm btn-link w-100 mt-2 text-decoration-none">View All Notifications</a>
            </x-collapsible-card>
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

    function doughnutChart(canvasId, labels, values, colors) {
        const canvas = document.getElementById(canvasId);
        if (!canvas) return;
        dashboardCharts.push(new Chart(canvas.getContext('2d'), {
            type: 'doughnut',
            data: {
                labels: labels,
                datasets: [{ data: values, backgroundColor: colors, borderWidth: 0 }],
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                plugins: { legend: { display: false } },
                cutout: '65%',
            },
        }));
    }

    function initDashboardCharts() {
        // The auto-refresh component replaces <main>'s innerHTML wholesale
        // (including these <canvas> elements) without re-running scripts,
        // so any chart instances bound to the old nodes are now orphaned -
        // destroy them before drawing fresh ones on the new nodes.
        dashboardCharts.splice(0).forEach(chart => chart.destroy());

        lineChart('usersTrendChart', @json($usersTrend['labels']), @json($usersTrend['values']), '#D6414B');
        lineChart('reservationsTrendChart', @json($reservationsTrend['labels']), @json($reservationsTrend['values']), '#28a745');
        lineChart('revenueTrendChart', @json($revenueTrend['labels']), @json($revenueTrend['values']), '#6f42c1');

        doughnutChart('usersByRoleChart', ['Guests', 'Staff'], [{{ $totalGuests }}, {{ $totalAdmins + $totalManagers + $totalReceptionists }}], ['#D6414B', '#D4AF37']);
        doughnutChart('usersByStatusChart', ['Active', 'Suspended'], [{{ $activeUsers }}, {{ $suspendedUsers }}], ['#28a745', '#dc3545']);
        doughnutChart('roomsByStatusChart', ['Available', 'Occupied', 'Maintenance'], [{{ $availableRooms }}, {{ $occupiedRooms }}, {{ $maintenanceRooms }}], ['#28a745', '#D6414B', '#ffc107']);
    }

    document.addEventListener('DOMContentLoaded', initDashboardCharts);
    window.addEventListener('auto-refresh:swapped', initDashboardCharts);
})();
</script>
@endpush

@include('components.auto-refresh')
@endsection
