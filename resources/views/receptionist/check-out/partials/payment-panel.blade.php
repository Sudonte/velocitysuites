<div class="modal-header modal-header-brand">
    <h5 class="modal-title"><i class="fas fa-credit-card"></i> Payment</h5>
    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
</div>
<div class="modal-body" data-billing-id="{{ $billing->id }}">
    <h6>Bill Summary</h6>
    <table class="table table-sm table-borderless mb-3">
        <tr>
            <td>Room Charge</td>
            <td class="text-end">₱{{ number_format($billing->room_charge, 2) }}</td>
        </tr>
        @if($billing->additional_guest_fee > 0)
            <tr>
                <td>Additional Guest Fee</td>
                <td class="text-end">₱{{ number_format($billing->additional_guest_fee, 2) }}</td>
            </tr>
        @endif
        @if($billing->amenity_charge > 0)
            <tr>
                <td>Amenities & Services</td>
                <td class="text-end">₱{{ number_format($billing->amenity_charge, 2) }}</td>
            </tr>
        @endif
        @if($billing->additional_charges_total > 0)
            <tr>
                <td>Additional Charges</td>
                <td class="text-end">₱{{ number_format($billing->additional_charges_total, 2) }}</td>
            </tr>
        @endif
        @if($billing->discount > 0)
            <tr>
                <td>Discount</td>
                <td class="text-end text-success">-₱{{ number_format($billing->discount, 2) }}</td>
            </tr>
        @endif
        <tr class="fw-bold">
            <td>Grand Total</td>
            <td class="text-end">₱{{ number_format($billing->running_total, 2) }}</td>
        </tr>
        @if($amountPaidSoFar > 0)
            <tr>
                <td>Already Paid</td>
                <td class="text-end">₱{{ number_format($amountPaidSoFar, 2) }}</td>
            </tr>
        @endif
        <tr class="fw-bold fs-5">
            <td>Balance Due</td>
            <td class="text-end text-brand" id="balanceDueDisplay">₱{{ number_format($balance, 2) }}</td>
        </tr>
    </table>

    <div class="alert alert-danger d-none" id="paymentErrorAlert"></div>

    <form id="paymentForm">
        <div class="mb-3">
            <label class="form-label">Payment Method <span class="text-danger">*</span></label>
            <select name="payment_method" id="paymentMethodSelect" class="form-select" required>
                <option value="cash">Cash</option>
                <option value="gcash">GCash</option>
            </select>
        </div>
        <div class="mb-3">
            <label class="form-label">Amount Received <span class="text-danger">*</span></label>
            <input type="number" step="0.01" min="0.01" name="amount_paid" id="amountPaidInput" class="form-control" value="{{ $balance }}" data-balance="{{ $balance }}" required>
        </div>
        <div class="mb-3 d-none" id="referenceNumberGroup">
            <label class="form-label">Reference Number <span class="text-danger">*</span></label>
            <input type="text" name="reference_number" id="referenceNumberInput" class="form-control" placeholder="GCash reference number">
        </div>
        <div class="mb-3 d-none" id="changeDueGroup">
            <label class="form-label">Change Due</label>
            <input type="text" class="form-control" id="changeDueDisplay" readonly>
        </div>
    </form>
</div>
<div class="modal-footer">
    <button type="button" class="btn btn-secondary" id="backToBillingBtn">
        <i class="fas fa-arrow-left"></i> Back to Billing
    </button>
    <button type="submit" form="paymentForm" class="btn btn-success" id="completePaymentBtn">
        <i class="fas fa-check"></i> Complete Payment
    </button>
</div>
