<div class="modal-header modal-header-brand">
    <h5 class="modal-title"><i class="fas fa-cash-register"></i> Billing</h5>
    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
</div>
<div class="modal-body" data-billing-id="{{ $billing->id }}" data-reservation-id="{{ $reservation->id }}">
    <div class="alert alert-danger d-none" id="billingErrorAlert"></div>

    <div class="row mb-3">
        <div class="col-md-6">
            <strong>Guest:</strong> {{ $reservation->stay_guest_full_name ?? $reservation->guest->user->full_name ?? 'N/A' }}<br>
            <strong>Reservation:</strong> #{{ $reservation->id }}<br>
            <strong>Room:</strong> {{ $reservation->room->room_number ?? 'N/A' }} ({{ $reservation->room->room_name ?? '' }})
        </div>
        <div class="col-md-6 text-md-end">
            <strong>Check-In:</strong> {{ $reservation->check_in->format('M d, Y') }}<br>
            <strong>Check-Out:</strong> {{ $reservation->check_out->format('M d, Y') }}<br>
            <strong>Nights:</strong> {{ $reservation->number_of_nights }}
        </div>
    </div>

    <h6><i class="fas fa-calculator"></i> Charges</h6>
    <table class="table table-sm table-borderless mb-3">
        <tr>
            <td>Room Charge ({{ $reservation->number_of_nights }} night{{ $reservation->number_of_nights === 1 ? '' : 's' }})</td>
            <td class="text-end">₱{{ number_format($billing->room_charge, 2) }}</td>
        </tr>
        @if($billing->additional_guest_fee > 0)
            <tr>
                <td>Additional Guest Fee</td>
                <td class="text-end">₱{{ number_format($billing->additional_guest_fee, 2) }}</td>
            </tr>
        @endif
    </table>

    <h6><i class="fas fa-spa"></i> Amenities & Services</h6>
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

    <div id="chargesTableContainer">
        @include('receptionist.check-out.partials.charges-table', ['billing' => $billing])
    </div>

    <table class="table table-sm mb-0 mt-3">
        @if($billing->discount > 0)
            <tr>
                <td>Discount</td>
                <td class="text-end text-success">-₱{{ number_format($billing->discount, 2) }}</td>
            </tr>
        @endif
        <tr class="fw-bold fs-5">
            <td>Running Total</td>
            <td class="text-end text-brand" id="runningTotalDisplay">₱{{ number_format($billing->running_total, 2) }}</td>
        </tr>
    </table>
</div>
<div class="modal-footer">
    <button type="button" class="btn btn-secondary" id="cancelBillingBtn">Cancel Billing</button>
    <button type="button" class="btn btn-primary" id="proceedToPaymentBtn">
        <i class="fas fa-arrow-right"></i> Proceed to Payment
    </button>
</div>
