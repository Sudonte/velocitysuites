{{--
    The one receipt document: the preview, the browser's Print and the PDF all draw THIS, from the payload of GET /api/guest/receipts/{number}
    (ReceiptService::findReceiptPayload), so they can never differ from each other or from the app. Tables only (no flex/grid), so Dompdf and
    the browser lay it out the same way. $receipt = the payload; $mode = 'pdf' | 'print'.
--}}
@php
    $mode = $mode ?? 'print';
    $peso = fn ($v) => '&#8369;'.number_format((float) $v, 2);
    $rc = $receipt; $h = $rc['highlight'] ?? []; $stage = (int) ($rc['receipt_stage'] ?? 0);
    $titles = [1 => 'Payment verified', 2 => 'Check-in', 3 => 'Check-out (final)'];
    $ps = $rc['payment_summary'] ?? [];
    $total = (float) ($h['grand_total'] ?? $h['total_amount'] ?? $ps['grand_total'] ?? 0);
    $paid = (float) ($h['total_paid'] ?? $ps['total_amount_paid'] ?? 0);
    $remaining = (float) ($h['remaining_balance'] ?? $ps['remaining_balance'] ?? 0);
    $settled = $remaining <= 0.009;
    $pay = $stage === 3 ? ($settled ? $peso(0).' &middot; Fully paid' : $peso($remaining).' &middot; still due')
        : ($settled ? $peso(0).' &middot; Nothing to pay' : $peso($remaining).' &middot; '.($stage === 2 ? 'at check-out' : 'at check-in'));
    $status = $stage === 3 ? ($settled ? 'Paid' : 'Balance due') : 'Issued';
    $row = fn ($l, $v, $cls = '') => '<tr'.($cls ? ' class="'.$cls.'"' : '').'><td class="l">'.$l.'</td><td class="r">'.$v.'</td></tr>';
    $date = fn ($d) => \Carbon\Carbon::parse($d)->format('M j, Y');
    $nights = fn ($n) => $n.' '.($n == 1 ? 'night' : 'nights');
    $method = fn ($m) => stripos((string) $m, 'gcash') !== false ? 'GCash' : 'Cash';
    $logo = '';
    if (is_file(public_path('images/logo.jpg'))) {
        $logo = $mode === 'pdf' ? 'data:image/jpeg;base64,'.base64_encode(file_get_contents(public_path('images/logo.jpg'))) : asset('images/logo.jpg');
    }
    $fileName = 'VelocitySuites-Receipt'.$stage.'-'.$rc['receipt_number'];
