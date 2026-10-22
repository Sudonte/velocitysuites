{{--
    Printed report body shared by the Admin and Manager PDFs (dompdf - no
    JavaScript, so the graph is drawn with sized table cells).

    @param array  $report       HotelReportService::build() output
    @param bool   $withRevenue  Admin only: revenue figures, graph and per-period table
    @param string $periodLabel
--}}
@php
    $s = $report['summary'];
    $peso = fn ($v) => '&#8369;' . number_format((float) $v, 2);
    $buckets = $report['buckets'];
    $maxRevenue = max(1, ...array_map(fn ($b) => $b['revenue'], $buckets ?: [['revenue' => 0]]));
    $chartHeight = 110;
    $shortLabel = fn ($b) => $report['bucketUnit'] === 'Day' ? substr($b['label'], -2) : $b['label'];
@endphp

<h2 class="section-title">Summary</h2>
<table class="report-table">
    <thead><tr><th>Field</th><th>Value</th><th>What it counts</th></tr></thead>
    <tbody>
        <tr><td>Rooms in Inventory</td><td>{{ $s['inventory'] }}</td><td>Rooms not archived</td></tr>
        <tr><td>Rooms Out of Order</td><td>{{ $s['out_of_order'] }}</td><td>Under maintenance now</td></tr>
        <tr><td>Room-Nights Available</td><td>{{ number_format($s['room_nights_available']) }}</td><td>Rooms &times; days in the period</td></tr>
        <tr><td>Room-Nights Sold</td><td>{{ number_format($s['room_nights_sold']) }}</td><td>Nights rooms were occupied by checked-in guests</td></tr>
        <tr><td>Occupancy</td><td>{{ $s['occupancy'] }}%</td><td>Room-nights sold &divide; available</td></tr>
        <tr><td>Arrivals / Departures</td><td>{{ $s['arrivals'] }} / {{ $s['departures'] }}</td><td>Stays checked in / checked out with dates in the period</td></tr>
        <tr><td>In-House Guests</td><td>{{ $s['in_house_rooms'] }} rooms, {{ $s['in_house_guests'] }} guests</td><td>Checked in right now</td></tr>
        <tr><td>Total Stays</td><td>{{ $s['stays_total'] }}</td><td>Reservations not yet converted + bookings, check-in within the period</td></tr>
        <tr><td>Confirmed / Pending</td><td>{{ $s['stays_confirmed'] }} / {{ $s['stays_pending'] }}</td><td>Pending = awaiting payment or verification</td></tr>
        <tr><td>Cancelled</td><td>{{ $s['cancellations'] }} ({{ $s['cancellation_rate'] }}%)</td><td>Each cancelled stay counted once</td></tr>
        <tr><td>No-Shows</td><td>{{ $s['no_shows'] }} ({{ $s['no_show_rate'] }}%)</td><td>Marked by a receptionist</td></tr>
        <tr><td>Total Guests Stayed</td><td>{{ $s['guests_total'] }}</td><td>Adults + minors of stays that checked in</td></tr>
        @if($withRevenue)
            <tr><td>ADR</td><td>{!! $peso($s['adr']) !!}</td><td>Room revenue &divide; room-nights sold</td></tr>
            <tr><td>RevPAR</td><td>{!! $peso($s['revpar']) !!}</td><td>Room revenue &divide; room-nights available</td></tr>
            <tr><td>Room Revenue</td><td>{!! $peso($s['room_revenue']) !!}</td><td>Room rate &times; nights stayed in the period</td></tr>
            @isset($s['payments_collected'])
                <tr><td>Other Charges</td><td>{!! $peso($s['other_charges']) !!}</td><td>Amenities, extra-guest fees, additional charges (stays checked out in the period)</td></tr>
                <tr><td>Payments Collected</td><td>{!! $peso($s['payments_collected']) !!}</td><td>Money received in the period (GCash once verified)</td></tr>
            @endisset
        @endif
        <tr><td>Reservations</td><td>{{ $s['reservations'] }}</td><td>Check-in within the period</td></tr>
        <tr><td>Direct Bookings</td><td>{{ $s['bookings'] }}</td><td>Pay-first bookings, check-in within the period</td></tr>
        <tr><td>Average Length of Stay</td><td>{{ number_format($s['average_stay'], 1) }} nights</td><td>Across reservations in the period</td></tr>
    </tbody>
</table>

