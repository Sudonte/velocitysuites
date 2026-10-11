<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    @include('reports.partials.pdf-styles')
</head>
<body>
    @include('reports.partials.pdf-header', [
        'reportTitle' => 'Administrative Report',
        'generatedAt' => $generatedAt,
        'periodLabel' => $periodLabel,
    ])

    {{-- Only what the selected filters cover (period, room type, payment method). --}}
    <h2 class="section-title">Summary</h2>
    <table class="report-table">
        <thead>
            <tr><th>Metric</th><th>Value</th><th>What it counts</th></tr>
        </thead>
        <tbody>
            <tr>
                <td>Reservations</td>
                <td>{{ number_format($reservationsCount) }}</td>
                <td>Reservations created within {{ $periodLabel }}</td>
            </tr>
            <tr>
                <td>Bookings</td>
                <td>{{ number_format($bookingsCount) }}</td>
                <td>Direct (pay-first) bookings created within {{ $periodLabel }}</td>
            </tr>
            <tr>
                <td>Revenue</td>
                <td>&#8369;{{ number_format($revenue, 2) }}</td>
                <td>Completed payments (GCash once verified) within {{ $periodLabel }}</td>
            </tr>
        </tbody>
    </table>

    <p class="footer-note">Velocity Suites &middot; Confidential internal report &middot; Generated {{ $generatedAt->format('M d, Y h:i A') }}</p>
</body>
</html>
