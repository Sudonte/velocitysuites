{{--
    The guest's itemized stay bill - the same App\Support\StayBill the mobile
    app shows (rooms x nights, amenities, extra charges, discount, paid,
    balance). @param array $stay StayBill::forBooking() output
--}}
@php $peso = fn ($v) => '₱' . number_format((float) $v, 2); @endphp
<x-card title="Your Stay Bill" icon="fas fa-file-invoice" bodyClass="card-body" class="mb-4">
    @foreach($stay['rooms'] as $line)
        <div class="d-flex justify-content-between mb-1">
            <span>
                {{ $line['room_type'] ?? 'Room' }}@if($line['room_number']) &middot; Room {{ $line['room_number'] }}@endif
                <small class="text-muted d-block">{{ $peso($line['rate']) }} &times; {{ \App\Support\StayBill::nightsLabel($line['nights']) }}</small>
            </span>
            <strong>{{ $peso($line['subtotal']) }}</strong>
        </div>
    @endforeach
    @foreach($stay['amenity_lines'] as $a)
        <div class="d-flex justify-content-between mb-1">
            <span>{{ $a['amenity_name'] }} &times; {{ $a['quantity'] }}@if($a['additional']) <small class="text-muted">(requested)</small>@endif</span>
            <span>{{ $peso($a['subtotal']) }}</span>
        </div>
    @endforeach
    @foreach($stay['additional_charges'] as $c)
        <div class="d-flex justify-content-between mb-1">
            <span>{{ $c['description'] }}</span>
            <span>{{ $peso($c['amount']) }}</span>
        </div>
    @endforeach
    @if($stay['discount'] > 0)
        <div class="d-flex justify-content-between mb-1 text-success">
            <span>Discount{{ $stay['discount_name'] ? ' (' . $stay['discount_name'] . ')' : '' }}</span>
            <span>&minus;{{ $peso($stay['discount']) }}</span>
        </div>
    @endif
    <hr class="my-2">
    <div class="d-flex justify-content-between"><strong>Total</strong><strong class="text-brand">{{ $peso($stay['total']) }}</strong></div>
    <div class="d-flex justify-content-between"><span>Paid</span><span>{{ $peso($stay['total_paid']) }}</span></div>
    @php $paymentState = \App\Support\StayPayment::from($stay['total'], $stay['total_paid']); @endphp
    @if($paymentState['state'] === 'overpaid')
        {{-- Paid more than the total (e.g. a discount approved after paying in full) - handed back at the front desk. --}}
        <div class="d-flex justify-content-between text-primary"><strong>To be returned to you</strong><strong>{{ $peso($paymentState['excess']) }}</strong></div>
        <small class="text-muted d-block">The front desk returns the difference; only the actual total is kept as paid.</small>
    @else
        <div class="d-flex justify-content-between"><strong>Balance</strong><strong>{{ $peso($stay['balance']) }}</strong></div>
        @if($paymentState['state'] === 'paid')
            <span class="badge bg-success mt-1">Fully paid</span>
        @endif
    @endif
    @if($stay['mode'] !== \App\Support\StayBill::FINAL)
        <small class="text-muted d-block mt-2"><i class="fas fa-info-circle"></i> Estimate until check-out - extra charges or a late check-out are added then.</small>
    @endif
</x-card>
