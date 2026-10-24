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
    $hasPayments = isset($s['payments_collected']);
    // One bucket (a single day) makes a chart of one bar - the key figures already say it.
    $showCharts = $withRevenue && count($report['buckets']) > 1;
@endphp

{{-- Key figures: the few numbers an owner checks first, each with a one-line plain explanation. --}}
<div class="report-kpis mb-4 {{ $withRevenue ? ($hasPayments ? 'report-kpis-5' : 'report-kpis-4') : 'report-kpis-1' }}">
    <div class="report-kpi">
        <div class="report-kpi-label">Occupancy</div>
        <div class="report-kpi-value">{{ $s['occupancy'] }}%</div>
        <div class="report-kpi-sub">{{ number_format($s['room_nights_sold']) }} of {{ number_format($s['room_nights_available']) }} room-nights sold</div>
    </div>
    @if($withRevenue)
        <div class="report-kpi">
            <div class="report-kpi-label">Room Revenue</div>
            <div class="report-kpi-value">{{ $peso($s['room_revenue']) }}</div>
            <div class="report-kpi-sub">Room rate &times; nights stayed</div>
        </div>
        <div class="report-kpi">
            <div class="report-kpi-label">ADR</div>
            <div class="report-kpi-value">{{ $peso($s['adr']) }}</div>
            <div class="report-kpi-sub">Average rate per occupied room</div>
        </div>
        <div class="report-kpi">
            <div class="report-kpi-label">RevPAR</div>
            <div class="report-kpi-value">{{ $peso($s['revpar']) }}</div>
            <div class="report-kpi-sub">Room revenue per available room</div>
        </div>
        @if($hasPayments)
            <div class="report-kpi">
                <div class="report-kpi-label">Payments Collected</div>
                <div class="report-kpi-value">{{ $peso($s['payments_collected']) }}</div>
                <div class="report-kpi-sub">Cash and verified GCash received</div>
            </div>
        @endif
    @endif
</div>

{{-- Details, grouped: rooms and front desk | bookings [REQ049, REQ051, REQ062] | guests [REQ052] --}}
<div class="row g-3 mb-4">
    <div class="col-lg-4">
        <div class="report-panel h-100">
            <div class="report-panel-title">Rooms &amp; Front Desk</div>
            <dl class="report-metrics">
                <div><dt>Arrivals</dt><dd>{{ number_format($s['arrivals']) }}</dd></div>
                <div><dt>Departures</dt><dd>{{ number_format($s['departures']) }}</dd></div>
                <div><dt>In-house rooms</dt><dd>{{ number_format($s['in_house_rooms']) }}</dd></div>
                <div><dt>In-house guests</dt><dd>{{ number_format($s['in_house_guests']) }}</dd></div>
                <div><dt>Total rooms</dt><dd>{{ number_format($s['inventory']) }}</dd></div>
                <div><dt>Out of order</dt><dd>{{ number_format($s['out_of_order']) }}</dd></div>
            </dl>
        </div>
    </div>
    <div class="col-lg-4">
        <div class="report-panel h-100">
            <div class="report-panel-title">Bookings</div>
            <dl class="report-metrics">
                <div><dt>Total stays</dt><dd>{{ number_format($s['stays_total']) }}</dd></div>
                <div><dt>Confirmed</dt><dd>{{ number_format($s['stays_confirmed']) }}</dd></div>
                <div><dt>Pending</dt><dd>{{ number_format($s['stays_pending']) }}</dd></div>
                <div><dt>Cancelled</dt><dd>{{ number_format($s['cancellations']) }} <small>{{ $s['cancellation_rate'] }}%</small></dd></div>
                <div><dt>No-shows</dt><dd>{{ number_format($s['no_shows']) }} <small>{{ $s['no_show_rate'] }}%</small></dd></div>
            </dl>
        </div>
    </div>
    <div class="col-lg-4">
        <div class="report-panel h-100">
            <div class="report-panel-title">Guest Stays</div>
            <dl class="report-metrics">
                <div><dt>Reservations</dt><dd>{{ number_format($s['reservations']) }}</dd></div>
                <div><dt>Direct bookings</dt><dd>{{ number_format($s['bookings']) }}</dd></div>
                <div><dt>Guests stayed</dt><dd>{{ number_format($s['guests_total']) }}</dd></div>
                <div><dt>Average stay</dt><dd>{{ number_format($s['average_stay'], 1) }} <small>nights</small></dd></div>
                @if($hasPayments)
                    <div><dt>Other charges</dt><dd>{{ $peso($s['other_charges']) }}</dd></div>
                @endif
            </dl>
        </div>
    </div>
