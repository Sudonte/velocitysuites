@extends('layouts.app')

@section('title', 'Receptionist Dashboard')

@section('content')
<div class="container-fluid py-4">
    <x-page-header icon="fas fa-home" title="Welcome, {{ auth()->user()->full_name }}!" subtitle="Here's today's front-desk overview." :showClock="true" />

    {{-- Today's work queues; each number matches the list it opens. --}}
    <div class="row g-3 mb-4 dashboard-kpis">
        <div class="col-6 col-md-4 col-xl-2">
            <x-stat-card icon="fas fa-inbox" label="Reservations to Review" :value="$bookingRequests" color="warning" :href="route('receptionist.reservations.index')" />
        </div>
        <div class="col-6 col-md-4 col-xl-2">
            <x-stat-card icon="fas fa-receipt" label="Bookings to Verify" :value="$bookingsToVerify" color="danger" :href="route('receptionist.bookings.index', ['tab' => 'pending'])" />
        </div>
        <div class="col-6 col-md-4 col-xl-2">
            <x-stat-card icon="fas fa-sign-in-alt" label="Expected Check-ins" :value="$awaitingCheckIn" color="primary" :href="route('receptionist.check-in.index', ['tab' => 'expected', 'range' => 'all'])" />
        </div>
        <div class="col-6 col-md-4 col-xl-2">
            <x-stat-card icon="fas fa-sign-out-alt" label="Departures Today" :value="$todayDepartures->count()" color="info" :href="route('receptionist.check-out.index')" />
        </div>
        <div class="col-6 col-md-4 col-xl-2">
            <x-stat-card icon="fas fa-concierge-bell" label="In-House Guests" :value="$inHouseGuests" color="secondary" :href="route('receptionist.check-in.index', ['tab' => 'checked_in'])" />
        </div>
        <div class="col-6 col-md-4 col-xl-2">
            <x-stat-card icon="fas fa-door-open" label="Available Rooms" :value="$availableRooms" color="success" :href="route('receptionist.rooms.index')" />
        </div>
    </div>

    <!-- Current Occupancy Status -->
    @php $totalKnownRooms = max(1, $availableRooms + $occupiedRooms + $maintenanceRooms); @endphp
    <div class="row g-3 mb-4">
    <div class="col-xl-4">
    <x-collapsible-card id="receptionistOccupancyStatus" title="Current Occupancy" icon="fas fa-chart-pie" bodyClass="card-body" class="h-100">
        <div class="row align-items-center">
            <div class="col-5 col-xl-12">
                <div class="chart-card-canvas-wrap">
                    <canvas id="occupancyStatusChart"></canvas>
                </div>
            </div>
            <div class="col-7 col-xl-12">
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
    </div>

        <!-- Pending Arrivals (today's confirmed bookings not yet checked in) -->
        <div class="col-md-6 col-xl-4">
            <x-collapsible-card id="receptionistPendingArrivals" title="Arrivals Today" icon="fas fa-sign-in-alt" bodyClass="table-responsive" class="h-100">
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
                                <td>{{ $booking->guest_display_name }}</td>
                                <td>{{ $booking->room->room_number ?? $booking->roomType->name ?? 'N/A' }}</td>
                                <td><x-status-badge :status="$booking->display_status" domain="booking" /></td>
                            </tr>
                        @empty
                            <tr><td colspan="3"><x-empty-state icon="fas fa-sign-in-alt" message="No pending arrivals today." /></td></tr>
                        @endforelse
                    </tbody>
                </table>
            </x-collapsible-card>
        </div>

        <!-- Today's Departures -->
        <div class="col-md-6 col-xl-4">
            <x-collapsible-card id="receptionistTodayDepartures" title="Departures Today" icon="fas fa-sign-out-alt" bodyClass="table-responsive" class="h-100">
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
                                <td>{{ $booking->guest_display_name }}</td>
                                <td>{{ $booking->room->room_number ?? 'N/A' }}</td>
                                <td><x-status-badge :status="$booking->display_status" domain="booking" /></td>
                            </tr>
                        @empty
                            <tr><td colspan="3"><x-empty-state icon="fas fa-sign-out-alt" message="No departures today." /></td></tr>
                        @endforelse
                    </tbody>
                </table>
            </x-collapsible-card>
        </div>
    </div>

    <!-- Current Confirmed Bookings -->
    <div class="row">
        <div class="col-12">
            <x-collapsible-card id="receptionistCurrentConfirmedBookings" title="Current Confirmed Bookings" icon="fas fa-calendar-check" bodyClass="table-responsive" class="mb-4">
                <div id="receptionistCurrentConfirmedBookings-list" data-preview-list data-preview-persist-key="dash-preview-receptionistCurrentConfirmedBookings">
                    <table class="table table-hover mb-0">
                        <thead>
                            <tr>
                                <th>Guest</th>
                                <th>Stays</th>
                                <th>Duration of Stay</th>
                                <th>Status</th>
                                <th></th>
                            </tr>
                        </thead>
                        <tbody>
                            @forelse($currentConfirmedBookings as $item)
                                <tr class="{{ $loop->index >= 5 ? 'preview-extra d-none' : '' }}">
                                    <td>{{ $item->booking->guest_display_name }}</td>
                                    <td>{{ $item->stayCount }} {{ \Illuminate\Support\Str::plural('stay', $item->stayCount) }}</td>
                                    <td>{{ $item->from->format($item->from->year === $item->to->year ? 'M d' : 'M d, Y') }} &ndash; {{ $item->to->format('M d, Y') }}</td>
                                    <td><x-status-badge :status="$item->booking->display_status" domain="booking" /></td>
                                    <td>
                                        <a href="{{ route('receptionist.bookings.show', $item->booking) }}" class="btn btn-outline-primary btn-sm btn-icon" title="View" aria-label="View">
                                            <i class="fas fa-eye"></i></a>
                                    </td>
                                </tr>
                            @empty
                                <tr><td colspan="5"><x-empty-state icon="fas fa-calendar-check" message="No confirmed bookings." /></td></tr>
                            @endforelse
                        </tbody>
                    </table>
                    @if($currentConfirmedBookings->count() > 5)
                        <button type="button" class="btn btn-sm btn-outline-secondary w-100 mt-2 preview-toggle-btn" aria-expanded="false" aria-controls="receptionistCurrentConfirmedBookings-list">
                            <i class="fas fa-chevron-down"></i> Expand
                        </button>
                    @endif
                </div>
                <div class="text-end mt-2">
                    <a href="{{ route('receptionist.bookings.index', ['tab' => 'verified']) }}" class="small">View all &raquo;</a>
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
