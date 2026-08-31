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

    <!-- Right-now operational snapshot - NOT period-filtered -->
    <div class="detail-section-title"><i class="fas fa-bolt"></i> Right Now</div>
    <div class="row mb-4">
        <div class="col-md-6 col-lg-3 mb-3">
            <x-stat-card icon="fas fa-building" label="Total Rooms" :value="$totalRooms" color="secondary" href="{{ route('admin.room-types.index') }}" />
        </div>
        <div class="col-md-6 col-lg-3 mb-3">
            <x-stat-card icon="fas fa-bed" label="Occupancy Rate" value="{{ $occupancyRate }}%" color="primary" />
        </div>
        <div class="col-md-6 col-lg-3 mb-3">
            <x-stat-card icon="fas fa-sign-in-alt" label="Today's Check-Ins" :value="$todayCheckIns" color="success" href="{{ route('receptionist.check-in.index') }}" />
        </div>
        <div class="col-md-6 col-lg-3 mb-3">
            <x-stat-card icon="fas fa-sign-out-alt" label="Today's Check-Outs" :value="$todayCheckOuts" color="info" href="{{ route('receptionist.check-out.index') }}" />
        </div>
    </div>

    <div class="row mb-4">
        <div class="col-md-6 col-lg-3 mb-3">
            <x-stat-card icon="fas fa-door-open" label="Available Rooms" :value="$availableRooms" color="success" href="{{ route('admin.room-types.index') }}" />
        </div>
        <div class="col-md-6 col-lg-3 mb-3">
            <x-stat-card icon="fas fa-bed" label="Occupied Rooms" :value="$occupiedRooms" color="primary" href="{{ route('admin.room-types.index') }}" />
        </div>
        <div class="col-md-6 col-lg-3 mb-3">
            <x-stat-card icon="fas fa-users" label="Check-In Guests" :value="$inHouseGuests" color="warning" />
        </div>
        <div class="col-md-6 col-lg-3 mb-3">
            <x-stat-card icon="fas fa-hourglass-half" label="Pending Payment Verifications" :value="$pendingPaymentVerifications" color="danger" />
        </div>
    </div>

    <!-- Period-filtered performance -->
    <div class="detail-section-title"><i class="fas fa-chart-line"></i> Performance for the Selected Period</div>
    <div class="row mb-4">
        <div class="col-md-6 col-lg-3 mb-3">
            <x-stat-card icon="fas fa-money-bill-wave" label="Revenue" value="₱{{ number_format($periodRevenue, 2) }}" color="success" href="{{ route('manager.reports.index', ['from' => $periodFrom->toDateString(), 'to' => $periodTo->toDateString()]) }}" />
        </div>
        <div class="col-md-6 col-lg-3 mb-3">
            <x-stat-card icon="fas fa-calendar-alt" label="Reservations" :value="$totalReservations" color="secondary" href="{{ route('manager.reservations.index', ['from' => $periodFrom->toDateString(), 'to' => $periodTo->toDateString()]) }}" />
        </div>
        <div class="col-md-6 col-lg-3 mb-3">
            <x-stat-card icon="fas fa-credit-card" label="Bookings" :value="$totalBookings" color="primary" href="{{ route('manager.reservations.index', ['type' => 'booking', 'from' => $periodFrom->toDateString(), 'to' => $periodTo->toDateString()]) }}" />
        </div>
        <div class="col-md-6 col-lg-3 mb-3">
            <x-stat-card icon="fas fa-moon" label="Avg. Length of Stay" value="{{ $averageLengthOfStay }} nights" color="info" />
        </div>
    </div>

    <div class="row mb-4">
        <div class="col-md-6 col-lg-3 mb-3">
            <x-stat-card icon="fas fa-ban" label="Cancellation Rate" value="{{ $cancellationRate }}%" color="{{ $cancellationRate > 15 ? 'danger' : 'warning' }}" />
        </div>
        <div class="col-md-6 col-lg-3 mb-3">
            <x-stat-card icon="fas fa-user-clock" label="No-Show Rate" value="{{ $noShowRate }}%" color="{{ $noShowRate > 10 ? 'danger' : 'warning' }}" />
        </div>
        <div class="col-md-6 col-lg-6 mb-3">
            <x-collapsible-card id="managerRoomUtilization" title="Room Utilization by Type" icon="fas fa-percentage" bodyClass="card-body py-2">
                @forelse($roomUtilization as $row)
                    <div class="d-flex justify-content-between align-items-center mb-2">
                        <span class="small">{{ $row['room_type'] }}</span>
                        <div class="flex-grow-1 mx-2">
                            <div class="progress" style="height: 8px;">
                                <div class="progress-bar bg-brand" style="width: {{ $row['utilization'] }}%"></div>
                            </div>
                        </div>
                        <span class="small text-muted">{{ $row['utilization'] }}%</span>
                    </div>
                @empty
                    <x-empty-state icon="fas fa-percentage" message="No room types yet." />
                @endforelse
            </x-collapsible-card>
        </div>
    </div>

    <!-- Booking Trend -->
    <div class="row mb-4">
        <div class="col-12">
            <x-collapsible-card id="managerBookingTrend" title="Booking Trend" icon="fas fa-chart-line" bodyClass="card-body">
                <canvas id="bookingTrendChart" height="90"></canvas>
            </x-collapsible-card>
        </div>
    </div>

    <!-- Category Breakdown Charts -->
    @php
        $bookingsByStatusLegend = [
            ['label' => 'Confirmed', 'value' => $bookingsByStatus['confirmed'] ?? 0, 'color' => '#17a2b8'],
            ['label' => 'Checked In', 'value' => $bookingsByStatus['checked_in'] ?? 0, 'color' => '#D6414B'],
            ['label' => 'Checked Out', 'value' => $bookingsByStatus['checked_out'] ?? 0, 'color' => '#28a745'],
            ['label' => 'Cancelled', 'value' => $bookingsByStatus['cancelled'] ?? 0, 'color' => '#6c757d'],
        ];
        $mgrRoomsByStatusLegend = [
            ['label' => 'Available', 'value' => $availableRooms, 'color' => '#28a745'],
            ['label' => 'Occupied', 'value' => $occupiedRooms, 'color' => '#D6414B'],
            ['label' => 'Maintenance', 'value' => $maintenanceRooms, 'color' => '#ffc107'],
        ];
        $roomTypeColors = ['#D6414B', '#D4AF37', '#28a745', '#17a2b8', '#6c757d'];
        $topRoomTypesLegend = [];
        foreach ($topRoomTypes as $i => $rt) {
            $topRoomTypesLegend[] = ['label' => $rt->name, 'value' => $rt->bookings_count, 'color' => $roomTypeColors[$i % count($roomTypeColors)]];
        }
    @endphp
    <div class="row mb-4">
        <div class="col-lg-4 mb-3">
            <x-chart-card
                icon="fas fa-calendar-check"
                title="Bookings by Status"
                canvasId="bookingsByStatusChart"
                href="{{ route('manager.reservations.index') }}"
                :legend="$bookingsByStatusLegend" />
        </div>
        <div class="col-lg-4 mb-3">
            <x-chart-card
                icon="fas fa-door-open"
                title="Rooms by Status"
                canvasId="mgrRoomsByStatusChart"
                :legend="$mgrRoomsByStatusLegend" />
        </div>
        <div class="col-lg-4 mb-3">
            <x-chart-card
                icon="fas fa-star"
                title="Top Room Types"
                canvasId="topRoomTypesChart"
                :legend="$topRoomTypesLegend" />
        </div>
    </div>

    <!-- Top Room Types -->
    <div class="row mb-4">
        <div class="col-12">
            <x-collapsible-card id="managerTopRoomTypes" title="Top Room Types" icon="fas fa-star" bodyClass="card-body">
                <div id="managerTopRoomTypes-list" data-preview-list data-preview-persist-key="dash-preview-managerTopRoomTypes">
                    @forelse($topRoomTypesList as $roomType)
                        <div class="d-flex justify-content-between align-items-center mb-3 pb-2 {{ $loop->index >= 5 ? 'preview-extra d-none' : '' }}" style="border-bottom: 1px solid #f0f0f0;">
                            <div>
                                <strong>{{ $roomType->name }}</strong><br>
                                <small class="text-muted">Up to {{ $roomType->capacity }} guests &middot; ₱{{ number_format($roomType->rate, 2) }}/night</small>
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
    </div>

    <!-- Recent Booking & Reservations -->
    <div class="row">
        <div class="col-12">
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
                                    <td>{{ $reservation->stay_guest_full_name ?? $reservation->guest->user->full_name ?? 'N/A' }}</td>
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

        doughnutChart(
            'bookingsByStatusChart',
            ['Confirmed', 'Checked In', 'Checked Out', 'Cancelled'],
            [{{ $bookingsByStatus['confirmed'] ?? 0 }}, {{ $bookingsByStatus['checked_in'] ?? 0 }}, {{ $bookingsByStatus['checked_out'] ?? 0 }}, {{ $bookingsByStatus['cancelled'] ?? 0 }}],
            ['#17a2b8', '#D6414B', '#28a745', '#6c757d']
        );
        doughnutChart(
            'mgrRoomsByStatusChart',
            ['Available', 'Occupied', 'Maintenance'],
            [{{ $availableRooms }}, {{ $occupiedRooms }}, {{ $maintenanceRooms }}],
            ['#28a745', '#D6414B', '#ffc107']
        );
        doughnutChart(
            'topRoomTypesChart',
            @json($topRoomTypes->pluck('name')),
            @json($topRoomTypes->pluck('bookings_count')),
            @json($roomTypeColors)
        );
    }

    document.addEventListener('DOMContentLoaded', initDashboardCharts);
    window.addEventListener('auto-refresh:swapped', initDashboardCharts);
})();
</script>
@endpush

@include('components.auto-refresh')
@endsection