@endphp
<style>
    .rcpt-doc { font-family: {{ $mode === 'pdf' ? "'DejaVu Sans', Helvetica, Arial, sans-serif" : "system-ui, -apple-system, 'Segoe UI', Roboto, Arial, sans-serif" }}; font-size: {{ $mode === 'pdf' ? '10px' : '12px' }}; color: #000; background: #fff; line-height: 1.4; }
    .rcpt-doc table { width: 100%; border-collapse: collapse; table-layout: fixed; }
    .rcpt-doc td { padding: 3px 0; vertical-align: top; word-wrap: break-word; overflow-wrap: anywhere; }
    .rcpt-doc td.l { width: 68%; padding-right: 10px; }
    .rcpt-doc td.r { width: 32%; text-align: right; font-variant-numeric: tabular-nums; }
    .rcpt-doc tr { page-break-inside: avoid; break-inside: avoid; }
    .rcpt-doc tr.strong td { font-weight: bold; border-top: 1px solid #000; padding-top: 5px; }
    .rcpt-doc tr.sub td.l { padding-left: 14px; }
    .rcpt-doc .muted { color: #333; font-size: 0.88em; }
    .rcpt-doc .sec { margin-top: 12px; page-break-inside: auto; }
    .rcpt-doc h2 { font-size: 1.1em; margin: 0 0 4px; padding-bottom: 2px; border-bottom: 1px solid #000; page-break-after: avoid; break-after: avoid; color: #000; }
    .rcpt-doc .stay { margin-top: 6px; page-break-inside: avoid; break-inside: avoid; }
    .rcpt-doc .stay-h { font-weight: bold; page-break-after: avoid; break-after: avoid; }
    .rcpt-doc .head td { vertical-align: middle; padding: 0; }
    .rcpt-doc .head img { width: 48px; height: 42px; }
    .rcpt-doc .brand { font-size: 1.9em; font-weight: bold; margin: 0; line-height: 1.1; }
    .rcpt-doc .title { font-size: 1.25em; font-weight: bold; text-align: right; }
    .rcpt-doc .rule { border: 0; border-top: 2px solid #000; margin: 6px 0 8px; }
    .rcpt-doc .box { border: 1px solid #000; padding: 6px 9px; margin-top: 12px; page-break-inside: avoid; break-inside: avoid; }
    .rcpt-doc .pay { font-size: 1.2em; font-weight: bold; }
    .rcpt-doc .note { margin-top: 6px; font-size: 0.9em; }
    @page { size: auto; margin: 14mm 14mm 16mm 14mm; @bottom-center { content: "Page " counter(page) " of " counter(pages); font-size: 9pt; color: #000; } }
    @if($mode === 'pdf')
    @page { margin: 14mm 14mm 18mm 14mm; }
    @endif
</style>
<div class="rcpt-doc" data-file="{{ $fileName }}">
    <table class="head"><tr>
        <td style="width:62%"><table><tr><td style="width:56px">@if($logo)<img src="{{ $logo }}" alt="">@endif</td><td><p class="brand">Velocity Suites</p><div class="muted">Your comfort is our priority</div></td></tr></table></td>
        <td style="width:38%"><div class="title">Receipt {{ $stage }} of 3<br>{{ $titles[$stage] ?? 'Receipt' }}</div><div class="muted" style="text-align:right">{{ $rc['receipt_number'] }}</div></td>
    </tr></table>
    <hr class="rule">
    <table>
        {!! $row('Receipt number', e($rc['receipt_number'])) !!}
        {!! $row('Transaction', ($rc['reservation_id'] ?? null) ? 'Reservation #'.$rc['reservation_id'] : 'Booking #'.$rc['booking_id']) !!}
        {!! $row('Date issued', e(\Carbon\Carbon::parse($rc['issued_at'])->timezone('Asia/Manila')->format('M j, Y g:i A'))) !!}
        {!! $row('Status', $status) !!}
    </table>

    @if(!empty($rc['stays']))
    <div class="sec"><h2>Your stay</h2>
        @foreach($rc['stays'] as $s)
            <div class="stay"><div class="stay-h">{{ $date($s['check_in']) }} &rarr; {{ $date($s['check_out']) }} &middot; {{ $nights($s['nights']) }}</div>
                <table>
                    @foreach(($s['rooms'] ?? []) as $r)
                        {!! $row(e($r['room_type']).' &times; '.$r['quantity'].' <span class="muted">('.$peso($r['price_per_night']).' &times; '.$nights($r['nights']).')</span>'.(!empty($r['assigned_room_numbers']) ? '<div class="muted">Room '.e(implode(', ', $r['assigned_room_numbers'])).'</div>' : ''), $peso($r['subtotal'])) !!}
                    @endforeach
                    @foreach(($s['amenities'] ?? []) as $a)
                        {!! $row('<span class="muted">'.e($a['amenity_name']).' &times; '.$a['quantity'].(!empty($a['room_label']) ? ' ('.e($a['room_label']).')' : '').'</span>', $peso($a['subtotal']), 'sub') !!}
                    @endforeach
                </table>
            </div>
        @endforeach
    </div>
    @endif

    <div class="sec"><h2>Guests</h2>
        <table>
            {!! $row('Account holder', e($rc['guest_account_name'] ?? '')) !!}
            {!! $row('Representative', e($rc['representative_name'] ?? '')) !!}
            {!! $row('Adults', (int) ($rc['adults'] ?? 0)) !!}
            {!! $row('Minors', (int) ($rc['children'] ?? 0)) !!}
            @if(!empty($rc['discount_info']['id_type'])){!! $row('ID / discount', e($rc['discount_info']['id_type']).(($rc['discount_info']['status'] ?? '') === 'approved' ? ' &middot; applied' : '')) !!}@endif
        </table>
    </div>

    <div class="sec"><h2>{{ $stage === 3 ? 'Bill' : ($stage === 2 ? 'Check-in payment' : 'Payment verified') }}</h2>
        @if($stage === 3)
            @foreach(($h['stays'] ?? []) as $s)
                <div class="stay"><div class="stay-h">{{ $date($s['check_in']) }} &rarr; {{ $date($s['check_out']) }}</div>
                    <table>
                        @foreach(($s['rooms'] ?? []) as $r)
                            @php $promoRow = (float) ($r['promo_off'] ?? 0) > 0.009; @endphp
                            {!! $row('Room '.e($r['room_number'] ?? '').' &middot; '.e($r['room_type']).' <span class="muted">('.($promoRow ? $peso($r['rate']).' &rarr; '.$peso($r['rate_after_promo']).' per night (Promo)' : $peso($r['rate']).' &times; '.$nights($r['nights'])).')</span>', $peso($r['subtotal'])) !!}
                        @endforeach
                        @foreach(($s['amenities'] ?? []) as $a)
                            {!! $row('<span class="muted">'.e($a['name']).' &times; '.$a['quantity'].(!empty($a['room']) ? ' ('.e($a['room']).')' : '').'</span>', $peso($a['subtotal']), 'sub') !!}
                        @endforeach
                    </table>
                </div>
            @endforeach
            <table style="margin-top:6px">
                {!! $row('Room charges', $peso($h['room_charges'] ?? 0)) !!}
                @if(($h['promo'] ?? 0) > 0.009){!! $row('Promo applied'.(!empty($h['promo_name']) ? ': '.e($h['promo_name']) : ''), '&minus;'.$peso($h['promo'])) !!}@endif
                {!! $row('Amenities', $peso($h['amenities_total'] ?? 0)) !!}
                @if(($h['discount'] ?? 0) > 0.009){!! $row('Discount'.(!empty($h['discount_name']) ? ' ('.e($h['discount_name']).')' : '').' <span class="muted">on rooms and amenities</span>', '&minus;'.$peso($h['discount'])) !!}@endif
                @foreach(($h['other_charges'] ?? []) as $c){!! $row('Other charges: '.e($c['name']), $peso($c['amount'])) !!}@endforeach
                {!! $row('Grand total', $peso($h['grand_total'] ?? 0), 'strong') !!}
                @foreach(($h['payments'] ?? []) as $p){!! $row(e($p['label']).(!empty($p['method']) ? ' &middot; '.$method($p['method']) : ''), $peso($p['amount'])) !!}@endforeach
                {!! $row('Paid so far', $peso($h['total_paid'] ?? 0)) !!}
            </table>
        @else
            <table>
                @if($stage === 2)
                    @if(($h['amount_paid_now'] ?? 0) > 0.009){!! $row('Paid at check-in', $peso($h['amount_paid_now'])) !!}@if(!empty($h['payment_method'])){!! $row('Payment method', $method($h['payment_method'])) !!}@endif
                    @else<tr><td colspan="2"><strong>No payment at check-in</strong></td></tr>@endif
                @else
                    {!! $row('Amount paid', $peso($h['amount_paid_now'] ?? 0)) !!}
                    @if(!empty($h['payment_method'])){!! $row('Payment method', $method($h['payment_method'])) !!}@endif
                    @if(($h['previous_payments'] ?? 0) > 0.009){!! $row('Earlier payments', $peso($h['previous_payments'])) !!}@endif
                @endif
                @if(($h['promo'] ?? 0) > 0.009){!! $row('Promo applied'.(!empty($h['promo_name']) ? ': '.e($h['promo_name']) : ''), '&minus;'.$peso($h['promo'])) !!}@endif
                @if(($h['discount'] ?? 0) > 0.009){!! $row('Discount'.(!empty($h['discount_name']) ? ' ('.e($h['discount_name']).')' : '').' <span class="muted">on rooms and amenities</span>', '&minus;'.$peso($h['discount'])) !!}@endif
                {!! $row('Grand total', $peso($h['total_amount'] ?? $total), 'strong') !!}
                {!! $row('Paid so far', $peso($paid)) !!}
            </table>
        @endif
    </div>

    @if($stage === 3 && !empty($rc['transaction_history']))
    <div class="sec"><h2>Transaction history</h2>
        <table>
            @foreach($rc['transaction_history'] as $e)
                {!! $row(e($e['label'] ?? $e['title'] ?? ''), e(\Carbon\Carbon::parse($e['at'] ?? $e['date'] ?? $e['time'] ?? now())->timezone('Asia/Manila')->format('M j, Y g:i A'))) !!}
            @endforeach
        </table>
    </div>
    @endif

    <div class="box">
        <table>
            {!! $row('Grand total', $peso($total)) !!}
            {!! $row('Remaining balance', $peso($remaining)) !!}
            <tr><td class="l"><strong>Amount to pay</strong></td><td class="r pay">{!! $pay !!}</td></tr>
        </table>
    </div>
    @if(!empty($h['no_refund_note']))<div class="note">{{ $h['no_refund_note'] }}</div>@endif
</div>
