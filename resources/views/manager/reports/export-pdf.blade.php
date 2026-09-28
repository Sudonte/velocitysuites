<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    @include('reports.partials.pdf-styles')
</head>
<body>
    @include('reports.partials.pdf-header', [
        'reportTitle' => 'Manager Report',
        'generatedAt' => $generatedAt,
        'periodLabel' => $periodLabel,
    ])

    <h2 class="section-title">Executive Summary</h2>
    <table class="report-table">
        <thead>
            <tr><th>Metric</th><th>Value</th><th>Context / Benchmarks</th></tr>
        </thead>
        <tbody>
            <tr>
                <td>Period Revenue</td>
                <td>&#8369;{{ number_format($totalRevenue, 2) }}</td>
                <td>From completed payments, {{ $periodLabel }}</td>
            </tr>
            <tr>
                <td>Total Reservations</td>
                <td>{{ number_format($totalReservations) }}</td>
                <td>Check-ins scheduled within {{ $periodLabel }}</td>
            </tr>
            <tr>
                <td>Total Bookings</td>
                <td>{{ number_format($totalBookings) }}</td>
                <td>Reservations converted into a booking</td>
            </tr>
            <tr>
                <td>Average Length of Stay</td>
                <td>{{ number_format($averageStay, 1) }} nights</td>
                <td>Average across reservations in {{ $periodLabel }}</td>
            </tr>
            <tr>
                <td>Occupancy Rate</td>
                <td>{{ number_format($managerStats['occupancyRate'], 1) }}%</td>
                <td>Rooms currently occupied vs. total inventory (live snapshot)</td>
            </tr>
            <tr>
                <td>Cancellation Rate</td>
                <td class="{{ $managerStats['cancellationRate'] > 15 ? 'rate-high' : '' }}">{{ number_format($managerStats['cancellationRate'], 1) }}%</td>
                <td>Cancelled reservations in {{ $periodLabel }}</td>
            </tr>
            <tr>
                <td>No-Show Rate</td>
                <td class="{{ $managerStats['noShowRate'] > 15 ? 'rate-high' : '' }}">{{ number_format($managerStats['noShowRate'], 1) }}%</td>
                <td>Confirmed bookings never arrived in {{ $periodLabel }}</td>
            </tr>
        </tbody>
    </table>

    <h2 class="section-title">Reservations &mdash; Last 6 Months</h2>
    <table class="report-table">
        <thead>
            <tr>
                <th>Month</th>
                <th class="text-end">Gross Reservations</th>
                <th class="text-end">Cancellations</th>
                <th class="text-end">Net Reservations</th>
                <th class="text-end">Cancel Rate</th>
            </tr>
        </thead>
        <tbody>
            @foreach($monthlyBreakdown as $row)
                <tr>
                    <td>{{ $row['label'] }}</td>
                    <td class="text-end">{{ number_format($row['gross']) }}</td>
                    <td class="text-end">{{ number_format($row['cancelled']) }}</td>
                    <td class="text-end">{{ number_format($row['net']) }}</td>
                    <td class="text-end {{ $row['cancelRate'] > 15 ? 'rate-high' : '' }}">{{ number_format($row['cancelRate'], 1) }}%</td>
                </tr>
            @endforeach
        </tbody>
    </table>

    <h2 class="section-title">Top Room Types</h2>
    <table class="report-table">
        <thead><tr><th>Room Type</th><th class="text-end">Reservations</th></tr></thead>
        <tbody>
            @forelse($topRoomTypes as $roomType)
                <tr><td>{{ $roomType->name }}</td><td class="text-end">{{ number_format($roomType->reservations_count) }}</td></tr>
            @empty
                <tr><td colspan="2">No data for this period.</td></tr>
            @endforelse
        </tbody>
    </table>

    <h2 class="section-title">Top Guests</h2>
    <table class="report-table">
        <thead><tr><th>Guest</th><th class="text-end">Reservations</th></tr></thead>
        <tbody>
            @forelse($topGuests as $row)
                <tr><td>{{ $row->guest->user->full_name ?? 'N/A' }}</td><td class="text-end">{{ number_format($row->reservation_count) }}</td></tr>
            @empty
                <tr><td colspan="2">No data for this period.</td></tr>
            @endforelse
        </tbody>
    </table>

    <p class="footer-note">Velocity Suites &middot; Confidential internal report &middot; Generated {{ $generatedAt->format('M d, Y h:i A') }}</p>
</body>
</html>
