<div class="modal-header modal-header-brand">
    <h5 class="modal-title"><i class="fas fa-cash-register"></i> Billing</h5>
    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
</div>
<div class="modal-body" data-billing-id="{{ $billing->id }}" data-booking-id="{{ $booking->id }}">
    <div class="alert alert-danger d-none" id="billingErrorAlert"></div>

    <div class="row mb-3">
        <div class="col-md-6">
            <strong>Guest:</strong> {{ $booking->guest_display_name }}<br>
            <strong>Booking:</strong> #{{ $booking->id }}<br>
            <strong>Room{{ $booking->rooms->count() > 1 ? 's' : '' }}:</strong> {{ $booking->rooms->pluck('room_number')->implode(', ') ?: 'N/A' }}
        </div>
        <div class="col-md-6 text-md-end">
            <strong>Check-In:</strong> {{ $booking->check_in->format('M d, Y') }}<br>
            <strong>Check-Out:</strong> {{ $effectiveCheckOutDate->format('M d, Y') }}
            @if($isEarlyCheckout)
                <span class="badge bg-info" title="Originally scheduled for {{ $booking->check_out->format('M d, Y') }} - billed for the shorter, actual stay only.">Early Checkout</span>
            @elseif($isLateCheckout)
                <span class="badge bg-warning text-dark" title="Originally scheduled for {{ $booking->check_out->format('M d, Y') }} - billed for the extra night(s) actually stayed.">Late Checkout</span>
            @endif
            <br>
            <strong>Nights:</strong> {{ $effectiveNights }}
        </div>
    </div>

    <h6><i class="fas fa-calculator"></i> Charges</h6>
    <p class="small text-muted mb-2">
        Booked {{ \Carbon\Carbon::parse($stay['check_in'])->format('M j, Y') }} to {{ \Carbon\Carbon::parse($stay['scheduled_check_out'])->format('M j, Y') }}
        ({{ $stay['scheduled_nights'] }} night{{ $stay['scheduled_nights'] === 1 ? '' : 's' }}).
        @if($stay['extra_nights'] > 0)
            Actual stay: <strong>{{ $stay['actual_nights'] }} nights</strong> - {{ $stay['extra_nights'] }} extra night{{ $stay['extra_nights'] === 1 ? '' : 's' }} billed (₱{{ number_format($stay['extra_nights_charge'], 2) }}).
        @elseif($isEarlyCheckout)
            Actual stay: <strong>{{ $stay['actual_nights'] }} night{{ $stay['actual_nights'] === 1 ? '' : 's' }}</strong> - billed for the nights stayed.
        @endif
    </p>
    <div class="table-responsive">
    <table class="table table-sm table-borderless mb-3">
        @foreach($stay['rooms'] as $line)
            <tr>
                <td>
                    Room {{ $line['room_number'] }}@if($line['room_type']) <span class="text-muted">({{ $line['room_type'] }})</span>@endif
                    - ₱{{ number_format($line['rate'], 2) }} x {{ $line['nights'] }} night{{ $line['nights'] === 1 ? '' : 's' }}
                    @if($line['extra_nights'] > 0)<span class="badge bg-warning text-dark">+{{ $line['extra_nights'] }} extra</span>@endif
                    @if($line['status'] === 'checked_out')<span class="badge bg-secondary">out {{ \Carbon\Carbon::parse($line['checked_out_on'])->format('M j') }}</span>@endif
                </td>
                <td class="text-end">₱{{ number_format($line['subtotal'], 2) }}</td>
            </tr>
        @endforeach
        <tr class="fw-semibold">
            <td>Room Charge</td>
            <td class="text-end">₱{{ number_format($billing->room_charge, 2) }}</td>
        </tr>
        @if($billing->additional_guest_fee > 0)
            <tr>
                <td>Additional Guest Fee</td>
                <td class="text-end">₱{{ number_format($billing->additional_guest_fee, 2) }}</td>
            </tr>
        @endif
    </table>
    </div>

    <h6><i class="fas fa-spa"></i> Amenities & Services</h6>
    <div class="table-responsive">
    <table class="table table-sm mb-3">
        <thead>
            <tr>
                <th>Item</th>
                <th>Qty</th>
                <th class="text-end">Amount</th>
            </tr>
        </thead>
        <tbody>
            @forelse($amenityRequests as $req)
                <tr>
                    <td>{{ $req->amenity->amenity_name ?? 'N/A' }}</td>
                    <td>{{ $req->quantity }}</td>
                    <td class="text-end">₱{{ number_format($req->charge * $req->quantity, 2) }}</td>
                </tr>
            @empty
                <tr><td colspan="3" class="text-center text-muted py-2">No amenities requested during this stay.</td></tr>
            @endforelse
        </tbody>
    </table>
    </div>

    <div id="chargesTableContainer">
        @include('receptionist.check-out.partials.charges-table', ['billing' => $billing])
    </div>

    @include('receptionist.check-out.partials.discount-panel', ['billing' => $billing, 'discounts' => $discounts])

    <div class="table-responsive">
    <table class="table table-sm mb-0 mt-3">
        @if($billing->discount > 0)
            <tr>
                <td>Discount{{ $billing->discountApplied ? ' (' . $billing->discountApplied->name . ')' : '' }}</td>
                <td class="text-end text-success">-₱{{ number_format($billing->discount, 2) }}</td>
            </tr>
        @endif
        <tr class="fw-bold fs-5">
            <td>Running Total</td>
            <td class="text-end text-brand" id="runningTotalDisplay">₱{{ number_format($billing->running_total, 2) }}</td>
        </tr>
    </table>
    </div>
</div>
<div class="modal-footer">
    <button type="button" class="btn btn-secondary" id="cancelBillingBtn">Cancel Billing</button>
    <button type="button" class="btn btn-primary" id="proceedToPaymentBtn">
        <i class="fas fa-arrow-right"></i> Proceed to Payment
    </button>
</div>
