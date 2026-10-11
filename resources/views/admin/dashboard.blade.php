@extends('layouts.app')

@section('title', 'Admin Dashboard')

@section('content')
<div class="container-fluid py-4">
    <x-page-header icon="fas fa-chart-line" title="Welcome, {{ auth()->user()->full_name }}!" subtitle="Here's what's happening across Velocity Suites today." :showClock="true" />

    {{-- Key indicators only; detailed user, promotion, amenity and room
         breakdowns live in Reports. Each linked card's number matches the
         page it opens. --}}
    <div class="row g-3 mb-4 dashboard-kpis">
        <div class="col-6 col-md-4 col-xl-2">
            <x-stat-card icon="fas fa-peso-sign" label="Today's Revenue" value="₱{{ number_format($todayRevenue, 2) }}" :change="$todayRevenueChange" color="success" :href="route('admin.reports.index', ['period' => 'daily'])" />
        </div>
        <div class="col-6 col-md-4 col-xl-2">
            <x-stat-card icon="fas fa-money-bill-wave" label="This Month" value="₱{{ number_format($monthlyRevenue, 2) }}" :change="$monthlyRevenueChange" color="success" :href="route('admin.reports.index', ['period' => 'monthly'])" />
        </div>
        <div class="col-6 col-md-4 col-xl-2">
            <x-stat-card icon="fas fa-bed" label="Rooms Occupied" value="{{ $occupiedRooms }} / {{ $totalRooms }}" color="primary" :href="route('admin.room-types.index')" />
        </div>
        <div class="col-6 col-md-4 col-xl-2">
            {{-- status=PENDING covers both awaiting-cash and awaiting-GCash. --}}
            <x-stat-card icon="fas fa-hourglass-half" label="Pending Reservations" :value="$pendingReservations" color="warning" :href="route('admin.reservations.index', ['status' => 'PENDING'])" />
        </div>
        <div class="col-6 col-md-4 col-xl-2">
            <x-stat-card icon="fas fa-receipt" label="Payments to Verify" :value="$pendingPaymentVerifications" color="danger" :href="route('admin.reservations.index', ['payment_status' => 'pending'])" />
        </div>
        <div class="col-6 col-md-4 col-xl-2">
            <x-stat-card icon="fas fa-user-check" label="Active Users" :value="$activeUsers" color="info" :href="route('admin.users.index', ['status' => 'active'])" />
        </div>
    </div>

    @php
        $roomsByStatusLegend = [
            ['label' => 'Available', 'value' => $availableRooms, 'color' => '#28a745'],
            ['label' => 'Occupied', 'value' => $occupiedRooms, 'color' => '#D6414B'],
            ['label' => 'Maintenance', 'value' => $maintenanceRooms, 'color' => '#ffc107'],
        ];
    @endphp
    <div class="row g-3 mb-4">
        <div class="col-lg-8">
            <x-card title="Revenue - Last 7 Days (₱)" icon="fas fa-chart-line" bodyClass="card-body" class="h-100">
                <div style="height: 240px;"><canvas id="revenueTrendChart"></canvas></div>
            </x-card>
        </div>
        <div class="col-lg-4">
            <x-chart-card
                icon="fas fa-door-open"
                title="Rooms by Status"
                canvasId="roomsByStatusChart"
                :href="route('admin.room-types.index')"
                :legend="$roomsByStatusLegend" />
        </div>
    </div>

    <div class="row g-3 mb-4">
        <div class="col-xl-8">
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
                                            <x-status-badge :status="$reservation->booking->display_status" domain="booking" />
                                        @else
                                            <x-status-badge :status="$reservation->status" domain="reservation" />
                                        @endif
                                    </td>
                                    <td>
                                        <a href="{{ route('admin.reservations.show', $reservation) }}" class="btn btn-outline-primary btn-sm btn-icon" title="View" aria-label="View">
                                            <i class="fas fa-eye"></i></a>
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
        <div class="col-xl-4">
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
            <x-collapsible-card id="adminSystemNotifications" title="System Notifications & Alerts" icon="fas fa-bell" bodyClass="card-body" class="mt-3">
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

        lineChart('revenueTrendChart', @json($revenueTrend['labels']), @json($revenueTrend['values']), '#D6414B');
        doughnutChart('roomsByStatusChart', ['Available', 'Occupied', 'Maintenance'], [{{ $availableRooms }}, {{ $occupiedRooms }}, {{ $maintenanceRooms }}], ['#28a745', '#D6414B', '#ffc107']);
    }

    document.addEventListener('DOMContentLoaded', initDashboardCharts);
    window.addEventListener('auto-refresh:swapped', initDashboardCharts);
})();
</script>
@endpush

@include('components.auto-refresh')
@endsection
