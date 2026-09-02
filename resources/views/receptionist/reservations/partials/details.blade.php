@php
    $depositPayment = $reservation->payments->firstWhere('payment_stage', 'deposit');
    $nights = $reservation->number_of_nights;
    $roomCharge = (float) ($reservation->roomType->rate ?? 0) * max(1, $nights) * $reservation->rooms_requested;
@endphp

<!-- Header: reservation #, guest, room type, dates, current status -->
<div class="d-flex justify-content-between align-items-start flex-wrap gap-2 mb-3 pb-3 border-bottom">
    <div class="d-flex align-items-center gap-3">
        @if($reservation->roomType)
            <img src="{{ $reservation->roomType->image_url }}" alt="{{ $reservation->roomType->name }}"
                 class="rounded" style="width: 64px; height: 64px; object-fit: cover; flex-shrink: 0;">
        @endif
        <div>
            <h5 class="mb-1">Reservation #{{ $reservation->id }}</h5>
            <p class="text-muted mb-0">
                {{ $reservation->guest_display_name }}
                &bull; {{ $reservation->roomType->name ?? 'N/A' }}
                &bull; {{ $reservation->check_in->format('M d') }} - {{ $reservation->check_out->format('M d, Y') }}
            </p>
        </div>
    </div>
    <x-status-badge :status="$reservation->status" domain="reservation" />
</div>