@if($withRevenue)
    <h2 class="section-title">Occupancy by {{ $report['bucketUnit'] }}</h2>
    <table style="width: 100%; border-collapse: collapse; table-layout: fixed;">
        <tr>
            @foreach($buckets as $b)
                <td style="height: {{ $chartHeight }}px; vertical-align: bottom; text-align: center; padding: 0 1px; border-bottom: 1px solid #999;">
                    <div style="font-size: 6px; color: #555;">{{ $b['occupancy'] > 0 ? $b['occupancy'] . '%' : '' }}</div>
                    <div style="height: {{ max(0, round($b['occupancy'] / 100 * ($chartHeight - 12))) }}px; background-color: #D6414B;"></div>
                </td>
            @endforeach
        </tr>
        <tr>
            @foreach($buckets as $b)
                <td style="font-size: 6px; text-align: center; color: #555; padding-top: 2px;">{{ $shortLabel($b) }}</td>
            @endforeach
        </tr>
    </table>

    <h2 class="section-title">Room Revenue by {{ $report['bucketUnit'] }}</h2>
    <table style="width: 100%; border-collapse: collapse; table-layout: fixed;">
        <tr>
            @foreach($buckets as $b)
                <td style="height: {{ $chartHeight }}px; vertical-align: bottom; text-align: center; padding: 0 1px; border-bottom: 1px solid #999;">
                    <div style="height: {{ max(0, round($b['revenue'] / $maxRevenue * ($chartHeight - 12))) }}px; background-color: #1B2A4A;"></div>
                </td>
            @endforeach
        </tr>
        <tr>
            @foreach($buckets as $b)
                <td style="font-size: 6px; text-align: center; color: #555; padding-top: 2px;">{{ $shortLabel($b) }}</td>
            @endforeach
        </tr>
    </table>
    <p style="font-size: 8px; color: #777; margin: 2px 0 0;">Tallest bar = {!! $peso($maxRevenue) !!}</p>

    <h2 class="section-title">Performance by {{ $report['bucketUnit'] }}</h2>
    <table class="report-table">
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
            @foreach($buckets as $b)
                <tr>
                    <td>{{ $b['label'] }}</td>
                    <td class="text-end">{{ number_format($b['available']) }}</td>
                    <td class="text-end">{{ number_format($b['occupied']) }}</td>
                    <td class="text-end">{{ $b['occupancy'] }}%</td>
                    <td class="text-end">{!! $peso($b['adr']) !!}</td>
                    <td class="text-end">{!! $peso($b['revpar']) !!}</td>
                    <td class="text-end">{!! $peso($b['revenue']) !!}</td>
                </tr>
            @endforeach
            <tr>
                <td><strong>Total / Average</strong></td>
                <td class="text-end"><strong>{{ number_format($s['room_nights_available']) }}</strong></td>
                <td class="text-end"><strong>{{ number_format($s['room_nights_sold']) }}</strong></td>
                <td class="text-end"><strong>{{ $s['occupancy'] }}%</strong></td>
                <td class="text-end"><strong>{!! $peso($s['adr']) !!}</strong></td>
                <td class="text-end"><strong>{!! $peso($s['revpar']) !!}</strong></td>
                <td class="text-end"><strong>{!! $peso($s['room_revenue']) !!}</strong></td>
            </tr>
        </tbody>
    </table>
@endif

<h2 class="section-title">Room Types</h2>
<table class="report-table">
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
                    <td class="text-end">{!! $peso($row['room_revenue']) !!}</td>
                    <td class="text-end">{!! $peso($row['adr']) !!}</td>
                @endif
            </tr>
        @empty
            <tr><td colspan="{{ $withRevenue ? 7 : 5 }}">No room types.</td></tr>
        @endforelse
    </tbody>
</table>

<h2 class="section-title">Guests</h2>
<table class="report-table">
    <thead><tr><th>Guest</th><th class="text-end">Stays</th><th class="text-end">Nights</th></tr></thead>
    <tbody>
        @forelse($report['guestRows'] as $row)
            <tr><td>{{ $row['name'] }}</td><td class="text-end">{{ $row['stays'] }}</td><td class="text-end">{{ $row['nights'] }}</td></tr>
        @empty
            <tr><td colspan="3">No guest stays in this period.</td></tr>
        @endforelse
    </tbody>
</table>

<h2 class="section-title">Cancellations and No-Shows</h2>
<table class="report-table">
    <thead><tr><th>Ref</th><th>Guest</th><th>Room Type</th><th>Check-in</th><th>Status</th><th>Reason</th><th>Marked By</th></tr></thead>
    <tbody>
        @forelse($report['cancellationRows'] as $row)
            <tr>
                <td>{{ $row['ref'] }}</td><td>{{ $row['guest'] }}</td><td>{{ $row['room_type'] }}</td>
                <td>{{ $row['check_in']->format('M d, Y') }}</td><td>{{ $row['status'] }}</td>
                <td>{{ $row['reason'] ?: '-' }}</td><td>{{ $row['by'] ?: '-' }}</td>
            </tr>
        @empty
            <tr><td colspan="7">No cancellations or no-shows in this period.</td></tr>
        @endforelse
    </tbody>
</table>
