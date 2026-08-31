@extends('layouts.app')

@section('title', 'Book Room - Guest')

@push('styles')
<style>
    .payment-choice-card {
        border: 2px solid var(--border-color);
        border-radius: var(--radius-card);
        padding: 1rem;
        cursor: pointer;
        height: 100%;
        transition: border-color 0.2s ease, background-color 0.2s ease;
    }
    .payment-choice-card input:checked ~ .payment-choice-label,
    .payment-choice-card.active {
        border-color: var(--primary-color);
        background: rgba(193, 18, 31, 0.04);
    }
    .payment-choice-card:has(input:checked) {
        border-color: var(--primary-color);
        background: rgba(193, 18, 31, 0.04);
    }
    .stay-summary-bar {
        display: flex;
        flex-wrap: wrap;
        gap: 1.5rem;
        align-items: center;
        background: linear-gradient(135deg, rgba(193, 18, 31, 0.06), rgba(193, 18, 31, 0.02));
        border: 1px solid rgba(193, 18, 31, 0.15);
        border-radius: var(--radius-card);
        padding: 1.25rem 1.5rem;
        margin-bottom: 1.5rem;
    }
    .stay-summary-item .stay-summary-label {
        font-size: 0.75rem;
        font-weight: 700;
        text-transform: uppercase;
        letter-spacing: 0.04em;
        color: var(--text-light);
        display: block;
        margin-bottom: 0.15rem;
    }
    .stay-summary-item .stay-summary-value {
        font-size: 1.05rem;
        font-weight: 600;
        color: var(--text-dark);
    }
</style>
@endpush