<div class="row mb-3">
    <!-- Guest Information + Stay Details -->
    <div class="col-md-6">
        <h6 class="text-brand"><i class="fas fa-user"></i> Guest Information</h6>
        <p class="mb-1"><strong>Account Holder:</strong> {{ $reservation->guest?->user?->full_name ?? 'Walk-in (no account)' }}</p>
        <p class="mb-1">
            <strong>Representative Name:</strong>
            {{ $reservation->guest_display_name }}
        </p>
        <p class="mb-1"><strong>Email:</strong> {{ $reservation->guest?->user?->email ?? 'N/A' }}</p>
        <p class="mb-1"><strong>Mobile:</strong> {{ $reservation->guest?->mobile_number ?: 'Not provided' }}</p>
        <p class="mb-3"><strong>Address:</strong> {{ $reservation->guest?->address ?: 'Not provided' }}</p>

        <h6 class="text-brand"><i class="fas fa-bed"></i> Stay Details</h6>
        <p class="mb-1">
            <strong>Room Type:</strong> {{ $reservation->roomType->name ?? 'N/A' }}
            @if($reservation->rooms_requested > 1)
                <span class="badge bg-secondary">&times;{{ $reservation->rooms_requested }} rooms</span>
            @endif
        </p>
        <p class="mb-1"><strong>Rooms Requested:</strong> {{ $reservation->rooms_requested }}</p>
        <p class="mb-1"><strong>Capacity per Room:</strong> {{ $reservation->roomType->capacity ?? 'N/A' }} guest{{ ($reservation->roomType->capacity ?? 0) == 1 ? '' : 's' }}</p>
        <p class="mb-1"><strong>Rate per Room/Night:</strong> ₱{{ number_format($reservation->roomType->rate ?? 0, 2) }}</p>
        @if(isset($available))
            <p class="mb-1">
                <strong>Availability:</strong>
                @if($available >= $reservation->rooms_requested)
                    <span class="text-success"><i class="fas fa-check-circle"></i> {{ $available }} room(s) free for these dates</span>
                @else
                    <span class="text-danger"><i class="fas fa-exclamation-triangle"></i> Only {{ $available }} free (needs {{ $reservation->rooms_requested }})</span>
                @endif
            </p>
        @endif
        <p class="mb-1"><strong>Assigned Room{{ $reservation->rooms_requested > 1 ? 's' : '' }}:</strong> <span class="text-muted">Not yet assigned - a receptionist assigns specific room(s) from the Bookings module once this converts to a booking.</span></p>
        <p class="mb-1"><strong>Check-In:</strong> {{ $reservation->check_in->format('M d, Y') }}</p>
        <p class="mb-1"><strong>Check-Out:</strong> {{ $reservation->check_out->format('M d, Y') }}</p>
        <p class="mb-0"><strong>Guests:</strong> {{ $reservation->adults }} adult{{ $reservation->adults == 1 ? '' : 's' }}@if($reservation->children > 0), {{ $reservation->children }} child{{ $reservation->children == 1 ? '' : 'ren' }}@endif</p>
    </div>

    <!-- Payment Information + Deposit + Discount -->
    <div class="col-md-6">
        <h6 class="text-brand"><i class="fas fa-receipt"></i> Amount to Pay</h6>
        <div class="table-responsive">
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
        </div>
        <p class="text-muted small mb-3">
            <i class="fas fa-info-circle"></i> Final charges (extra-guest fees, amenities, additional charges, and
            any verified discount) are settled at checkout, not here.
        </p>

        <p class="mb-1">
            <strong>{{ $reservation->payment_preference === 'pay_now' ? 'Pay Now' : 'Pay Later' }}</strong>
            via {{ $reservation->payment_method === 'gcash' ? 'GCash QR' : 'Cash' }}
        </p>
        @if($reservation->payment_deadline)
            <p class="mb-1">
                <strong>Payment Deadline:</strong>
                <span class="text-danger">{{ \Illuminate\Support\Carbon::parse($reservation->payment_deadline)->format('M d, Y h:i A') }}</span>
                <small class="text-muted d-block">Unpaid past this deadline auto-cancels the reservation.</small>
            </p>
        @endif
        @if($depositPayment)
            <p class="mb-1"><strong>Deposit Status:</strong> <x-status-badge :status="$depositPayment->payment_status" domain="payment" /></p>
            <p class="mb-1"><strong>Deposit Amount:</strong> ₱{{ number_format($depositPayment->amount_paid, 2) }}</p>
            @php $depositRemaining = max(0, $roomCharge - (float) $depositPayment->amount_paid); @endphp
            <p class="mb-3"><strong>Remaining Balance:</strong> ₱{{ number_format($depositRemaining, 2) }}
                <small class="text-muted d-block">Settled at checkout (extra-guest fees, amenities, and any verified discount are added there too).</small>
            </p>
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
                    <img src="{{ route('receptionist.reservations.id-card', $reservation) }}" alt="ID Card{{ $reservation->id_card_type ? " ({$reservation->id_card_type})" : '' }}" class="img-thumbnail" style="max-height: 150px;">
                    <small class="d-block text-muted">{{ $reservation->id_card_type ? "{$reservation->id_card_type} - " : '' }}Click to view full size</small>
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
            @if($depositPayment->gcash_number)
                <p class="mb-1 mt-2"><strong>GCash Number:</strong></p>
                <p class="mb-0">{{ $depositPayment->gcash_number }}</p>
            @endif
        </div>
    </div>

    <div class="mt-3">
        <p class="mb-2">
            <strong>Payment Verification:</strong>
            @if($depositPayment->isVerified())
                <span class="badge bg-success">Verified</span>
            @elseif($depositPayment->isRejected())
                <span class="badge bg-danger">Rejected</span>
                @if($depositPayment->rejection_reason)
                    <br><small class="text-muted">Reason: {{ $depositPayment->rejection_reason }}</small>
                @endif
            @else
                <span class="badge bg-warning text-dark">Pending Verification</span>
            @endif
        </p>
        @if(!$depositPayment->isVerified() && !$depositPayment->isRejected())
            <div class="d-flex gap-2">
                <form action="{{ route('receptionist.payments.verify', $depositPayment) }}" method="POST" class="d-inline">
                    @csrf
                    @method('PUT')
                    <button type="submit" class="btn btn-sm btn-success" onclick="return confirm('Verify this payment?')">
                        <i class="fas fa-check"></i> Verify Payment
                    </button>
                </form>
                <form action="{{ route('receptionist.payments.reject', $depositPayment) }}" method="POST" class="d-inline" onsubmit="return window.preparePaymentReject(this)">
                    @csrf
                    @method('PUT')
                    <input type="hidden" name="reason" value="">
                    <button type="submit" class="btn btn-sm btn-outline-danger">
                        <i class="fas fa-times"></i> Reject Payment
                    </button>
                </form>
            </div>
        @endif
    </div>
@endif

{{-- Only worth showing once there's an actual payment on file - before
     that, "history" is just "Created/Submitted reservation", which isn't
     a transaction and reads as noise on a reservation nobody has paid on
     yet. --}}
@if($reservation->payments->isNotEmpty() && $history->isNotEmpty())
    <hr>
    <h6 class="text-brand"><i class="fas fa-history"></i> Transaction History</h6>
    <div class="row">
        @foreach($history as $entry)
            <div class="col-md-6 mb-2">
                <p class="mb-0"><strong>{{ $entry->action }}</strong></p>
                @if($entry->description)
                    <p class="mb-0 text-muted small">{{ $entry->description }}</p>
                @endif
                <p class="mb-0 text-muted small">
                    <i class="fas fa-user"></i> {{ $entry->user->full_name ?? 'System' }}
                    &middot; {{ $entry->created_at->format('M d, Y h:i A') }}
                </p>
            </div>
        @endforeach
    </div>
