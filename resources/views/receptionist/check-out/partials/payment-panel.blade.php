<div class="modal-header modal-header-brand">
    <h5 class="modal-title"><i class="fas fa-credit-card"></i> Payment</h5>
    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
</div>
<div class="modal-body" data-billing-id="{{ $billing->id }}">
    {{-- Payment Summary: one aligned column, always in the same order - room charges, discounts, what was already paid,
         the additional charges itemized, then the total due. Every figure is the server's own bill (StayBill). --}}
    @php
        $stay = $billing->stay_bill ?? [];
        $extraLines = [];
        foreach (($stay['amenity_lines'] ?? []) as $line) {
            $extraLines[] = [
                'label' => $line['amenity_name'].' x'.$line['quantity'].(! empty($line['additional']) ? ' (requested)' : ''),
                'amount' => (float) $line['subtotal'],
            ];
        }
        foreach (($stay['additional_charges'] ?? []) as $line) {
            $extraLines[] = ['label' => $line['description'], 'amount' => (float) $line['amount']];
        }
        $guestFee = (float) $billing->additional_guest_fee;
        $extrasTotal = round(array_sum(array_column($extraLines, 'amount')) + $guestFee, 2);
        $previouslyPaid = (float) $paymentSummary['total_amount_paid'];
    @endphp
    <h6 class="mb-2">6. Payment Summary</h6>
    @if(! empty($stay['receipt_breakdown']))
        {{-- Paid in full at check-in (discount taken once, then): check-out collects only what was added since. --}}
        <div class="table-responsive mb-3">
            @include('partials.receipt-breakdown', ['breakdown' => $stay['receipt_breakdown'], 'nights' => $stay['scheduled_nights'] ?? null])
        </div>
        <table class="table table-sm table-borderless align-middle mb-3" id="paymentSummaryTable">
            <tr class="fw-bold fs-5 border-top">
                <th>Total Due</th>
                <td class="text-end text-brand" id="balanceDueDisplay">₱{{ number_format($balance, 2) }}</td>
            </tr>
        </table>
    @else
    <div class="table-responsive">
    <table class="table table-sm table-borderless align-middle mb-3" id="paymentSummaryTable">
        <colgroup><col><col style="width: 9rem;"></colgroup>
        <tr>
            <th class="fw-semibold">Room Charges</th>
            <td class="text-end">₱{{ number_format($billing->room_charge, 2) }}</td>
        </tr>
        <tr>
            <th class="fw-semibold">Discounts @if(! empty($stay['discount_name'])) <span class="text-muted fw-normal">({{ $stay['discount_name'] }})</span>@endif</th>
            <td class="text-end {{ $billing->discount > 0 ? 'text-success' : 'text-muted' }}">{{ $billing->discount > 0 ? '-' : '' }}₱{{ number_format($billing->discount, 2) }}</td>
        </tr>
        <tr>
            <th class="fw-semibold">Amount Previously Paid</th>
            <td class="text-end">-₱{{ number_format($previouslyPaid, 2) }}</td>
        </tr>
        @if($settlementTotals)
            <tr class="small text-muted">
                <td class="ps-4">Paid at check-in</td>
                <td class="text-end">₱{{ number_format($settlementTotals['at_check_in'], 2) }}</td>
            </tr>
            @if($settlementTotals['at_check_out'] > 0)
                <tr class="small text-muted">
                    <td class="ps-4">Paid at check-out</td>
                    <td class="text-end">₱{{ number_format($settlementTotals['at_check_out'], 2) }}</td>
                </tr>
            @endif
        @endif
        <tr>
            <th class="fw-semibold">Additional Charges</th>
            <td class="text-end">₱{{ number_format($extrasTotal, 2) }}</td>
        </tr>
        @forelse($extraLines as $line)
            <tr class="small text-muted">
                <td class="ps-4">{{ $line['label'] }}</td>
                <td class="text-end">₱{{ number_format($line['amount'], 2) }}</td>
            </tr>
        @empty
            @if($guestFee <= 0)
                <tr class="small text-muted"><td class="ps-4" colspan="2">None</td></tr>
            @endif
        @endforelse
        @if($guestFee > 0)
            <tr class="small text-muted">
                <td class="ps-4">Additional guest fee</td>
                <td class="text-end">₱{{ number_format($guestFee, 2) }}</td>
            </tr>
        @endif
        <tr class="small text-muted border-top">
            <td>Bill total (room + additional charges - discounts)</td>
            <td class="text-end">₱{{ number_format($paymentSummary['grand_total'], 2) }}</td>
        </tr>
        <tr class="fw-bold fs-5 border-top">
            <th>Total Due</th>
            <td class="text-end text-brand" id="balanceDueDisplay">₱{{ number_format($balance, 2) }}</td>
        </tr>
    </table>
    </div>
    @endif

    {{-- Full Payment Transaction History - reservation-originated,
         direct-booking, and checkout-collected payments alike, whatever
         Booking::allPayments() already merged for this booking - see that
         partial's own doc. Fed by the exact same paymentSummary/
         paymentTransactions arrays as the Bill Summary table above, never
         recalculated separately here. --}}
    @include('receptionist.partials.payment-history', [
        'paymentSummary' => $paymentSummary,
        'paymentTransactions' => $paymentTransactions,
    ])

    <div class="alert alert-danger d-none mt-3" id="paymentErrorAlert"></div>

    <h6 class="mt-3">7. Payment Input</h6>
    <form id="paymentForm">
        @if($balance > 0.009)
            {{-- The form continues the Payment Summary above: the same label-left / field-right rows, in the order the
                 desk works through it - how it is paid, what was received, what is applied, then change or balance. --}}
            <div class="row g-2 align-items-center mb-2">
                <label class="col-sm-5 col-form-label fw-semibold" for="paymentMethodSelect">Payment Method <span class="text-danger">*</span></label>
                <div class="col-sm-7">
                    <select name="payment_method" id="paymentMethodSelect" class="form-select" required>
                        <option value="cash">Cash</option>
                        <option value="gcash">GCash</option>
                    </select>
                </div>
            </div>
            <div class="row g-2 align-items-center mb-2" id="cashReceivedGroup">
                <label class="col-sm-5 col-form-label fw-semibold" for="cashReceivedInput">Amount Received <span class="text-danger">*</span></label>
                <div class="col-sm-7">
                    <div class="input-group">
                        <span class="input-group-text">₱</span>
                        <input type="number" step="0.01" min="0.01" name="amount_received" id="cashReceivedInput" class="form-control text-end" value="{{ $balance }}">
                    </div>
                    <div class="form-text">The cash the guest hands over.</div>
                </div>
            </div>
            <div class="row g-2 align-items-center mb-2">
                <label class="col-sm-5 col-form-label fw-semibold" for="amountPaidInput">Amount Applied to Total Due <span class="text-danger">*</span></label>
                <div class="col-sm-7">
                    <div class="input-group">
                        <span class="input-group-text">₱</span>
                        <input type="number" step="0.01" min="{{ $balance }}" max="{{ $balance }}" name="amount_paid" id="amountPaidInput" class="form-control text-end" value="{{ $balance }}" data-balance="{{ $balance }}" required readonly aria-describedby="amountPaidHelp amountPaidError">
                    </div>
                    <div class="form-text" id="amountPaidHelp">The full <strong>₱{{ number_format($balance, 2) }}</strong> is paid now - partial payment isn't accepted at check-out.</div>
                    <div class="text-danger small d-none mt-1" id="amountPaidError" role="alert"></div>
                </div>
            </div>
            <div class="row g-2 align-items-center mb-3 border-top pt-2" id="changeBalanceRow" role="status">
                <span class="col-sm-5 fw-bold" id="changeBalanceLabel">Change</span>
                <span class="col-sm-7 text-end fw-bold fs-5" id="changeBalanceValue">₱0.00</span>
            </div>
            <div class="row g-2 align-items-center mb-2 d-none" id="referenceNumberGroup">
                <label class="col-sm-5 col-form-label fw-semibold" for="referenceNumberInput">Reference Number <span class="text-danger">*</span></label>
                <div class="col-sm-7">
                    <input type="text" name="reference_number" id="referenceNumberInput" class="form-control" placeholder="GCash reference number">
                </div>
            </div>

        @else
            {{-- Grand Total already fully covered by prior verified payments
                 (e.g. a 100%-tier reservation converted with its payment
                 already verified) - nothing left to collect, so the form
                 submits a 0-amount completion instead of blocking the
                 receptionist with a payment field that can never validate. --}}
            <input type="hidden" name="payment_method" value="cash">
            <input type="hidden" name="amount_paid" value="0">
            @if($excess > 0.004)
                {{-- Paid more than the final bill - only the actual total stays recorded as paid. --}}
                <div class="alert alert-primary mb-0">
                    <p class="mb-2"><i class="fas fa-hand-holding-dollar"></i> The guest paid <strong>₱{{ number_format($excess, 2) }} more</strong> than the final bill of ₱{{ number_format($paymentSummary['grand_total'], 2) }}. Hand it back before completing the check-out.</p>
                    <div class="form-check mb-0">
                        <input class="form-check-input" type="checkbox" name="change_returned" value="1" id="coChangeReturned" required>
                        <label class="form-check-label" for="coChangeReturned">I handed ₱{{ number_format($excess, 2) }} back to the guest</label>
                    </div>
                </div>
            @else
                <div class="alert alert-success mb-0">
                    <i class="fas fa-check-circle"></i> This stay is already fully paid - no additional payment is required. Click "Complete Check-Out" to finish.
                </div>
            @endif
        @endif
    </form>
</div>
<div class="modal-footer">
    <button type="button" class="btn btn-secondary" id="backToBillingBtn">
        <i class="fas fa-arrow-left"></i> Back to Billing
    </button>
    <button type="submit" form="paymentForm" class="btn btn-success" id="completePaymentBtn">
        <i class="fas fa-check"></i> 8. {{ $balance > 0.009 ? 'Confirm Payment & Check-out' : 'Confirm Check-out' }}
    </button>
</div>