@section('content')
<div class="container-fluid py-4">
    <x-page-header icon="fas fa-calendar-alt" title="Request a Reservation"
        subtitle="Review your stay, choose how you'd like to pay, and submit your request." />

    @if (session('error'))
        <div class="alert alert-danger alert-dismissible fade show" role="alert">
            <i class="fas fa-exclamation-circle"></i> {{ session('error') }}
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
    @endif

    <!-- Trip Summary -->
    <div class="stay-summary-bar">
        <div class="stay-summary-item">
            <span class="stay-summary-label"><i class="fas fa-door-open"></i> Room Type</span>
            <span class="stay-summary-value">{{ $roomType->name }}</span>
        </div>
        <div class="stay-summary-item">
            <span class="stay-summary-label"><i class="fas fa-right-to-bracket"></i> Check-In</span>
            <span class="stay-summary-value">{{ $checkIn->format('M d, Y') }}</span>
        </div>
        <div class="stay-summary-item">
            <span class="stay-summary-label"><i class="fas fa-right-from-bracket"></i> Check-Out</span>
            <span class="stay-summary-value">{{ $checkOut->format('M d, Y') }}</span>
        </div>
        <div class="stay-summary-item">
            <span class="stay-summary-label"><i class="fas fa-moon"></i> Nights</span>
            <span class="stay-summary-value">{{ $nights }}</span>
        </div>
        <div class="stay-summary-item">
            <span class="stay-summary-label"><i class="fas fa-tag"></i> Rate</span>
            <span class="stay-summary-value">₱{{ number_format($roomType->rate, 2) }}/night</span>
        </div>
    </div>

    <div class="row">
        <div class="col-lg-8">
            <x-card title="Room Type" icon="fas fa-door-open" bodyClass="card-body">
                <h3 class="mb-2">{{ $roomType->name }} Room</h3>
                <p class="mb-3"><i class="fas fa-users text-brand"></i> Up to {{ $roomType->capacity }} guests</p>
                <p class="{{ $roomType->amenities ? 'mb-3' : 'mb-0' }}">{{ $roomType->description ?: 'A comfortable room for your stay.' }}</p>
                @if($roomType->amenities)
                    <hr class="mt-0">
                    <h6 class="mb-2"><i class="fas fa-concierge-bell text-brand me-1"></i> Amenities</h6>
                    <div class="d-flex flex-wrap gap-2 mb-0">
                        @foreach($roomType->amenities as $amenity)
                            <span class="badge bg-light text-dark border">
                                {{ $amenity['name'] }}
                                @if($amenity['pricing_type'] === 'paid')
                                    (+₱{{ number_format($amenity['fee'], 2) }})
                                @else
                                    (Included)
                                @endif
                            </span>
                        @endforeach
                    </div>
                @endif
            </x-card>

            @if($isFullyBooked)
                <div class="alert alert-danger mt-4">
                    <i class="fas fa-exclamation-circle"></i> This room type is fully booked for the selected
                    dates. You may still submit a request, but our staff may not be able to convert it to a
                    booking unless a room becomes available.
                </div>
            @endif

            <x-card title="Reservation Request" icon="fas fa-clipboard-list" bodyClass="card-body" class="mt-4">
                <div class="alert alert-info">
                    <i class="fas fa-info-circle"></i>
                    You are requesting a <strong>{{ $roomType->name }}</strong> room type.
                    A specific room number is assigned by our staff when you check in.
                </div>
                <form action="{{ route('guest.reservations.store') }}" method="POST" enctype="multipart/form-data">
                    @csrf

                    <input type="hidden" name="room_type_id" value="{{ $roomType->id }}">
                    <input type="hidden" name="check_in" value="{{ $checkIn->format('Y-m-d H:i:s') }}">
                    <input type="hidden" name="check_out" value="{{ $checkOut->format('Y-m-d H:i:s') }}">

                    <div class="detail-section">
                        <div class="detail-section-title"><i class="fas fa-bed"></i> Rooms &amp; Guests</div>

                        <div class="row">
                            <div class="col-md-6">
                                <div class="form-group mb-3">
                                    <label for="rooms_requested" class="form-label">Number of Rooms *</label>
                                    <input type="number" class="form-control @error('rooms_requested') is-invalid @enderror"
                                           id="rooms_requested" name="rooms_requested" min="1" max="{{ $roomsAvailable }}"
                                           value="{{ old('rooms_requested', 1) }}" onchange="updatePriceSummary()" required>
                                    @error('rooms_requested')
                                        <div class="invalid-feedback">{{ $message }}</div>
                                    @enderror
                                    <small class="text-muted">Need more than one {{ $roomType->name }} room? Request them together here - up to {{ $roomsAvailable }} currently free for these dates.</small>
                                </div>
                            </div>
                        </div>

                        <div class="row">
                            <div class="col-md-6">
                                <div class="form-group mb-3">
                                    <label for="adults" class="form-label">Adults *</label>
                                    <input type="number" class="form-control @error('adults') is-invalid @enderror"
                                           id="adults" name="adults" min="1" max="{{ $roomType->capacity }}"
                                           value="{{ old('adults', request('guests', 1)) }}" required>
                                    @error('adults')
                                        <div class="invalid-feedback">{{ $message }}</div>
                                    @enderror
                                </div>
                            </div>
                            <div class="col-md-6">
                                <div class="form-group mb-3">
                                    <label for="children" class="form-label">Children <span class="text-muted">(under 12)</span></label>
                                    <input type="number" class="form-control @error('children') is-invalid @enderror"
                                           id="children" name="children" min="0"
                                           value="{{ old('children', 0) }}">
                                    @error('children')
                                        <div class="invalid-feedback">{{ $message }}</div>
                                    @enderror
                                </div>
                            </div>
                        </div>
                        <small class="text-muted d-block mb-0">
                            Room type capacity: {{ $roomType->capacity }} guests. Children under 12 stay free of charge.
                        </small>
                    </div>

                    <div class="detail-section">
                        <div class="detail-section-title"><i class="fas fa-id-card"></i> Discount (optional)</div>
                        <div class="form-check mb-2">
                            <input class="form-check-input" type="checkbox" value="1" id="discount_requested"
                                   name="discount_requested" onchange="document.getElementById('idDocumentGroup').classList.toggle('d-none', !this.checked)"
                                   {{ old('discount_requested') ? 'checked' : '' }}>
                            <label class="form-check-label" for="discount_requested">
                                <strong>I would like to request a discount</strong>
                                <span class="text-muted d-block small">Senior Citizen, PWD, Student, or other applicable discount. Upload a valid ID for verification - our staff will determine eligibility and apply the appropriate discount during billing. This does not reduce your reservation amount now.</span>
                            </label>
                        </div>
                        <div id="idDocumentGroup" class="{{ old('discount_requested') ? '' : 'd-none' }}">
                            <label for="id_document" class="form-label">Valid ID <span class="text-muted">(for discount verification)</span></label>
                            <input type="file" class="form-control @error('id_document') is-invalid @enderror"
                                   id="id_document" name="id_document" accept="image/*">
                            @error('id_document')
                                <div class="invalid-feedback">{{ $message }}</div>
                            @enderror
                        </div>
                    </div>

                    <div class="detail-section">
                        <div class="detail-section-title"><i class="fas fa-credit-card"></i> Payment</div>

                        <div class="form-group mb-4">
                            <div class="row g-2">
                                <div class="col-md-4">
                                    <label class="payment-choice-card d-block mb-0">
                                        <input type="radio" name="payment_choice" value="pay_now_gcash" class="form-check-input me-2"
                                               onchange="updatePaymentPanels()" {{ old('payment_choice', 'pay_later_cash') === 'pay_now_gcash' ? 'checked' : '' }}>
                                        <span class="payment-choice-label"><strong>Pay Now</strong><br><small class="text-muted">via GCash QR</small></span>
                                    </label>
                                </div>
                                <div class="col-md-4">
                                    <label class="payment-choice-card d-block mb-0">
                                        <input type="radio" name="payment_choice" value="pay_later_cash" class="form-check-input me-2"
                                               onchange="updatePaymentPanels()" {{ old('payment_choice', 'pay_later_cash') === 'pay_later_cash' ? 'checked' : '' }}>
                                        <span class="payment-choice-label"><strong>Pay Later</strong><br><small class="text-muted">Cash at check-in</small></span>
                                    </label>
                                </div>
                                <div class="col-md-4">
                                    <label class="payment-choice-card d-block mb-0">
                                        <input type="radio" name="payment_choice" value="pay_later_gcash" class="form-check-input me-2"
                                               onchange="updatePaymentPanels()" {{ old('payment_choice') === 'pay_later_gcash' ? 'checked' : '' }}>
                                        <span class="payment-choice-label"><strong>Pay Later</strong><br><small class="text-muted">GCash before stay</small></span>
                                    </label>
                                </div>
                            </div>
                            @error('payment_choice')
                                <div class="text-danger small mt-2">{{ $message }}</div>
                            @enderror
                        </div>

                        <!-- Cash amount (only for Pay Later + Cash) -->
                        <div class="form-group mb-4" id="cashAmountGroup">
                            <label for="cash_amount" class="form-label">Amount you intend to pay <span class="text-muted">(optional)</span></label>
                            <input type="number" step="0.01" min="{{ $depositRange['min'] }}" max="{{ $depositRange['max'] }}"
                                   class="form-control @error('cash_amount') is-invalid @enderror"
                                   id="cash_amount" name="cash_amount" value="{{ old('cash_amount') }}">
                            <small class="text-muted">If provided, must be between <span id="cashMinLabel">₱{{ number_format($depositRange['min'], 2) }}</span> and <span id="cashMaxLabel">₱{{ number_format($depositRange['max'], 2) }}</span> (20%-50% of the total). The rest is settled at checkout.</small>
                            @error('cash_amount')
                                <div class="invalid-feedback d-block">{{ $message }}</div>
                            @enderror
                        </div>

                        <!-- GCash panel (only for Pay Now + GCash) -->
                        <div class="form-group mb-4 d-none" id="gcashPanel">
                            <div class="card bg-light border-0">
                                <div class="card-body text-center">
                                    <img src="{{ asset('images/gcash-qr.png') }}" alt="GCash QR" class="img-fluid mb-3" style="max-width: 220px;" onerror="this.style.display='none'">
                                    <div class="text-start">
                                        <label class="form-label d-block">How much are you paying now? *</label>
                                        <div class="btn-group w-100 mb-2" role="group" aria-label="Payment amount type">
                                            <input type="radio" class="btn-check" name="payment_type" id="paymentTypePartial" value="partial"
                                                   {{ old('payment_type', 'partial') === 'partial' ? 'checked' : '' }} onchange="updateGcashAmountMode()">
                                            <label class="btn btn-outline-secondary" for="paymentTypePartial">Partial (Deposit)</label>
                                            <input type="radio" class="btn-check" name="payment_type" id="paymentTypeFull" value="full"
                                                   {{ old('payment_type') === 'full' ? 'checked' : '' }} onchange="updateGcashAmountMode()">
                                            <label class="btn btn-outline-secondary" for="paymentTypeFull">Full Payment</label>
                                        </div>
                                        <div id="gcashPercentChips" class="d-flex flex-wrap gap-2 mb-3">
                                            <button type="button" class="btn btn-sm btn-outline-primary" onclick="setGcashPercent(0.20)">20%</button>
                                            <button type="button" class="btn btn-sm btn-outline-primary" onclick="setGcashPercent(0.30)">30%</button>
                                            <button type="button" class="btn btn-sm btn-outline-primary" onclick="setGcashPercent(0.40)">40%</button>
                                            <button type="button" class="btn btn-sm btn-outline-primary" onclick="setGcashPercent(0.50)">50%</button>
                                        </div>

                                        <label for="gcash_amount" class="form-label">Amount *</label>
                                        <input type="number" step="0.01" min="{{ $depositRange['min'] }}" max="{{ $depositRange['max'] }}"
                                               class="form-control mb-1 @error('gcash_amount') is-invalid @enderror"
                                               id="gcash_amount" name="gcash_amount" value="{{ old('gcash_amount', $depositRange['min']) }}">
                                        <small class="text-muted d-block mb-3" id="gcashAmountHint">Between <span id="gcashMinLabel">₱{{ number_format($depositRange['min'], 2) }}</span> and <span id="gcashMaxLabel">₱{{ number_format($depositRange['max'], 2) }}</span> (20%-50% of the total). The rest is settled at checkout.</small>
                                        @error('gcash_amount')
                                            <div class="invalid-feedback d-block mb-3">{{ $message }}</div>
                                        @enderror

                                        <label for="gcash_number" class="form-label">GCash Mobile Number *</label>
                                        <input type="text" class="form-control mb-1 @error('gcash_number') is-invalid @enderror"
                                               id="gcash_number" name="gcash_number" placeholder="9XXXXXXXXX" maxlength="10"
                                               value="{{ old('gcash_number') }}">
                                        <small class="text-muted d-block mb-3">The GCash number the payment was sent from (10 digits, starting with 9).</small>
                                        @error('gcash_number')
                                            <div class="invalid-feedback d-block mb-3">{{ $message }}</div>
                                        @enderror

                                        <label for="gcash_receipt" class="form-label">Payment Receipt *</label>
                                        <input type="file" class="form-control mb-3 @error('gcash_receipt') is-invalid @enderror"
                                               id="gcash_receipt" name="gcash_receipt" accept="image/*">
                                        @error('gcash_receipt')
                                            <div class="invalid-feedback">{{ $message }}</div>
                                        @enderror

                                        <label for="reference_number" class="form-label">Transaction Reference Number *</label>
                                        <input type="text" class="form-control @error('reference_number') is-invalid @enderror"
                                               id="reference_number" name="reference_number" value="{{ old('reference_number') }}">
                                        @error('reference_number')
                                            <div class="invalid-feedback">{{ $message }}</div>
                                        @enderror
                                    </div>
                                </div>
                            </div>
                        </div>

                        <!-- Terms & Conditions (required only when paying now via GCash) -->
                        <div class="form-group mb-0 d-none" id="termsGroup">
                            <div class="form-check">
                                <input class="form-check-input @error('agree_terms') is-invalid @enderror" type="checkbox" value="1"
                                       id="agree_terms" name="agree_terms" {{ old('agree_terms') ? 'checked' : '' }}>
                                <label class="form-check-label" for="agree_terms">
                                    I agree to the <a href="{{ route('public.about') }}#terms" target="_blank">Terms &amp; Conditions</a> and Cancellation Policy. *
                                </label>
                                @error('agree_terms')
                                    <div class="invalid-feedback d-block">{{ $message }}</div>
                                @enderror
                            </div>
                        </div>
                    </div>

                    <button type="submit" class="btn btn-primary btn-lg w-100">
                        <i class="fas fa-check"></i> Submit Reservation Request
                    </button>
                </form>
            </x-card>
        </div>

        <!-- Price Summary -->
        <div class="col-lg-4">
            <x-card title="Price Summary" icon="fas fa-receipt" bodyClass="card-body" class="sticky-lg-top" style="top: 20px;">
                <div class="d-flex justify-content-between mb-2">
                    <span>Room Rate per Night:</span>
                    <strong>₱{{ number_format($roomType->rate, 2) }}</strong>
                </div>
                <div class="d-flex justify-content-between mb-2">
                    <span>Number of Nights:</span>
                    <strong>{{ $nights }}</strong>
                </div>
                <div class="d-flex justify-content-between mb-2">
                    <span>Number of Rooms:</span>
                    <strong id="summaryRoomCount">1</strong>
                </div>
                <hr>
                <div class="d-flex justify-content-between mb-2">
                    <span>Subtotal:</span>
                    <strong id="summarySubtotal">₱{{ number_format($totalRate, 2) }}</strong>
                </div>

                <hr>
                <div class="d-flex justify-content-between align-items-center">
                    <strong>Estimated Total:</strong>
                    <strong class="text-brand" style="font-size: 1.5rem;" id="summaryTotal">₱{{ number_format($finalRate, 2) }}</strong>
                </div>
                <p class="text-muted small mt-2 mb-0">Final amount is confirmed at billing after check-out. Any deposit paid now is deducted from your final bill.</p>
            </x-card>

            @if($applicablePromos->count() > 0)
                <x-card title="Active Promotion" icon="fas fa-gift" variant="warning" bodyClass="card-body" class="mt-3">
                    @foreach($applicablePromos as $promo)
                        <h6>{{ $promo->promo_name }}</h6>
                        <p class="mb-2">
                            <strong>Includes free:</strong>
                            @foreach($promo->amenities as $amenity)
                                {{ $amenity->pivot->quantity }}× {{ $amenity->amenity_name }}@if(!$loop->last), @endif
                            @endforeach
                        </p>
                        <p class="mb-0">
                            <small>{{ $promo->description }}</small>
                        </p>
                        @if(!$loop->last)<hr>@endif
                    @endforeach
                </x-card>
            @endif
        </div>
    </div>
