{{--
    Shared Payment Summary + Payment Transaction History block - fed
    entirely by backend-authoritative arrays (Booking::paymentSummary()/
    paymentTransactionsPayload(), which delegate to ReceiptService/
    PaymentMath). Deliberately does not compute any total/balance/status
    itself - every figure here is exactly what was passed in, so this can
    never drift from what the guest-facing API or another receptionist
    screen shows for the same booking (PAYMENT_RECEIPT_HISTORY_BACKEND_SPEC.md
    §17). Covers reservation-originated, direct-booking, and checkout-
    collected payments alike - whatever Booking::allPayments() already
    merged - so a converted reservation's full history (deposit +
    checkout) renders here with no special-casing needed by this view.

    A plain @include()-able partial (not an anonymous <x-... /> component -
    it lives under receptionist/partials/, not resources/views/components/),
    so callers pass variables the normal @include() way:
    @include('receptionist.partials.payment-history', [
        'paymentSummary' => $paymentSummary,
        'paymentTransactions' => $paymentTransactions,
    ])

    Variables:
    - paymentSummary: array|null - Booking::paymentSummary() shape
        (grand_total, total_amount_paid, remaining_balance, payment_status,
        payment_percentage, official_receipt_available)
    - paymentTransactions: array - Booking::paymentTransactionsPayload() shape,
        already in chronological order
--}}
@php
    $paymentSummary = $paymentSummary ?? null;
    $paymentTransactions = $paymentTransactions ?? [];
@endphp

<div class="payment-history-block">
    @if($paymentSummary)
        <div class="card border-0 shadow-sm mb-3">
            <div class="card-body">
                <h6 class="mb-3 text-muted"><i class="fas fa-file-invoice-dollar me-1"></i> Payment Summary</h6>
                <div class="row g-3 text-center">
                    <div class="col-6 col-md-3">
                        <div class="small text-muted">Grand Total</div>
                        <div class="fw-bold fs-5">₱{{ number_format($paymentSummary['grand_total'], 2) }}</div>
                    </div>
                    <div class="col-6 col-md-3">
                        <div class="small text-muted">Total Amount Paid</div>
                        <div class="fw-bold fs-5 text-success">₱{{ number_format($paymentSummary['total_amount_paid'], 2) }}</div>
                    </div>
                    <div class="col-6 col-md-3">
                        <div class="small text-muted">Remaining Balance</div>
                        <div class="fw-bold fs-5 {{ $paymentSummary['remaining_balance'] > 0.009 ? 'text-brand' : 'text-success' }}">
                            ₱{{ number_format($paymentSummary['remaining_balance'], 2) }}
                        </div>
                    </div>
                    <div class="col-6 col-md-3">
                        <div class="small text-muted mb-1">Payment Status</div>
                        <x-status-badge :status="$paymentSummary['payment_status']" domain="booking_payment_status" />
                    </div>
                </div>

                @if(!$paymentSummary['official_receipt_available'] && $paymentSummary['remaining_balance'] <= 0.009 && $paymentSummary['total_amount_paid'] > 0.009)
                    {{-- The exact "billing_status flipped to paid before checkout
                         actually completed" case - see
                         Billing::isOfficialReceiptAvailable()'s own doc. Balance
                         reads ₱0.00 here, but the Official Payment Receipt is
                         intentionally still not available until the receptionist
                         actually finishes checkout below. --}}
                    <div class="alert alert-info mt-3 mb-0 py-2 small">
                        <i class="fas fa-circle-info me-1"></i>
                        Already fully paid, but check-out has not been completed yet - the
                        <strong>Official Payment Receipt</strong> will only become available once check-out is finished.
                    </div>
                @endif
            </div>
        </div>
    @endif

    <h6 class="mb-2 text-muted"><i class="fas fa-clock-rotate-left me-1"></i> Payment History</h6>

    @if(empty($paymentTransactions))
        <p class="text-muted small mb-0">No payments recorded yet.</p>
    @else
        @php
            $transactionTypeLabels = [
                'PARTIAL_PAYMENT' => 'Partial Payment',
                'FULL_PAYMENT' => 'Full Payment',
                'CHECKOUT_PAYMENT' => 'Checkout Payment',
            ];
        @endphp
        <ul class="list-unstyled payment-history-timeline mb-0">
            @foreach($paymentTransactions as $tx)
                <li class="payment-history-item border rounded p-3 mb-2">
                    <div class="d-flex justify-content-between align-items-start flex-wrap gap-2">
                        <div class="text-break" style="min-width: 0;">
                            <div class="fw-semibold">
                                <i class="fas {{ $tx['payment_method'] === 'gcash' ? 'fa-mobile-screen' : 'fa-money-bill-wave' }} me-1"></i>
                                {{ ucfirst($tx['payment_method']) }}
                                &middot;
                                {{ $transactionTypeLabels[$tx['transaction_type']] ?? $tx['transaction_type'] }}
                            </div>
                            <div class="small text-muted">
                                {{ $tx['payment_date'] ? \Carbon\Carbon::parse($tx['payment_date'])->format('F j, Y \a\t g:i A') : '—' }}
                            </div>
                            @if($tx['payment_percentage'])
                                <div class="small">Payment Percentage: <strong>{{ $tx['payment_percentage'] }}%</strong></div>
                            @endif
                            @if($tx['gcash_reference_number'])
                                <div class="small text-break">Reference #: {{ $tx['gcash_reference_number'] }}</div>
                            @elseif($tx['reference_number'])
                                <div class="small text-break">Reference #: {{ $tx['reference_number'] }}</div>
                            @endif
                            @if($tx['receipt_type'] && $tx['receipt_number'])
                                <div class="small mt-1">
                                    <x-status-badge :status="$tx['receipt_type']" domain="receipt_type" />
                                    <span class="text-muted">{{ $tx['receipt_number'] }}</span>
                                </div>
                            @endif
                        </div>
                        <div class="text-end">
                            <div class="fw-bold">₱{{ number_format($tx['amount_paid'], 2) }}</div>
                            {{-- A guest-submitted GCash payment shows its
                                 verification_status ("Verified"/"Pending
                                 Verification"/"Rejected") - more specific
                                 than the raw payment_status; a
                                 receptionist-recorded checkout payment has
                                 no verification concept at all (always
                                 null - see Payment::getVerificationStatusAttribute()),
                                 so it falls back to the plain payment_status
                                 badge ("Completed"). --}}
                            @if($tx['verification_status'])
                                <x-status-badge :status="$tx['verification_status']" domain="verification_status" />
                            @else
                                <x-status-badge :status="$tx['payment_status']" domain="payment" />
                            @endif
                        </div>
                    </div>
                </li>
            @endforeach
        </ul>
    @endif
</div>
