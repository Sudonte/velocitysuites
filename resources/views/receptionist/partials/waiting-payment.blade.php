@php
    // Money the guest submitted that nobody has verified yet: shown next to what is already paid, with a link to each proof.
    $waitingPayments = $booking->allPayments()->filter(fn ($p) => $p->isWaitingForVerification());
    $waitingTotal = round((float) $waitingPayments->sum('amount_paid'), 2);
@endphp
@if($waitingTotal > 0.009)
    <div class="small mt-1">
        <span class="text-muted">Paid ₱{{ number_format($booking->paidTotal(), 2) }}</span> &middot;
        <span class="badge bg-warning text-dark">Waiting for verification: ₱{{ number_format($waitingTotal, 2) }}</span>
        @foreach($waitingPayments as $waitingPayment)
            @if($waitingPayment->receipt_url)
                <a href="{{ $waitingPayment->receipt_url }}" target="_blank" rel="noopener" class="ms-1"><i class="fas fa-image"></i> Proof</a>
            @endif
        @endforeach
    </div>
@endif
