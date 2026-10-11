{{--
    Report body shared by the Admin and Manager report screens - see
    App\Services\HotelReportService for how each figure is computed.

    @param array $report       HotelReportService::build() output
    @param bool  $withRevenue  room revenue, ADR, RevPAR, graph and per-day table (Admin + Manager);
                               Other Charges / Payments Collected only render when present (Admin)
--}}
@php
    $s = $report['summary'];
    $peso = fn ($v) => '₱' . number_format((float) $v, 2);
@endphp

<div class="row g-3 mb-3">
    <div class="col-6 col-lg-3">
        <x-stat-card icon="fas fa-bed" label="Occupancy ({{ number_format($s['room_nights_sold']) }}/{{ number_format($s['room_nights_available']) }} room-nights)" value="{{ $s['occupancy'] }}%" color="primary" />
    </div>
    <div class="col-6 col-lg-3">
        <x-stat-card icon="fas fa-right-left" label="Arrivals / Departures" value="{{ $s['arrivals'] }} / {{ $s['departures'] }}" color="success" />
    </div>
    <div class="col-6 col-lg-3">
        <x-stat-card icon="fas fa-users" label="In-House Now (rooms / guests)" value="{{ $s['in_house_rooms'] }} / {{ $s['in_house_guests'] }}" color="info" />
    </div>
    <div class="col-6 col-lg-3">
        <x-stat-card icon="fas fa-door-closed" label="Rooms / Out of Order" value="{{ $s['inventory'] }} / {{ $s['out_of_order'] }}" color="secondary" />
    </div>
</div>

{{-- Booking summary [REQ049] + cancellation / no-show [REQ051, REQ062] --}}
<div class="row g-3 mb-3">
    <div class="col-6 col-md-4 col-xl"><x-stat-card icon="fas fa-list" label="Total Stays" :value="$s['stays_total']" color="secondary" /></div>
    <div class="col-6 col-md-4 col-xl"><x-stat-card icon="fas fa-circle-check" label="Confirmed" :value="$s['stays_confirmed']" color="success" /></div>
    <div class="col-6 col-md-4 col-xl"><x-stat-card icon="fas fa-hourglass-half" label="Pending" :value="$s['stays_pending']" color="warning" /></div>
    <div class="col-6 col-md-6 col-xl"><x-stat-card icon="fas fa-ban" label="Cancelled ({{ $s['cancellation_rate'] }}%)" :value="$s['cancellations']" color="danger" /></div>
    <div class="col-12 col-md-6 col-xl"><x-stat-card icon="fas fa-user-slash" label="No-Shows ({{ $s['no_show_rate'] }}%)" :value="$s['no_shows']" color="dark" /></div>
</div>

{{-- Guest stay statistics [REQ052] --}}
<div class="row g-3 mb-3">
    <div class="col-6 col-lg-3"><x-stat-card icon="fas fa-calendar-alt" label="Reservations" :value="$s['reservations']" color="info" /></div>
    <div class="col-6 col-lg-3"><x-stat-card icon="fas fa-credit-card" label="Direct Bookings" :value="$s['bookings']" color="primary" /></div>
    <div class="col-6 col-lg-3"><x-stat-card icon="fas fa-people-group" label="Total Guests Stayed" :value="$s['guests_total']" color="success" /></div>
    <div class="col-6 col-lg-3"><x-stat-card icon="fas fa-moon" label="Average Stay" value="{{ number_format($s['average_stay'], 1) }} nights" color="secondary" /></div>
</div>

