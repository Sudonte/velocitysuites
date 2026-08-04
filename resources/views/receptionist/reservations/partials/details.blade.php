@php
    $depositPayment = $reservation->payments->firstWhere('payment_stage', 'deposit');
    $nights = $reservation->number_of_nights;
    $roomCharge = (float) ($reservation->roomType->rate ?? 0) * max(1, $nights);
@endphp

<!-- Header: reservation #, guest, room type, dates, current status -->
<div class="d-flex justify-content-between align-items-start flex-wrap gap-2 mb-3 pb-3 border-bottom">
    <div>
        <h5 class="mb-1">Reservation #{{ $reservation->id }}</h5>
        <p class="text-muted mb-0">
            {{ $reservation->guest->user->full_name ?? 'N/A' }}
            &bull; {{ $reservation->roomType->name ?? 'N/A' }}
            &bull; {{ $reservation->check_in->format('M d') }} - {{ $reservation->check_out->format('M d, Y') }}
        </p>
    </div>
    <x-status-badge :status="$reservation->status" domain="reservation" />
</div>

<div class="row mb-3">
    <!-- Guest Information + Stay Details -->
    <div class="col-md-6">
        <h6 class="text-brand"><i class="fas fa-user"></i> Guest Information</h6>
        <p class="mb-1"><strong>Name:</strong> {{ $reservation->guest->user->full_name ?? 'N/A' }}</p>
        <p class="mb-1"><strong>Email:</strong> {{ $reservation->guest->user->email ?? 'N/A' }}</p>
        <p class="mb-1"><strong>Mobile:</strong> {{ $reservation->guest->mobile_number ?: 'Not provided' }}</p>
        <p class="mb-3"><strong>Address:</strong> {{ $reservation->guest->address ?: 'Not provided' }}</p>

        <h6 class="text-brand"><i class="fas fa-bed"></i> Stay Details</h6>
        <p class="mb-1"><strong>Room Type:</strong> {{ $reservation->roomType->name ?? 'N/A' }}</p>
        <p class="mb-1"><strong>Assigned Room:</strong> <span class="text-muted">Not yet assigned - happens at check-in</span></p>
        <p class="mb-1"><strong>Check-In:</strong> {{ $reservation->check_in->format('M d, Y') }}</p>
        <p class="mb-1"><strong>Check-Out:</strong> {{ $reservation->check_out->format('M d, Y') }}</p>
        <p class="mb-0"><strong>Guests:</strong> {{ $reservation->adults }} adult{{ $reservation->adults == 1 ? '' : 's' }}@if($reservation->children > 0), {{ $reservation->children }} child{{ $reservation->children == 1 ? '' : 'ren' }}@endif</p>
    </div>

    <!-- Payment Information + Deposit + Discount -->
    <div class="col-md-6">
        <h6 class="text-brand"><i class="fas fa-receipt"></i> Payment Information</h6>
        <table class="table table-sm table-borderless mb-2">
            <tr>
                <td>Room Charge ({{ $nights }} night{{ $nights === 1 ? '' : 's' }})</td>
                <td class="text-end">₱{{ number_format($roomCharge, 2) }}</td>
            </tr>
            <tr class="fw-bold">
                <td>Estimated Total</td>
                <td class="text-end text-brand">₱{{ number_format($roomCharge, 2) }}</td>
            </tr>
        </table>
        <p class="text-muted small mb-3">
            <i class="fas fa-info-circle"></i> Final charges (extra-guest fees, amenities, additional charges, and
            any verified discount) are settled at checkout, not here.
        </p>

        <p class="mb-1">
            <strong>{{ $reservation->payment_preference === 'pay_now' ? 'Pay Now' : 'Pay Later' }}</strong>
            via {{ $reservation->payment_method === 'gcash' ? 'GCash QR' : 'Cash' }}
        </p>
        @if($depositPayment)
            <p class="mb-1"><strong>Deposit Status:</strong> <x-status-badge :status="$depositPayment->payment_status" domain="payment" /></p>
            <p class="mb-3"><strong>Deposit Amount:</strong> ₱{{ number_format($depositPayment->amount_paid, 2) }}</p>
        @else
            <p class="text-muted mb-3">No deposit submitted yet.</p>
        @endif

        <h6 class="text-brand"><i class="fas fa-id-card"></i> Discount Request</h6>
        @if($reservation->discount_requested)
            <p class="mb-1"><strong>Status:</strong> <x-status-badge :status="$reservation->discount_verification_status" domain="discount_verification" /></p>
            @if($reservation->id_document_path)
                <a href="{{ asset('storage/' . $reservation->id_document_path) }}" target="_blank" class="d-block mt-1">
                    <img src="{{ asset('storage/' . $reservation->id_document_path) }}" alt="ID Document" class="img-thumbnail" style="max-height: 150px;">
                    <small class="d-block text-muted">Click to view full size</small>
                </a>
            @elseif($reservation->id_card_image_path)
                <a href="{{ route('receptionist.reservations.id-card', $reservation) }}" target="_blank" class="d-block mt-1">
                    <img src="{{ route('receptionist.reservations.id-card', $reservation) }}" alt="ID Card ({{ $reservation->id_card_type }})" class="img-thumbnail" style="max-height: 150px;">
                    <small class="d-block text-muted">{{ $reservation->id_card_type }} - submitted via mobile app. Click to view full size</small>
                </a>
            @else
                <p class="text-muted mb-0">Requested but no ID uploaded yet.</p>
            @endif
        @else
            <p class="text-muted mb-0">No discount requested.</p>
        @endif
    </div>
