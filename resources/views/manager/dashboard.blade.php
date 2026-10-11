@extends('layouts.app')

@section('title', 'Manager Dashboard')

@section('content')
<div class="container-fluid py-4">
    <x-page-header icon="fas fa-chart-pie" title="Welcome, {{ auth()->user()->full_name }}!" subtitle="Here's the hotel's performance for the selected period." :showClock="true" />

    <!-- Date-Range Filter -->
    <x-card title="Reporting Period" icon="fas fa-calendar-alt" bodyClass="card-body" class="mb-4">
        <form method="GET" action="{{ route('manager.dashboard') }}" class="row gy-2 gx-2 align-items-end" id="periodFilterForm">
            <div class="col-auto">
                <div class="d-flex flex-wrap gap-1" role="group" aria-label="Reporting period">
                    <a href="{{ route('manager.dashboard', ['period' => 'daily']) }}" class="btn btn-sm {{ $period === 'daily' ? 'btn-primary' : 'btn-outline-primary' }}">Today</a>
                    <a href="{{ route('manager.dashboard', ['period' => 'weekly']) }}" class="btn btn-sm {{ $period === 'weekly' ? 'btn-primary' : 'btn-outline-primary' }}">This Week</a>
                    <a href="{{ route('manager.dashboard', ['period' => 'monthly']) }}" class="btn btn-sm {{ $period === 'monthly' ? 'btn-primary' : 'btn-outline-primary' }}">This Month</a>
                    <button type="submit" name="period" value="custom" class="btn btn-sm {{ $period === 'custom' ? 'btn-primary' : 'btn-outline-primary' }}">Custom</button>
                </div>
            </div>
            <div class="col-auto">
                <label class="form-label mb-0 small text-muted">From</label>
                <input type="date" name="from" class="form-control form-control-sm" value="{{ $periodFrom->toDateString() }}">
            </div>
            <div class="col-auto">
                <label class="form-label mb-0 small text-muted">To</label>
                <input type="date" name="to" class="form-control form-control-sm" value="{{ $periodTo->toDateString() }}">
            </div>
            <div class="col-auto">
                <small class="text-muted">Showing {{ $periodFrom->format('M d, Y') }} &ndash; {{ $periodTo->format('M d, Y') }}</small>
            </div>
        </form>
    </x-card>

    {{-- Management view: occupancy now, then activity for the selected
         period. No revenue (Admin only); front-desk queues live on the
         Receptionist dashboard. --}}
    <div class="detail-section-title"><i class="fas fa-bolt"></i> Right Now</div>
    <div class="row g-3 mb-4 dashboard-kpis">
        <div class="col-6 col-xl-3">
            <x-stat-card icon="fas fa-bed" label="Occupancy ({{ $occupiedRooms }}/{{ $totalRooms }} rooms)" value="{{ $occupancyRate }}%" color="primary" />
        </div>
        <div class="col-6 col-xl-3">
            <x-stat-card icon="fas fa-sign-in-alt" label="Check-Ins Today" :value="$todayCheckIns" color="success" />
        </div>
        <div class="col-6 col-xl-3">
            <x-stat-card icon="fas fa-sign-out-alt" label="Check-Outs Today" :value="$todayCheckOuts" color="info" />
        </div>
        <div class="col-6 col-xl-3">
            <x-stat-card icon="fas fa-users" label="In-House Guests" :value="$inHouseGuests" color="warning" />
        </div>
    </div>

    <div class="detail-section-title"><i class="fas fa-chart-line"></i> Selected Period &middot; {{ $periodFrom->format('M d') }} &ndash; {{ $periodTo->format('M d, Y') }}</div>
    <div class="row g-3 mb-4 dashboard-kpis">
        <div class="col-md-4">
            {{-- Each card opens its monitoring tab with the same count. --}}
            <x-stat-card icon="fas fa-calendar-alt" label="Reservations" :value="$totalReservations" color="info" :href="route('manager.reservations.index', ['tab' => 'reservations', 'from' => $periodFrom->toDateString(), 'to' => $periodTo->toDateString()])" />
        </div>
        <div class="col-md-4">
            <x-stat-card icon="fas fa-credit-card" label="Bookings" :value="$totalBookings" color="primary" :href="route('manager.reservations.index', ['tab' => 'bookings', 'from' => $periodFrom->toDateString(), 'to' => $periodTo->toDateString()])" />
        </div>
        <div class="col-md-4">
            <x-stat-card icon="fas fa-moon" label="Avg. Length of Stay" value="{{ $averageLengthOfStay }} nights" color="secondary" />
        </div>
    </div>

    <div class="row g-3 mb-4">
        <div class="col-lg-8">
            <x-card title="Booking Trend" icon="fas fa-chart-line" bodyClass="card-body" class="h-100">
                <div style="height: 240px;"><canvas id="bookingTrendChart"></canvas></div>
            </x-card>
        </div>
        <div class="col-lg-4">
            <x-card title="Room Utilization by Type" icon="fas fa-percentage" bodyClass="card-body" class="h-100">
                <p class="small text-muted mb-3">Share of each type's room-nights that were booked in the selected period (booked nights &divide; rooms &times; days).</p>
                @forelse($roomUtilization as $row)
                    <div class="d-flex justify-content-between align-items-center mb-2">
                        <span class="small text-truncate" style="max-width: 40%;">{{ $row['room_type'] }}</span>
                        <div class="flex-grow-1 mx-2">
                            <div class="progress" style="height: 8px;" role="progressbar" aria-label="{{ $row['room_type'] }} utilization" aria-valuenow="{{ $row['utilization'] }}" aria-valuemin="0" aria-valuemax="100">
                                <div class="progress-bar bg-brand" style="width: {{ $row['utilization'] }}%"></div>
                            </div>
                        </div>
                        <span class="small text-muted">{{ $row['utilization'] }}%</span>
                    </div>
                @empty
                    <x-empty-state icon="fas fa-percentage" message="No room types yet." />
                @endforelse
            </x-card>
        </div>
    </div>

    <div class="row g-3">
        <div class="col-xl-4 order-xl-2">
            <x-collapsible-card id="managerTopRoomTypes" title="Top Room Types" icon="fas fa-star" bodyClass="card-body">
                <div id="managerTopRoomTypes-list" data-preview-list data-preview-persist-key="dash-preview-managerTopRoomTypes">
                    @forelse($topRoomTypesList as $roomType)
                        <div class="d-flex justify-content-between align-items-center mb-3 pb-2 {{ $loop->index >= 5 ? 'preview-extra d-none' : '' }}" style="border-bottom: 1px solid #f0f0f0;">
                            <div>
                                <strong>{{ $roomType->name }}</strong><br>
                                <small class="text-muted">{{ $roomType->capacity_label }} &middot; ₱{{ number_format($roomType->rate, 2) }}/night</small>
                            </div>
                            <span class="badge badge-brand">{{ $roomType->bookings_count }} bookings</span>
                        </div>
                    @empty
                        <x-empty-state icon="fas fa-star" message="No data yet." />
                    @endforelse
                    @if($topRoomTypesList->count() > 5)
                        <button type="button" class="btn btn-sm btn-outline-secondary w-100 mt-2 preview-toggle-btn" aria-expanded="false" aria-controls="managerTopRoomTypes-list">
                            <i class="fas fa-chevron-down"></i> Expand
                        </button>
                    @endif
                </div>
            </x-collapsible-card>
        </div>
        <div class="col-xl-8 order-xl-1">
            <x-collapsible-card id="managerRecentBookingReservations" title="Recent Booking & Reservations" icon="fas fa-calendar-alt" bodyClass="table-responsive">
                <div id="managerRecentBookingReservations-list" data-preview-list data-preview-persist-key="dash-preview-managerRecentBookingReservations">
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
                                    <td>{{ $reservation->booking->room->room_number ?? $reservation->roomType->name ?? 'N/A' }}</td>
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
                                        <a href="{{ route('manager.reservations.show', $reservation) }}" class="btn btn-outline-primary btn-sm btn-icon" title="View" aria-label="View">
                                            <i class="fas fa-eye"></i></a>
                                    </td>
                                </tr>
                            @empty
                                <tr>
                                    <td colspan="7">
                                        <x-empty-state icon="fas fa-calendar-alt" message="No bookings or reservations in this period." />
                                    </td>
                                </tr>
                            @endforelse
                        </tbody>
                    </table>
                    @if($recentReservations->count() > 5)
                        <button type="button" class="btn btn-sm btn-outline-secondary w-100 mt-2 preview-toggle-btn" aria-expanded="false" aria-controls="managerRecentBookingReservations-list">
                            <i class="fas fa-chevron-down"></i> Expand
                        </button>
                    @endif
                </div>
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
        // Same auto-refresh re-init pattern as the admin dashboard - the
        // auto-refresh component replaces <main>'s innerHTML wholesale, so
        // chart instances bound to the old <canvas> nodes must be destroyed
        // before drawing fresh ones on the new nodes.
        dashboardCharts.splice(0).forEach(chart => chart.destroy());

        lineChart('bookingTrendChart', @json($bookingTrend['labels']), @json($bookingTrend['values']), '#D6414B');

    }

    document.addEventListener('DOMContentLoaded', initDashboardCharts);
    window.addEventListener('auto-refresh:swapped', initDashboardCharts);
})();
</script>
@endpush

@include('components.auto-refresh')
@endsection