@if($withRevenue)
    <div class="row g-3 mb-4">
        <div class="col-6 col-lg">
            <x-stat-card icon="fas fa-hotel" label="Room Revenue" :value="$peso($s['room_revenue'])" color="success" />
        </div>
        <div class="col-6 col-lg">
            <x-stat-card icon="fas fa-tag" label="ADR (avg. daily rate)" :value="$peso($s['adr'])" color="primary" />
        </div>
        <div class="col-6 col-lg">
            <x-stat-card icon="fas fa-chart-line" label="RevPAR" :value="$peso($s['revpar'])" color="info" />
        </div>
        @isset($s['payments_collected'])
            <div class="col-6 col-lg">
                <x-stat-card icon="fas fa-concierge-bell" label="Other Charges" :value="$peso($s['other_charges'])" color="secondary" />
            </div>
            <div class="col-12 col-lg">
                <x-stat-card icon="fas fa-money-bill-wave" label="Payments Collected" :value="$peso($s['payments_collected'])" color="warning" />
            </div>
        @endisset
    </div>
    <p class="text-muted small mt-n2 mb-4">
        <i class="fas fa-info-circle"></i>
        Room revenue = room rate &times; nights stayed in the period. ADR = room revenue &divide; room-nights sold; RevPAR = room revenue &divide; room-nights available.
        @isset($s['payments_collected'])
            Other charges = amenities, extra-guest fees and additional charges on stays that checked out in the period. Payments collected = money received in the period (GCash once verified).
        @endisset
    </p>

    <x-card title="Occupancy and Room Revenue by {{ $report['bucketUnit'] }}" icon="fas fa-chart-column" bodyClass="card-body" class="mb-4">
        <div style="height: 280px;"><canvas id="reportOccupancyChart"></canvas></div>
    </x-card>

    <x-card title="Performance by {{ $report['bucketUnit'] }}" icon="fas fa-table" bodyClass="table-responsive" class="mb-4">
        <table class="table table-hover table-sm mb-0">
            <thead>
                <tr>
                    <th>{{ $report['bucketUnit'] }}</th>
                    <th class="text-end">Rooms Available</th>
                    <th class="text-end">Rooms Occupied</th>
                    <th class="text-end">Occupancy</th>
                    <th class="text-end">ADR</th>
                    <th class="text-end">RevPAR</th>
                    <th class="text-end">Room Revenue</th>
                </tr>
            </thead>
            <tbody>
                @foreach($report['buckets'] as $b)
                    <tr>
                        <td>{{ $b['label'] }}</td>
                        <td class="text-end">{{ number_format($b['available']) }}</td>
                        <td class="text-end">{{ number_format($b['occupied']) }}</td>
                        <td class="text-end">{{ $b['occupancy'] }}%</td>
                        <td class="text-end">{{ $peso($b['adr']) }}</td>
                        <td class="text-end">{{ $peso($b['revpar']) }}</td>
                        <td class="text-end">{{ $peso($b['revenue']) }}</td>
                    </tr>
                @endforeach
            </tbody>
            <tfoot class="fw-bold">
                <tr>
                    <td>Total / Average</td>
                    <td class="text-end">{{ number_format($s['room_nights_available']) }}</td>
                    <td class="text-end">{{ number_format($s['room_nights_sold']) }}</td>
                    <td class="text-end">{{ $s['occupancy'] }}%</td>
                    <td class="text-end">{{ $peso($s['adr']) }}</td>
                    <td class="text-end">{{ $peso($s['revpar']) }}</td>
                    <td class="text-end">{{ $peso($s['room_revenue']) }}</td>
                </tr>
            </tfoot>
        </table>
    </x-card>

    @push('scripts')
    <script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.0/dist/chart.umd.min.js"></script>
    <script>
    document.addEventListener('DOMContentLoaded', function () {
        const canvas = document.getElementById('reportOccupancyChart');
        if (!canvas || typeof Chart === 'undefined') return;
        const buckets = @json($report['buckets']);
        new Chart(canvas.getContext('2d'), {
            data: {
                labels: buckets.map((b) => b.label),
                datasets: [
                    { type: 'bar', label: 'Occupancy %', data: buckets.map((b) => b.occupancy), backgroundColor: 'rgba(214, 65, 75, 0.75)', yAxisID: 'y' },
                    { type: 'line', label: 'Room Revenue (₱)', data: buckets.map((b) => b.revenue), borderColor: '#1B2A4A', backgroundColor: '#1B2A4A', tension: 0.25, yAxisID: 'y1' },
                ],
            },
            options: {
                maintainAspectRatio: false,
                interaction: { mode: 'index', intersect: false },
                scales: {
                    y: { beginAtZero: true, max: 100, title: { display: true, text: 'Occupancy %' } },
                    y1: { beginAtZero: true, position: 'right', grid: { drawOnChartArea: false }, title: { display: true, text: 'Room Revenue (₱)' } },
                },
            },
        });
    });
    </script>
    @endpush
