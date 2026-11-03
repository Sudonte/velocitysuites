@php
    $peso = fn ($v) => '&#8369;'.number_format((float) $v, 2);
    $rc = $receipt; $h = $rc['highlight'] ?? []; $stage = $rc['receipt_stage'] ?? 0;
    $titles = [1 => 'Payment verified', 2 => 'Check-in', 3 => 'Check-out (final)'];
    $ps = $rc['payment_summary'] ?? [];
    $total = (float) ($h['grand_total'] ?? $h['total_amount'] ?? $ps['grand_total'] ?? 0);
    $paid = (float) ($h['total_paid'] ?? $ps['total_amount_paid'] ?? 0);
    $remaining = (float) ($h['remaining_balance'] ?? $ps['remaining_balance'] ?? 0);
    $settled = $remaining <= 0.009;
    $pay = $stage === 3 ? ($settled ? $peso(0).' &middot; Fully paid' : $peso($remaining).' &middot; still due') : ($settled ? $peso(0).' &middot; Nothing to pay' : $peso($remaining).' &middot; '.($stage === 2 ? 'at check-out' : 'at check-in'));
    $row = fn ($l, $v, $strong = false) => '<tr'.($strong ? ' class="strong"' : '').'><td>'.$l.'</td><td class="r">'.$v.'</td></tr>';
@endphp
<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <style>
        body { font-family: DejaVu Sans, Helvetica, Arial, sans-serif; font-size: 11px; color: #1a1a1a; }
        h1 { color: #D6414B; font-size: 22px; margin: 0; }
        .sub { color: #666; margin-bottom: 10px; }
        h2 { color: #D6414B; font-size: 12px; margin: 14px 0 4px; }
        table { width: 100%; border-collapse: collapse; }
        td { padding: 3px 0; vertical-align: top; }
        td.r { text-align: right; white-space: nowrap; }
        tr.strong td { font-weight: bold; border-top: 1px solid #ddd; padding-top: 5px; }
        .muted { color: #666; font-size: 10px; }
        .box { background: #fdecec; padding: 8px 10px; margin-top: 14px; }
        .pay { font-size: 14px; font-weight: bold; color: {{ $settled ? '#1b5e20' : '#A62B34' }}; }
    </style>
</head>
<body>
    <h1>Velocity Suites</h1>
    <div class="sub">Your comfort is our priority</div>
    <strong>Receipt {{ $stage }} of 3 &middot; {{ $titles[$stage] ?? 'Receipt' }}</strong>
    <table style="margin-top:6px">
        {!! $row('Receipt number', e($rc['receipt_number'])) !!}
        {!! $row('Transaction', ($rc['reservation_id'] ?? null) ? 'Reservation #'.$rc['reservation_id'] : 'Booking #'.$rc['booking_id']) !!}
        {!! $row('Date issued', e(\Carbon\Carbon::parse($rc['issued_at'])->timezone('Asia/Manila')->format('M j, Y g:i A'))) !!}
    </table>

    <h2>Your stay</h2>
    @foreach(($rc['stays'] ?? []) as $s)
        <strong>{{ \Carbon\Carbon::parse($s['check_in'])->format('M j, Y') }} &rarr; {{ \Carbon\Carbon::parse($s['check_out'])->format('M j, Y') }} &middot; {{ $s['nights'] }} {{ $s['nights'] == 1 ? 'night' : 'nights' }}</strong>
        <table>
            @foreach(($s['rooms'] ?? []) as $r)
                {!! $row(e($r['room_type']).' &times; '.$r['quantity'].' <span class="muted">('.$peso($r['price_per_night']).' &times; '.$r['nights'].' '.($r['nights'] == 1 ? 'night' : 'nights').')</span>', $peso($r['subtotal'])) !!}
            @endforeach
            @foreach(($s['amenities'] ?? []) as $a)
                {!! $row('<span class="muted">'.e($a['amenity_name']).' &times; '.$a['quantity'].(!empty($a['room_label']) ? ' ('.e($a['room_label']).')' : '').'</span>', $peso($a['subtotal'])) !!}
            @endforeach
        </table>
    @endforeach

    <h2>Guests</h2>
    <table>
        {!! $row('Account holder', e($rc['guest_account_name'] ?? '')) !!}
        {!! $row('Representative', e($rc['representative_name'] ?? '')) !!}
        {!! $row('Adults', (int) ($rc['adults'] ?? 0)) !!}
        {!! $row('Minors', (int) ($rc['children'] ?? 0)) !!}
    </table>

    <h2>{{ $stage === 3 ? 'Bill' : ($stage === 2 ? 'Check-in payment' : 'Payment verified') }}</h2>
    <table>
        @if($stage === 3)
            {!! $row('Room charges', $peso($h['room_charges'] ?? 0)) !!}
            @if(($h['promo'] ?? 0) > 0.009){!! $row('Promo applied'.(!empty($h['promo_name']) ? ': '.e($h['promo_name']) : ''), '&minus;'.$peso($h['promo'])) !!}@endif
            {!! $row('Amenities', $peso($h['amenities_total'] ?? 0)) !!}
            @if(($h['discount'] ?? 0) > 0.009){!! $row('Discount'.(!empty($h['discount_name']) ? ' ('.e($h['discount_name']).')' : '').' <span class="muted">on rooms and amenities</span>', '&minus;'.$peso($h['discount'])) !!}@endif
            @foreach(($h['other_charges'] ?? []) as $c){!! $row('Other charges: '.e($c['name']), $peso($c['amount'])) !!}@endforeach
            {!! $row('Grand total', $peso($h['grand_total'] ?? 0), true) !!}
            @foreach(($h['payments'] ?? []) as $p){!! $row(e($p['label']).(!empty($p['method']) ? ' &middot; '.(stripos($p['method'], 'gcash') !== false ? 'GCash' : 'Cash') : ''), $peso($p['amount'])) !!}@endforeach
            {!! $row('Paid so far', $peso($h['total_paid'] ?? 0)) !!}
        @else
            @if(($h['amount_paid_now'] ?? 0) > 0.009){!! $row($stage === 2 ? 'Paid at check-in' : 'Amount paid', $peso($h['amount_paid_now'])) !!}@elseif($stage === 2)<tr><td colspan="2"><strong>No payment at check-in</strong></td></tr>@endif
            @if(($h['promo'] ?? 0) > 0.009){!! $row('Promo applied'.(!empty($h['promo_name']) ? ': '.e($h['promo_name']) : ''), '&minus;'.$peso($h['promo'])) !!}@endif
            @if(($h['discount'] ?? 0) > 0.009){!! $row('Discount'.(!empty($h['discount_name']) ? ' ('.e($h['discount_name']).')' : '').' <span class="muted">on rooms and amenities</span>', '&minus;'.$peso($h['discount'])) !!}@endif
            {!! $row('Grand total', $peso($h['total_amount'] ?? $total), true) !!}
            {!! $row('Paid so far', $peso($paid)) !!}
        @endif
    </table>

    <div class="box">
        <table>
            {!! $row('Grand total', $peso($total)) !!}
            {!! $row('Remaining balance', $peso($remaining)) !!}
            <tr><td><strong>Amount to pay</strong></td><td class="r pay">{!! $pay !!}</td></tr>
        </table>
    </div>
    @if(!empty($h['no_refund_note']))<p class="muted">{{ $h['no_refund_note'] }}</p>@endif
</body>
</html>