@endif

@if(in_array($reservation->status, \App\Models\Reservation::ACTIVE_STATUSES))
    <hr>
    <div class="alert alert-danger d-none" id="detailsActionError"></div>

    <div id="detailsRejectForm" class="d-none">
        <h6 class="text-brand"><i class="fas fa-times-circle"></i> Reject Reservation</h6>
        <div class="mb-2">
            <label class="form-label">Reason <span class="text-danger">*</span></label>
            <textarea id="detailsRejectReason" class="form-control" rows="3" maxlength="500" required
                      placeholder="This will be sent to the guest.">{{ ($available ?? 0) < $reservation->rooms_requested ? 'The ' . ($reservation->roomType->name ?? '') . ' room type does not have enough rooms available for your requested dates.' : '' }}</textarea>
        </div>
        <div class="d-flex gap-2 justify-content-end">
            <button type="button" class="btn btn-secondary" id="detailsCancelRejectBtn">Cancel</button>
            <button type="button" class="btn btn-danger" id="detailsSubmitRejectBtn">Reject Reservation</button>
        </div>
    </div>

    @if($reservation->payment_method === 'cash')
        <div id="detailsCashPaymentForm" class="d-none">
            <h6 class="text-brand"><i class="fas fa-money-bill-wave"></i> Convert to Booking</h6>
            <p class="text-muted small">Enter the amount actually received from the guest at the front desk. Must be
                the full ₱{{ number_format($roomCharge, 2) }}, or a deposit between 20%-50% of that total.</p>
            <div class="mb-2">
                <label class="form-label">Amount Received (₱) <span class="text-danger">*</span></label>
                <input type="number" id="detailsCashAmount" class="form-control" min="0.01" step="0.01" required
                       placeholder="0.00">
            </div>
            <div class="d-flex gap-2 justify-content-end">
                <button type="button" class="btn btn-secondary" id="detailsCancelCashBtn">Cancel</button>
                <button type="button" class="btn btn-success" id="detailsSubmitCashBtn">
                    <i class="fas fa-check"></i> Confirm &amp; Convert to Booking
                </button>
            </div>
        </div>
    @endif

    @if($reservation->payment_method === 'gcash' && $reservation->status === \App\Models\Reservation::STATUS_AWAITING_GCASH && $reservation->payments->isEmpty())
        <!-- No manual Accept step for GCash - recordDepositPayment() moves this
             straight to ready_for_booking (and usually auto-converts) the
             moment the guest actually submits a payment; there's nothing for
             the receptionist to do here yet. Matches convertToBooking()'s own
             defense-in-depth guard against converting an unpaid GCash
             reservation. -->
        <div class="d-flex justify-content-between align-items-center">
            <p class="text-muted mb-0"><i class="fas fa-hourglass-half"></i> Waiting for Guest GCash Payment</p>
            <button type="button" class="btn btn-outline-danger btn-sm" id="detailsShowRejectBtn">
                <i class="fas fa-times"></i> Reject
            </button>
        </div>
    @else
    @php $notEnoughRooms = ($available ?? 0) < $reservation->rooms_requested; @endphp
    <div id="detailsMainActions" class="d-flex gap-2 justify-content-end">
        <button type="button" class="btn btn-outline-danger" id="detailsShowRejectBtn">
            <i class="fas fa-times"></i> Reject
        </button>
        @if($reservation->payment_method === 'cash')
            {{-- One action for Cash: asks how much was actually received,
                 then records that payment and converts to a Booking in the
                 same step (confirmCashPayment()) - there's no way to
                 convert a Cash reservation without also recording what the
                 guest paid. --}}
            <button type="button" class="btn btn-success" id="detailsShowCashBtn" {{ $notEnoughRooms ? 'disabled' : '' }}
                    title="{{ $notEnoughRooms ? 'Not enough rooms of this type available for the requested dates.' : '' }}">
                <i class="fas fa-calendar-check"></i> Convert to Booking
            </button>
        @else
            {{-- GCash, reached here only once a payment is actually on
                 file (the "Waiting for Guest GCash Payment" branch above
                 covers the unpaid case) - Convert works the same whether
                 this reservation is still pending_review or already
                 ready_for_booking, no separate Accept step either. --}}
            <button type="button" class="btn btn-success" id="detailsConvertBtn" {{ $notEnoughRooms ? 'disabled' : '' }}
                    title="{{ $notEnoughRooms ? 'Not enough rooms of this type available for the requested dates.' : '' }}">
                <i class="fas fa-calendar-check"></i> Convert to Booking
            </button>
        @endif
    </div>
    @endif
@endif