@endif

<x-card title="Room Types" icon="fas fa-door-open" bodyClass="table-responsive" class="mb-4">
    <table class="table table-hover mb-0">
        <thead>
            <tr>
                <th>Room Type</th>
                <th class="text-end">Rooms</th>
                <th class="text-end">Reservations</th>
                <th class="text-end">Nights Sold</th>
                <th class="text-end">Occupancy</th>
                @if($withRevenue)
                    <th class="text-end">Room Revenue</th>
                    <th class="text-end">ADR</th>
                @endif
            </tr>
        </thead>
        <tbody>
            @forelse($report['roomTypeRows'] as $row)
                <tr>
                    <td>{{ $row['name'] }}</td>
                    <td class="text-end">{{ $row['rooms'] }}</td>
                    <td class="text-end">{{ $row['reservations'] }}</td>
                    <td class="text-end">{{ $row['nights_sold'] }}</td>
                    <td class="text-end">{{ $row['occupancy'] }}%</td>
                    @if($withRevenue)
                        <td class="text-end">{{ $peso($row['room_revenue']) }}</td>
                        <td class="text-end">{{ $peso($row['adr']) }}</td>
                    @endif
                </tr>
            @empty
                <tr><td colspan="{{ $withRevenue ? 7 : 5 }}"><x-empty-state icon="fas fa-door-open" message="No room types." /></td></tr>
            @endforelse
        </tbody>
    </table>
</x-card>

<x-card title="Guests" icon="fas fa-users" bodyClass="table-responsive" class="mb-4">
    <table class="table table-hover mb-0">
        <thead>
            <tr>
                <th>Guest</th>
                <th class="text-end">Stays</th>
                <th class="text-end">Nights</th>
            </tr>
        </thead>
        <tbody>
            @forelse($report['guestRows'] as $row)
                <tr>
                    <td>{{ $row['name'] }}</td>
                    <td class="text-end">{{ $row['stays'] }}</td>
                    <td class="text-end">{{ $row['nights'] }}</td>
                </tr>
            @empty
                <tr><td colspan="3"><x-empty-state icon="fas fa-users" message="No guest stays in this period." /></td></tr>
            @endforelse
        </tbody>
    </table>
</x-card>

<x-card title="Cancellations and No-Shows" icon="fas fa-user-slash" bodyClass="table-responsive" class="mb-4">
    <table class="table table-hover mb-0">
        <thead>
            <tr><th>Ref</th><th>Guest</th><th>Room Type</th><th>Check-in</th><th>Status</th><th>Reason</th><th>Marked By</th></tr>
        </thead>
        <tbody>
            @forelse($report['cancellationRows'] as $row)
                <tr>
                    <td>{{ $row['ref'] }}</td>
                    <td>{{ $row['guest'] }}</td>
                    <td>{{ $row['room_type'] }}</td>
                    <td>{{ $row['check_in']->format('M d, Y') }}</td>
                    <td><span class="badge {{ $row['status'] === 'No-Show' ? 'bg-dark' : 'bg-danger' }}">{{ $row['status'] }}</span></td>
                    <td>{{ $row['reason'] ?: '—' }}</td>
                    <td>{{ $row['by'] ?: '—' }}</td>
                </tr>
            @empty
                <tr><td colspan="7"><x-empty-state icon="fas fa-user-slash" message="No cancellations or no-shows in this period." /></td></tr>
            @endforelse
        </tbody>
    </table>
</x-card>
