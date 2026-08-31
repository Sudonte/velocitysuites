@extends('layouts.app')

@section('title', 'Receptionist Dashboard')

@section('content')
<div class="container-fluid py-4">
    <x-page-header icon="fas fa-home" title="Welcome, {{ auth()->user()->full_name }}!" subtitle="Here's today's front-desk overview." :showClock="true" />

    <!-- KPI Cards: live work-queue counts (click through to each module) -->
    <div class="detail-section-title"><i class="fas fa-list-check"></i> Front Desk Overview</div>
    <div class="row mb-4">
        <div class="col-md-6 col-lg-3 mb-3">
            <x-stat-card icon="fas fa-inbox" label="Reservations" :value="$bookingRequests" color="warning" :href="route('receptionist.reservations.index')" />
        </div>

        <div class="col-md-6 col-lg-3 mb-3">
            <x-stat-card icon="fas fa-sign-in-alt" label="Awaiting Check-In" :value="$awaitingCheckIn" color="primary" :href="route('receptionist.check-in.index')" />
        </div>

        <div class="col-md-6 col-lg-3 mb-3">
            <x-stat-card icon="fas fa-concierge-bell" label="In-House Guests" :value="$inHouseGuests" color="info" :href="route('receptionist.check-out.index')" />
        </div>

        <div class="col-md-6 col-lg-3 mb-3">
            <x-stat-card icon="fas fa-door-open" label="Available Rooms" :value="$availableRooms" color="success" :href="route('receptionist.rooms.index')" />
        </div>
    </div>

    <!-- Current Occupancy Status -->
    @php $totalKnownRooms = max(1, $availableRooms + $occupiedRooms + $maintenanceRooms); @endphp
    <x-collapsible-card id="receptionistOccupancyStatus" title="Current Occupancy Status" icon="fas fa-chart-pie" bodyClass="card-body" class="mb-4">
        <div class="row align-items-center">
            <div class="col-md-5 col-lg-4">
                <div class="chart-card-canvas-wrap">
                    <canvas id="occupancyStatusChart"></canvas>
                </div>
            </div>
            <div class="col-md-7 col-lg-8">
                <ul class="chart-card-legend list-unstyled mb-0 mt-3 mt-md-0">
                    <li class="d-flex align-items-center justify-content-between">
                        <span class="d-flex align-items-center gap-2">
                            <span class="chart-legend-dot" style="background-color: #28a745;"></span>
                            Available
                        </span>
                        <span class="text-end">
                            <strong>{{ $availableRooms }}</strong>
                            <span class="text-muted">({{ round($availableRooms / $totalKnownRooms * 100, 1) }}%)</span>
                        </span>
                    </li>
                    <li class="d-flex align-items-center justify-content-between">
                        <span class="d-flex align-items-center gap-2">
                            <span class="chart-legend-dot" style="background-color: #D6414B;"></span>
                            Occupied
                        </span>
                        <span class="text-end">
                            <strong>{{ $occupiedRooms }}</strong>
                            <span class="text-muted">({{ round($occupiedRooms / $totalKnownRooms * 100, 1) }}%)</span>
                        </span>
                    </li>
                    <li class="d-flex align-items-center justify-content-between">
                        <span class="d-flex align-items-center gap-2">
                            <span class="chart-legend-dot" style="background-color: #ffc107;"></span>
                            Maintenance
                        </span>
                        <span class="text-end">
                            <strong>{{ $maintenanceRooms }}</strong>
                            <span class="text-muted">({{ round($maintenanceRooms / $totalKnownRooms * 100, 1) }}%)</span>
                        </span>
                    </li>
                </ul>
            </div>
        </div>
    </x-collapsible-card>

    <div class="detail-section-title"><i class="fas fa-calendar-day"></i> Today's Schedule</div>
    <div class="row">
        <!-- Pending Arrivals (today's confirmed bookings not yet checked in) -->
        <div class="col-lg-6">
            <x-collapsible-card id="receptionistPendingArrivals" title="Pending Arrivals" icon="fas fa-sign-in-alt" bodyClass="table-responsive" class="mb-4">
                <table class="table table-hover mb-0">
                    <thead>
                        <tr>
                            <th>Guest</th>
                            <th>Room</th>
                            <th>Status</th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse($pendingArrivals as $booking)
                            <tr>
                                <td>{{ $booking->account_guest_full_name ?? 'N/A' }}</td>
                                <td>{{ $booking->room->room_number ?? $booking->roomType->name ?? 'N/A' }}</td>
                                <td><x-status-badge :status="$booking->booking_status" domain="booking" /></td>
                            </tr>
                        @empty
                            <tr><td colspan="3"><x-empty-state icon="fas fa-sign-in-alt" message="No pending arrivals today." /></td></tr>
                        @endforelse
                    </tbody>
                </table>
            </x-collapsible-card>
        </div>

        <!-- Today's Departures -->
        <div class="col-lg-6">
            <x-collapsible-card id="receptionistTodayDepartures" title="Today's Departures" icon="fas fa-sign-out-alt" bodyClass="table-responsive" class="mb-4">
                <table class="table table-hover mb-0">
                    <thead>
                        <tr>
                            <th>Guest</th>
                            <th>Room</th>
                            <th>Status</th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse($todayDepartures as $booking)
                            <tr>
                                <td>{{ $booking->account_guest_full_name ?? 'N/A' }}</td>
                                <td>{{ $booking->room->room_number ?? 'N/A' }}</td>
                                <td><x-status-badge :status="$booking->booking_status" domain="booking" /></td>
                            </tr>
                        @empty
                            <tr><td colspan="3"><x-empty-state icon="fas fa-sign-out-alt" message="No departures today." /></td></tr>
                        @endforelse
                    </tbody>
                </table>
            </x-collapsible-card>
        </div>
    </div>

    <!-- Current Booking & Reservations -->
    <div class="row">
        <div class="col-12">
            <x-collapsible-card id="receptionistCurrentBookingReservations" title="Current Booking & Reservations" icon="fas fa-calendar-check" bodyClass="table-responsive" class="mb-4">
                <div id="receptionistCurrentBookingReservations-list" data-preview-list data-preview-persist-key="dash-preview-receptionistCurrentBookingReservations">
                    <table class="table table-hover mb-0">
                        <thead>
                            <tr>
                                <th>Guest</th>
                                <th>Type</th>
                                <th>Room Type</th>
                                <th>Check-In</th>
                                <th>Check-Out</th>
                                <th>Status</th>
                                <th></th>
                            </tr>
                        </thead>
                        <tbody>
                            @forelse($currentReservations as $item)
                                <tr class="{{ $loop->index >= 5 ? 'preview-extra d-none' : '' }}">
                                    <td>{{ $item->guest->user->full_name ?? 'N/A' }}</td>
                                    <td>
                                        @if($item->booking)
                                            <span class="badge bg-primary">Booking</span>
                                        @else
                                            <span class="badge bg-secondary">Reservation</span>
                                        @endif
                                    </td>
                                    <td>{{ $item->roomType->name ?? 'N/A' }}</td>
                                    <td>{{ $item->check_in->format('M d, Y') }}</td>
                                    <td>{{ $item->check_out->format('M d, Y') }}</td>
                                    <td>
                                        @if($item->booking)
                                            <x-status-badge :status="$item->booking->booking_status" domain="booking" />
                                        @else
                                            <x-status-badge :status="$item->status" domain="reservation" />
                                        @endif
                                    </td>
                                    <td>
                                        @if($item->booking)
                                            <a href="{{ route('receptionist.bookings.show', $item->booking) }}" class="btn btn-sm btn-primary">
                                                <i class="fas fa-eye"></i>
                                            </a>
                                        @endif
                                    </td>
                                </tr>
                            @empty
                                <tr><td colspan="7"><x-empty-state icon="fas fa-calendar-check" message="No current bookings or reservations." /></td></tr>
                            @endforelse
                        </tbody>
                    </table>
                    @if($currentReservations->count() > 5)
                        <button type="button" class="btn btn-sm btn-outline-secondary w-100 mt-2 preview-toggle-btn" aria-expanded="false" aria-controls="receptionistCurrentBookingReservations-list">
                            <i class="fas fa-chevron-down"></i> Expand
                        </button>
                    @endif
                </div>
                <div class="text-end mt-2">
                    <a href="{{ route('receptionist.bookings.index') }}" class="small">View all &raquo;</a>
                </div>
            </x-collapsible-card>
        </div>
    </div>

    <!-- Recent Booking Activities -->
    <div class="row">
        <div class="col-12">
            <x-collapsible-card id="receptionistRecentActivities" title="Recent Booking Activities" icon="fas fa-history" bodyClass="card-body" class="mb-4">
                <div id="receptionistRecentActivities-list" data-preview-list data-preview-persist-key="dash-preview-receptionistRecentActivities">
                    @forelse($recentBookingActivities as $activity)
                        <div class="d-flex mb-3 {{ $loop->index >= 5 ? 'preview-extra d-none' : '' }}">
                            <div class="flex-shrink-0">
                                <i class="fas fa-circle text-brand" style="font-size: 0.5rem;"></i>
                            </div>
                            <div class="flex-grow-1 ms-3">
                                <p class="mb-1 text-sm"><strong>{{ $activity->user->full_name }}</strong></p>
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
                        <x-empty-state icon="fas fa-history" message="No recent activity." />
                    @endforelse
                    @if($recentBookingActivities->count() > 5)
                        <button type="button" class="btn btn-sm btn-outline-secondary w-100 mt-2 preview-toggle-btn" aria-expanded="false" aria-controls="receptionistRecentActivities-list">
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
    let occupancyChart = null;

    function initOccupancyChart() {
        const canvas = document.getElementById('occupancyStatusChart');
        if (!canvas) return;

        // The auto-refresh component replaces <main>'s innerHTML wholesale
        // (including this <canvas>) without re-running scripts, so any
        // chart instance bound to the old node is now orphaned - destroy it
        // before drawing a fresh one on the new node.
        if (occupancyChart) {
            occupancyChart.destroy();
            occupancyChart = null;
        }

        occupancyChart = new Chart(canvas.getContext('2d'), {
            type: 'pie',
            data: {
                labels: ['Available', 'Occupied', 'Maintenance'],
                datasets: [{
                    data: [{{ $availableRooms }}, {{ $occupiedRooms }}, {{ $maintenanceRooms }}],
                    backgroundColor: ['#28a745', '#D6414B', '#ffc107'],
                    borderWidth: 0,
                }],
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                plugins: { legend: { display: false } },
            },
        });
    }

    document.addEventListener('DOMContentLoaded', initOccupancyChart);
    window.addEventListener('auto-refresh:swapped', initOccupancyChart);
})();
</script>
@endpush

@include('components.auto-refresh')
@endsection
