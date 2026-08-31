<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <style>
        body { font-family: Helvetica, Arial, sans-serif; font-size: 12px; color: #1a1a1a; }
        h1 { color: #D6414B; font-size: 24px; margin-bottom: 2px; }
        .subtitle { color: #666; font-size: 14px; margin-bottom: 14px; }
        hr { border: none; border-top: 1px solid #f0c9cc; margin: 14px 0; }
        h2 { font-size: 12px; text-transform: uppercase; letter-spacing: 0.5px; margin-bottom: 6px; }
        p { margin: 3px 0; }
        table.summary { width: 100%; border-collapse: collapse; margin-top: 10px; }
        table.summary th { background-color: #f7d7d9; text-align: left; padding: 8px; font-size: 12px; }
        table.summary td { padding: 8px; font-size: 12px; }
        table.summary th:last-child, table.summary td:last-child { text-align: right; }
        .total-row td { border-top: 1px solid #333; font-weight: bold; font-size: 15px; color: #D6414B; padding-top: 10px; }
        .remaining { color: #666; font-size: 11px; }
        .footer { margin-top: 30px; color: #666; font-size: 9px; }
    </style>
</head>
<body>
    <h1>Velocity Suites</h1>
    <p class="subtitle">Official Payment Receipt</p>
    <hr>

    <h2>Booking Information</h2>
    <p>Reservation ID: #{{ $reservation->id }}</p>
    <p>
        Room: {{ $reservation->roomType->name }}
        @if($reservation->booking?->room) (Room {{ $reservation->booking->room->room_number }}) @endif
    </p>
    <p>Guests: {{ $reservation->number_of_guests }}</p>
    <p>Stay Period: {{ $reservation->check_in->format('M d, Y') }} to {{ $reservation->check_out->format('M d, Y') }}</p>

    <h2 style="margin-top: 16px;">Payment Details</h2>
    <?php $latestPayment = $reservation->payments->sortByDesc('created_at')->first(); ?>
    <p>Transaction Ref: {{ $latestPayment->reference_number ?? 'N/A' }}</p>
    <p>Payment Method: {{ $latestPayment ? ucfirst($latestPayment->payment_method) : ($reservation->payment_method ? ucfirst($reservation->payment_method) : 'N/A') }}</p>
    <p>Payment Date: {{ $latestPayment && $latestPayment->payment_date ? $latestPayment->payment_date->format('M d, Y') : 'N/A' }}</p>

    <?php
        $nights = abs($reservation->check_out->diffInDays($reservation->check_in));
        $baseAmount = $reservation->roomType->rate * $nights;
        $billing = $reservation->booking->billing ?? null;
        $totalAmount = $billing->total_amount ?? $baseAmount;
        $totalPaid = $reservation->payments->where('payment_status', 'completed')->sum('amount_paid');
        if ($billing) {
            $totalPaid += $billing->payments->where('payment_status', 'completed')->sum('amount_paid') ?? 0;
        }
        $remaining = max(0, $totalAmount - $totalPaid);
    ?>

    <table class="summary">
        <thead>
            <tr><th>Description</th><th>Amount</th></tr>
        </thead>
        <tbody>
            <tr><td>Accommodation Charges</td><td>&#8369;{{ number_format($totalAmount, 2) }}</td></tr>
            <tr class="total-row"><td>TOTAL PAID</td><td>&#8369;{{ number_format($totalPaid > 0 ? $totalPaid : $totalAmount, 2) }}</td></tr>
            @if($remaining > 0.009 && $totalPaid > 0)
                <tr><td colspan="2" class="remaining">Remaining Balance: &#8369;{{ number_format($remaining, 2) }}</td></tr>
            @endif
        </tbody>
    </table>

    <p class="footer">
        Thank you for choosing Velocity Suites. Your comfort is our service.<br>
        This is a computer-generated receipt and does not require a signature.
    </p>
</body>
</html>
