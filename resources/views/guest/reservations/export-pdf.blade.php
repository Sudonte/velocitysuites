<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <style>
        body { font-family: Helvetica, Arial, sans-serif; font-size: 11px; color: #1a1a1a; }
        h1 { color: #D6414B; font-size: 20px; margin-bottom: 2px; }
        .subtitle { color: #666; font-size: 11px; margin-bottom: 14px; }
        table { width: 100%; border-collapse: collapse; margin-top: 10px; }
        th { background-color: #D6414B; color: #fff; text-align: left; padding: 6px 8px; font-size: 10px; }
        td { padding: 6px 8px; border-bottom: 1px solid #eee; font-size: 10px; }
        tr:nth-child(even) td { background-color: #fafafa; }
        .text-end { text-align: right; }
    </style>
</head>
<body>
    <h1>Velocity Suites - Reservations</h1>
    <p class="subtitle">
        Generated on: {{ $generatedAt->format('M d, Y h:i A') }} &middot; Total Records: {{ $reservations->count() }}
    </p>

    <table>
        <thead>
            <tr>
                <th>ID</th>
                <th>Room Type</th>
                <th>Check-In</th>
                <th>Check-Out</th>
                <th>Status</th>
                <th class="text-end">Est. Total</th>
            </tr>
        </thead>
        <tbody>
            @forelse($reservations as $reservation)
                <?php
                    $nights = abs($reservation->check_out->diffInDays($reservation->check_in));
                    $total = $reservation->roomType->rate * $nights;
                    $status = $reservation->status === 'converted' && $reservation->booking
                        ? $reservation->booking->booking_status
                        : $reservation->status;
                ?>
                <tr>
                    <td>#{{ $reservation->id }}</td>
                    <td>{{ $reservation->roomType->name }}</td>
                    <td>{{ $reservation->check_in->format('M d, Y') }}</td>
                    <td>{{ $reservation->check_out->format('M d, Y') }}</td>
                    <td>{{ ucfirst($status) }}</td>
                    <td class="text-end">&#8369;{{ number_format($total, 2) }}</td>
                </tr>
            @empty
                <tr><td colspan="6">No reservations found.</td></tr>
            @endforelse
        </tbody>
    </table>
</body>
</html>
