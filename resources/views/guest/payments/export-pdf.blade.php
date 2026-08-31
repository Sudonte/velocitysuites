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
    <h1>Velocity Suites - Payment History</h1>
    <p class="subtitle">
        Generated on: {{ $generatedAt->format('M d, Y h:i A') }} &middot; Total Records: {{ $payments->count() }}
    </p>

    <table>
        <thead>
            <tr>
                <th>Date</th>
                <th>Bill #</th>
                <th>Room</th>
                <th>Method</th>
                <th>Reference</th>
                <th class="text-end">Amount</th>
                <th>Status</th>
            </tr>
        </thead>
        <tbody>
            @forelse($payments as $payment)
                <?php
                    $room = $payment->billing
                        ? ($payment->billing->booking->room->room_number ?? $payment->billing->booking->roomType->name)
                        : ($payment->reservation ?? $payment->booking)?->roomType->name;
                ?>
                <tr>
                    <td>{{ ($payment->payment_date ?? $payment->created_at)->format('M d, Y h:i A') }}</td>
                    <td>{{ $payment->billing_id ? '#' . $payment->billing_id : ($payment->reservation_id ? 'Deposit' : 'Booking Payment') }}</td>
                    <td>{{ $room }}</td>
                    <td>{{ ucfirst($payment->payment_method) }}</td>
                    <td>{{ $payment->reference_number ?? '-' }}</td>
                    <td class="text-end">&#8369;{{ number_format($payment->amount_paid, 2) }}</td>
                    <td>{{ ucfirst($payment->payment_status) }}</td>
                </tr>
            @empty
                <tr><td colspan="7">No payments found.</td></tr>
            @endforelse
        </tbody>
    </table>
</body>
</html>