</div>

@if($depositPayment && $depositPayment->payment_method === 'gcash')
    <hr>
    <h6 class="text-brand"><i class="fas fa-qrcode"></i> GCash Payment Receipt</h6>
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

@if(in_array($reservation->status, ['pending_review', 'ready_for_booking']))
    <hr>
    <div class="alert alert-danger d-none" id="detailsActionError"></div>

    <div id="detailsRejectForm" class="d-none">
        <h6 class="text-brand"><i class="fas fa-times-circle"></i> Reject Reservation</h6>
        <div class="mb-2">
            <label class="form-label">Reason <span class="text-danger">*</span></label>
            <textarea id="detailsRejectReason" class="form-control" rows="3" maxlength="500" required
                      placeholder="This will be sent to the guest.">{{ $reservation->status === 'ready_for_booking' && ($available ?? 1) <= 0 ? 'The ' . ($reservation->roomType->name ?? '') . ' room type is fully booked for your requested dates.' : '' }}</textarea>
        </div>
        <div class="d-flex gap-2 justify-content-end">
            <button type="button" class="btn btn-secondary" id="detailsCancelRejectBtn">Cancel</button>
            <button type="button" class="btn btn-danger" id="detailsSubmitRejectBtn">Reject Reservation</button>
        </div>
    </div>

    <div id="detailsMainActions" class="d-flex gap-2 justify-content-end">
        <button type="button" class="btn btn-outline-danger" id="detailsShowRejectBtn">
            <i class="fas fa-times"></i> Reject
        </button>
        @if($reservation->status === 'pending_review')
            <button type="button" class="btn btn-success" id="detailsAcceptBtn">
                <i class="fas fa-check"></i> Accept
            </button>
        @else
            @php $isFullyBooked = ($available ?? 0) <= 0; @endphp
            <button type="button" class="btn btn-success" id="detailsConvertBtn" {{ $isFullyBooked ? 'disabled' : '' }}
                    title="{{ $isFullyBooked ? 'This room type is fully booked for the requested dates.' : '' }}">
                <i class="fas fa-calendar-check"></i> Convert to Booking
            </button>
        @endif
    </div>
@endif
