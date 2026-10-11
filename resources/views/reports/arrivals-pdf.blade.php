<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    @include('reports.partials.pdf-styles')
</head>
<body>
    @include('reports.partials.pdf-header', [
        'reportTitle' => 'Daily Guest Arrival (DAE-1A)',
        'generatedAt' => $generatedAt,
        'periodLabel' => $arrivalDate->format('l, M d, Y'),
    ])

    <h2 class="section-title">Expected Arrivals &mdash; {{ count($arrivals) }}</h2>
    <table class="report-table">
        <thead>
            <tr><th>Ref</th><th>Guest</th><th>Room Type</th><th>Room</th><th class="text-end">Nights</th><th class="text-end">Guests</th><th>Status</th></tr>
        </thead>
        <tbody>
            @php
                $labels = ['ACTIVE_BOOKING' => 'Confirmed', 'AWAITING_VERIFICATION' => 'For Verification', 'CHECKED_IN' => 'Checked In',
                    'COMPLETED_BOOKING' => 'Checked Out', 'NO_SHOW_BOOKING' => 'No-Show',
                    'AWAITING_CASH_CONFIRMATION' => 'Awaiting Payment (Cash)', 'AWAITING_GCASH_PAYMENT' => 'Awaiting Payment (GCash)'];
            @endphp
            @forelse($arrivals as $row)
                <tr>
                    <td>{{ $row['ref'] }}</td>
                    <td>{{ $row['guest'] }}</td>
                    <td>{{ $row['room_type'] }}</td>
                    <td>{{ $row['rooms'] }}</td>
                    <td class="text-end">{{ $row['nights'] }}</td>
                    <td class="text-end">{{ $row['guests'] }}</td>
                    <td>{{ $labels[$row['display_status']] ?? $row['display_status'] }}</td>
                </tr>
            @empty
                <tr><td colspan="7">No arrivals expected on this day.</td></tr>
            @endforelse
        </tbody>
    </table>

    <p class="footer-note">Velocity Suites &middot; Confidential internal report &middot; Generated {{ $generatedAt->format('M d, Y h:i A') }}</p>
</body>
</html>
