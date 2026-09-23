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
        table.history { width: 100%; border-collapse: collapse; margin-top: 6px; }
        table.history th { background-color: #f7d7d9; text-align: left; padding: 6px; font-size: 10px; }
        table.history td { padding: 6px; font-size: 10px; border-bottom: 1px solid #eee; }
        table.history th:last-child, table.history td:last-child { text-align: right; }
        .badge-verified { color: #1a7f37; }
        .badge-pending { color: #9a6700; }
        .badge-rejected, .badge-failed { color: #b91c1c; }
    </style>
</head>
<body>
    <h1>Velocity Suites</h1>
    {{--
        Reflects the backend's own official_receipt_available rule
        (billing_status=paid AND booking_status=COMPLETED_BOOKING) -
        never assumed. A 20-50% deposit or a verified 100% pre-checkout
        payment must never print as "Official Payment Receipt" - see
        PAYMENT_RECEIPT_HISTORY_BACKEND_SPEC.md §7.
    --}}
    <p class="subtitle">{{ $paymentSummary['official_receipt_available'] ? 'Official Payment Receipt' : 'Payment Receipt' }}</p>
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

    {{--
        Grand Total / Total Amount Paid / Remaining Balance come from
        $paymentSummary (Reservation::paymentSummary(), ReceiptService-
        backed) - not a second, independent calculation in this template.
        Previously this block fell back to showing the full Grand Total
        as "TOTAL PAID" whenever nothing had actually been paid yet
        ($totalPaid > 0 ? $totalPaid : $totalAmount) - a real "shows an
        incorrect total" bug (Total Amount Paid must only reflect
        completed payments, never the Grand Total itself). Fixed by
        always showing the real total_amount_paid, even when it's ₱0.
    --}}
    <table class="summary">
        <thead>
            <tr><th>Description</th><th>Amount</th></tr>
        </thead>
        <tbody>
            <tr><td>Accommodation Charges</td><td>&#8369;{{ number_format($paymentSummary['grand_total'], 2) }}</td></tr>
            <tr class="total-row"><td>TOTAL PAID</td><td>&#8369;{{ number_format($paymentSummary['total_amount_paid'], 2) }}</td></tr>
            @if($paymentSummary['remaining_balance'] > 0.009)
                <tr><td colspan="2" class="remaining">Remaining Balance: &#8369;{{ number_format($paymentSummary['remaining_balance'], 2) }}</td></tr>
            @endif
        </tbody>
    </table>

    @if(!empty($paymentTransactions))
        <h2 style="margin-top: 16px;">Payment Transaction History</h2>
        <table class="history">
            <thead>
                <tr><th>Date</th><th>Method</th><th>Type</th><th>Status</th><th>Amount</th></tr>
            </thead>
            <tbody>
                @foreach($paymentTransactions as $tx)
                    @php
                        $statusLabel = $tx['verification_status']
                            ? ucfirst(str_replace('_', ' ', $tx['verification_status']))
                            : ucfirst($tx['payment_status']);
                        $statusClass = 'badge-' . ($tx['verification_status'] ?? $tx['payment_status']);
                    @endphp
                    <tr>
                        <td>{{ $tx['payment_date'] ? \Carbon\Carbon::parse($tx['payment_date'])->format('M d, Y') : 'N/A' }}</td>
                        <td>{{ ucfirst($tx['payment_method']) }}</td>
                        <td>{{ ucwords(str_replace('_', ' ', $tx['transaction_type'])) }}</td>
                        <td class="{{ $statusClass }}">{{ $statusLabel }}</td>
                        <td>&#8369;{{ number_format($tx['amount_paid'], 2) }}</td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    @endif

    <p class="footer">
        Thank you for choosing Velocity Suites. Your comfort is our service.<br>
        This is a computer-generated receipt and does not require a signature.
    </p>
</body>
</html>