</div>

@push('scripts')
<script>
function updatePaymentPanels() {
    const choice = document.querySelector('input[name="payment_choice"]:checked')?.value;
    const payingNowGcash = choice === 'pay_now_gcash';

    document.getElementById('cashAmountGroup').classList.toggle('d-none', choice !== 'pay_later_cash');
    document.getElementById('gcashPanel').classList.toggle('d-none', !payingNowGcash);

    // Terms & Conditions are only required when a payment is actually being
    // made right now (Pay Now + GCash) - matches the mobile app's Booking-tab-
    // only requirement; a pure hold Reservation (Pay Later) needs no agreement.
    document.getElementById('termsGroup').classList.toggle('d-none', !payingNowGcash);
    document.getElementById('agree_terms').required = payingNowGcash;

    ['gcash_number', 'gcash_receipt', 'reference_number', 'gcash_amount'].forEach((id) => {
        const el = document.getElementById(id);
        if (el) el.required = payingNowGcash;
    });
}
document.addEventListener('DOMContentLoaded', updatePaymentPanels);

// Per-room baseline (qty=1), computed server-side - everything else here
// just scales linearly with the room-quantity selector. perRoomSubtotal
// doubles as the per-room Full-payment amount ($depositRange['total'] here
// is computed for roomsRequested=1, same as $totalRate).
const perRoomSubtotal = {{ $totalRate }};
const perRoomDepositMin = {{ $depositRange['min'] }};
const perRoomDepositMax = {{ $depositRange['max'] }};

