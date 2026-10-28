{{-- The 3rd receipt's balance breakdown. Rendered from StayBill's `receipt_breakdown` and nothing else - the guest app
     prints the very same payload, so both sides always show identical figures.
     Expects: $breakdown (StayBill['receipt_breakdown']), $nights (scheduled nights, optional). --}}
@php
    $c = $breakdown['checkin'];
    $a = $breakdown['after_checkin'];
    $peso = fn ($v) => '₱'.number_format((float) $v, 2);
    $items = array_merge($a['amenities'], $a['charges']);
    $nights = $nights ?? null;
@endphp
<table class="table table-sm table-borderless align-middle mb-0" id="receiptBreakdownTable">
    <colgroup><col><col style="width: 9rem;"></colgroup>
    <tr class="border-bottom"><th colspan="2" class="text-uppercase small text-muted">Check-in summary <span class="fw-normal">({{ $c['paid_in_full'] ? 'fully paid' : 'not fully paid' }})</span></th></tr>
    <tr>
        <td>Room rate × {{ $nights ? \App\Support\StayBill::nightsLabel((int) $nights) : 'nights' }}</td>
        <td class="text-end">{{ $peso($c['room_charge']) }}</td>
    </tr>
    @if($c['booked_amenities'] > 0)
        <tr>
            <td>Amenities booked</td>
            <td class="text-end">{{ $peso($c['booked_amenities']) }}</td>
        </tr>
    @endif
    @if(($c['promo'] ?? 0) > 0)
        <tr>
            <td>Promo applied @if($c['promo_name'])<span class="text-muted">({{ $c['promo_name'] }})</span>@endif</td>
            <td class="text-end text-success">-{{ $peso($c['promo']) }}</td>
        </tr>
    @endif
    @if($c['discount'] > 0)
        <tr>
            <td>Discount @if($c['discount_name'])<span class="text-muted">({{ $c['discount_name'] }}@if($c['discount_percent'] !== null), {{ rtrim(rtrim(number_format($c['discount_percent'], 2), '0'), '.') }}%@endif)</span>@endif</td>
            <td class="text-end text-success">-{{ $peso($c['discount']) }}</td>
        </tr>
    @endif
    <tr class="fw-semibold border-top">
        <td>Check-in Grand Total</td>
        <td class="text-end">{{ $peso($c['grand_total']) }}</td>
    </tr>
    <tr>
        <td>Amount Paid at Check-in</td>
        <td class="text-end">-{{ $peso($c['amount_paid']) }}</td>
    </tr>
    <tr>
        <td>Status</td>
        <td class="text-end"><span class="badge {{ $c['paid_in_full'] ? 'bg-success' : 'bg-warning text-dark' }}">{{ $c['status'] }}</span></td>
    </tr>

    <tr class="border-bottom"><th colspan="2" class="text-uppercase small text-muted pt-3">Additional charges</th></tr>
    @forelse($items as $line)
        <tr>
            <td>{{ $line['name'] }} <span class="text-muted">× {{ $line['quantity'] }} @ {{ $peso($line['unit_price']) }}</span></td>
            <td class="text-end">{{ $peso($line['subtotal']) }}</td>
        </tr>
    @empty
        <tr><td colspan="2" class="text-muted">No additional charges</td></tr>
    @endforelse
    <tr class="small text-muted">
        <td class="ps-3">Amenity requests</td>
        <td class="text-end">{{ $peso($a['amenities_total']) }}</td>
    </tr>
    <tr class="small text-muted">
        <td class="ps-3">Additional charges</td>
        <td class="text-end">{{ $peso($a['charges_total']) }}</td>
    </tr>
    <tr class="fw-semibold border-top">
        <td>Subtotal of Additional</td>
        <td class="text-end">{{ $peso($a['subtotal']) }}</td>
    </tr>

    <tr class="fw-bold">
        <td class="pt-3">REMAINING BALANCE</td>
        <td class="text-end pt-3">{{ $peso($breakdown['remaining_balance']) }}</td>
    </tr>

    <tr class="fw-bold fs-5 border-top" style="background: rgba(139, 0, 0, .08);">
        <td>FINAL GRAND TOTAL</td>
        <td class="text-end text-brand" id="finalGrandTotalDisplay">{{ $peso($breakdown['final_grand_total']) }}</td>
    </tr>
    <tr>
        <td>Total Paid</td>
        <td class="text-end">-{{ $peso($breakdown['total_paid']) }}</td>
    </tr>
    <tr class="fw-bold fs-5">
        <td>BALANCE DUE</td>
        <td class="text-end" id="breakdownBalanceDue">{{ $peso($breakdown['balance_due']) }}</td>
    </tr>
    <tr>
        <td colspan="2" class="small text-muted pt-2"><i class="fas fa-info-circle"></i> {{ \App\Support\StayBill::NO_REFUND_NOTE }}</td>
    </tr>
</table>