</div>

@if($withRevenue)
    <details class="report-howto mb-4">
        <summary><i class="fas fa-circle-info"></i> How these figures are calculated</summary>
        <p class="mb-0">
            Room revenue = room rate &times; nights stayed in the period. ADR = room revenue &divide; room-nights sold. RevPAR = room revenue &divide; room-nights available.
            @if($hasPayments)
                Other charges = amenities, extra-guest fees and additional charges on stays that checked out in the period. Payments collected = money received in the period (GCash once verified).
            @endif
        </p>
    </details>

    @if($showCharts)
        {{-- Two charts, one measure each (a % and a peso amount never share one chart). --}}
        <div class="row g-3 mb-4">
            <div class="col-lg-6">
                <x-card title="Occupancy by {{ $report['bucketUnit'] }}" bodyClass="card-body">
                    <div style="height: 240px;"><canvas id="reportOccupancyChart" aria-label="Occupancy by {{ strtolower($report['bucketUnit']) }}, in percent" role="img"></canvas></div>
                </x-card>
            </div>
            <div class="col-lg-6">
                <x-card title="Room Revenue by {{ $report['bucketUnit'] }}" bodyClass="card-body">
                    <div style="height: 240px;"><canvas id="reportRevenueChart" aria-label="Room revenue by {{ strtolower($report['bucketUnit']) }}, in pesos" role="img"></canvas></div>
                </x-card>
            </div>
        </div>
    @endif

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

    @if($showCharts)
    @push('scripts')
    <script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.0/dist/chart.umd.min.js"></script>
    <script>
    document.addEventListener('DOMContentLoaded', function () {
        if (typeof Chart === 'undefined') return;
        const buckets = @json($report['buckets']);
        const dark = document.documentElement.getAttribute('data-bs-theme') === 'dark';
        // One hue per chart (validated against both surfaces); recessive grid and axis ink.
        const ink = dark ? '#A9AFB8' : '#6B7280';
        const grid = dark ? 'rgba(255,255,255,0.07)' : 'rgba(17,24,39,0.06)';
        const peso = (v) => '₱' + Number(v).toLocaleString('en-PH', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
        Chart.defaults.font.family = getComputedStyle(document.body).fontFamily;
        Chart.defaults.color = ink;

        function barChart(id, values, color, opts) {
            const canvas = document.getElementById(id);
            if (!canvas) return;
            new Chart(canvas, {
                type: 'bar',
                data: {
                    labels: buckets.map((b) => b.label),
                    datasets: [{ data: values, backgroundColor: color, hoverBackgroundColor: color, borderRadius: 4, borderSkipped: 'start', maxBarThickness: 28, categoryPercentage: 0.7 }],
                },
                options: {
                    maintainAspectRatio: false,
                    plugins: {
                        legend: { display: false }, // one measure per chart - the card title names it
                        tooltip: { displayColors: false, padding: 10, callbacks: { label: (ctx) => opts.format(ctx.parsed.y) } },
                    },
                    scales: {
                        x: { grid: { display: false }, border: { display: false }, ticks: { maxRotation: 0, autoSkipPadding: 12 } },
                        y: { beginAtZero: true, max: opts.max, grid: { color: grid }, border: { display: false }, ticks: { maxTicksLimit: 5, callback: opts.tick } },
                    },
                },
            });
        }

        barChart('reportOccupancyChart', buckets.map((b) => b.occupancy), dark ? '#5B8BD6' : '#2F5EA8',
            { max: 100, format: (v) => v + '% occupied', tick: (v) => v + '%' });
        barChart('reportRevenueChart', buckets.map((b) => b.revenue), dark ? '#3DAA78' : '#1E8A5A',
            { max: undefined, format: peso, tick: (v) => '₱' + Number(v).toLocaleString('en-PH', { notation: 'compact' }) });
    });
    </script>
    @endpush
    @endif
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
                    <td><span class="badge rounded-pill {{ $row['status'] === 'No-Show' ? 'bg-secondary-subtle text-secondary-emphasis' : 'bg-danger-subtle text-danger-emphasis' }}"><i class="fas {{ $row['status'] === 'No-Show' ? 'fa-user-slash' : 'fa-ban' }} me-1"></i>{{ $row['status'] }}</span></td>
                    <td>{{ $row['reason'] ?: '—' }}</td>
                    <td>{{ $row['by'] ?: '—' }}</td>
                </tr>
            @empty
                <tr><td colspan="7"><x-empty-state icon="fas fa-user-slash" message="No cancellations or no-shows in this period." /></td></tr>
            @endforelse
        </tbody>
    </table>
</x-card>