function peso(amount) {
    return '₱' + Number(amount).toLocaleString('en-PH', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
}

function currentQty() {
    return Math.max(1, parseInt(document.getElementById('rooms_requested').value, 10) || 1);
}

/**
 * Owns the gcash_amount field/hint/percent-chips for both the Partial and
 * Full payment_type modes - single source of truth so updatePriceSummary()
 * (fired on room-quantity change) never has to duplicate this logic.
 */
function updateGcashAmountMode() {
    const isFull = document.getElementById('paymentTypeFull')?.checked;
    const amountField = document.getElementById('gcash_amount');
    const chips = document.getElementById('gcashPercentChips');
    const hint = document.getElementById('gcashAmountHint');
    const qty = currentQty();
    const total = perRoomSubtotal * qty;

    chips.classList.toggle('d-none', !!isFull);

    if (isFull) {
        amountField.value = total.toFixed(2);
        amountField.readOnly = true;
        amountField.min = total;
        amountField.max = total;
        hint.textContent = 'Full payment: ' + peso(total) + '. Nothing left to settle at checkout.';
    } else {
        const depositMin = perRoomDepositMin * qty;
        const depositMax = perRoomDepositMax * qty;
        amountField.readOnly = false;
        amountField.min = depositMin;
        amountField.max = depositMax;
        if (!amountField.value || parseFloat(amountField.value) > depositMax || parseFloat(amountField.value) < depositMin) {
            amountField.value = depositMin.toFixed(2);
        }
        hint.innerHTML = 'Between <span id="gcashMinLabel">' + peso(depositMin) + '</span> and <span id="gcashMaxLabel">' + peso(depositMax) + '</span> (20%-50% of the total). The rest is settled at checkout.';
    }
}

function setGcashPercent(pct) {
    const total = perRoomSubtotal * currentQty();
    document.getElementById('gcash_amount').value = (total * pct).toFixed(2);
}

function updatePriceSummary() {
    const qty = currentQty();
    const subtotal = perRoomSubtotal * qty;
    const depositMin = perRoomDepositMin * qty;
    const depositMax = perRoomDepositMax * qty;

    document.getElementById('summaryRoomCount').textContent = qty;
    document.getElementById('summarySubtotal').textContent = peso(subtotal);
    document.getElementById('summaryTotal').textContent = peso(subtotal);

    const cashAmount = document.getElementById('cash_amount');
    cashAmount.min = depositMin;
    cashAmount.max = depositMax;
    document.getElementById('cashMinLabel').textContent = peso(depositMin);
    document.getElementById('cashMaxLabel').textContent = peso(depositMax);

    updateGcashAmountMode();
}
document.addEventListener('DOMContentLoaded', updatePriceSummary);
</script>
@endpush
@endsection
