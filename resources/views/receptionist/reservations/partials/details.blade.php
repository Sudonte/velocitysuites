@php
    $depositPayment = $reservation->payments->firstWhere('payment_stage', 'deposit');
@endphp

<div class="row mb-3">
    <div class="col-md-6">
        <h6 class="text-brand">Guest Details</h6>
        <p class="mb-1"><strong>Name:</strong> {{ $reservation->guest->user->full_name ?? 'N/A' }}</p>
        <p class="mb-1"><strong>Email:</strong> {{ $reservation->guest->user->email ?? 'N/A' }}</p>
        <p class="mb-1"><strong>Phone:</strong> {{ $reservation->guest->mobile_number ?: 'Not provided' }}</p>
        <p class="mb-0"><strong>Address:</strong> {{ $reservation->guest->address ?: 'Not provided' }}</p>
    </div>
    <div class="col-md-6">
        <h6 class="text-brand">Reservation Information</h6>
        <p class="mb-1"><strong>Reservation #:</strong> {{ $reservation->id }}</p>
        <p class="mb-1"><strong>Room Type:</strong> {{ $reservation->roomType->name ?? 'N/A' }}</p>
        <p class="mb-1"><strong>Check-In:</strong> {{ $reservation->check_in->format('M d, Y') }}</p>
        <p class="mb-1"><strong>Check-Out:</strong> {{ $reservation->check_out->format('M d, Y') }}</p>
        <p class="mb-0"><strong>Guests:</strong> {{ $reservation->adults }} adult{{ $reservation->adults == 1 ? '' : 's' }}@if($reservation->children > 0), {{ $reservation->children }} child{{ $reservation->children == 1 ? '' : 'ren' }}@endif</p>
    </div>
</div>

<hr>

<div class="row mb-3">
    <div class="col-md-6">
        <h6 class="text-brand">Payment Preference</h6>
        <p class="mb-1">
            <strong>{{ $reservation->payment_preference === 'pay_now' ? 'Pay Now' : 'Pay Later' }}</strong>
            via {{ $reservation->payment_method === 'gcash' ? 'GCash QR' : 'Cash' }}
        </p>
        @if($depositPayment)
            <p class="mb-1"><strong>Deposit Status:</strong> <x-status-badge :status="$depositPayment->payment_status" domain="payment" /></p>
            @if($reservation->payment_method === 'cash')
                <p class="mb-0"><strong>Intended Amount:</strong> ₱{{ number_format($depositPayment->amount_paid, 2) }}</p>
            @endif
        @else
            <p class="text-muted mb-0">No deposit submitted yet.</p>
        @endif
    </div>
    <div class="col-md-6">
        <h6 class="text-brand">Discount Request</h6>
        @if($reservation->discount_requested)
            <p class="mb-1"><strong>Status:</strong> <x-status-badge :status="$reservation->discount_verification_status" domain="discount_verification" /></p>
            @if($reservation->id_document_path)
                <a href="{{ asset('storage/' . $reservation->id_document_path) }}" target="_blank" class="d-block mt-1">
                    <img src="{{ asset('storage/' . $reservation->id_document_path) }}" alt="ID Document" class="img-thumbnail" style="max-height: 150px;">
                    <small class="d-block text-muted">Click to view full size</small>
                </a>
            @endif
        @else
            <p class="text-muted mb-0">No discount requested.</p>
        @endif
    </div>
</div>

@if($depositPayment && $depositPayment->payment_method === 'gcash')
    <hr>
    <h6 class="text-brand">GCash Payment Receipt</h6>
    <div class="row">
        <div class="col-md-6">
            @if($depositPayment->receipt_path)
                <a href="{{ asset('storage/' . $depositPayment->receipt_path) }}" target="_blank">
                    <img src="{{ asset('storage/' . $depositPayment->receipt_path) }}" alt="Payment Receipt" class="img-thumbnail" style="max-height: 200px;">
                </a>
            @else
                <p class="text-muted">No receipt uploaded.</p>
            @endif
        </div>
        <div class="col-md-6">
            <p class="mb-1"><strong>Transaction Reference Number:</strong></p>
            <p class="mb-0">{{ $depositPayment->reference_number ?: 'Not provided' }}</p>
        </div>
    </div>
@endif
