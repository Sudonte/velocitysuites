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
    }
    .payment-choice-card input:checked ~ .payment-choice-label,
    .payment-choice-card.active {
        border-color: var(--primary-color);
        background: rgba(193, 18, 31, 0.04);
    }
</style>
@endpush

@section('content')
<div class="container-fluid py-4">
    <x-page-header icon="fas fa-calendar-alt" title="Request a Reservation" />

    <div class="row">
        <!-- Room Type Details -->
        <div class="col-lg-8">
            <x-card title="Room Type" bodyClass="card-body">
                <h3 class="mb-3">{{ $roomType->name }} Room</h3>
                <p class="mb-2">
                    <strong>Capacity:</strong> Up to {{ $roomType->capacity }} guests
                </p>
                <p class="mb-2">
                    <strong>Rate:</strong> ₱{{ number_format($roomType->rate, 2) }} per night
                </p>
                <p class="mb-0">
                    <strong>Description:</strong><br>
                    {{ $roomType->description ?: 'A comfortable room for your stay.' }}
                </p>
            </x-card>

            @if($isFullyBooked)
                <div class="alert alert-danger mt-4">
                    <i class="fas fa-exclamation-circle"></i> This room type is fully booked for the selected
                    dates. You may still submit a request, but our staff may not be able to convert it to a
                    booking unless a room becomes available.
                </div>
            @endif

            <!-- Reservation Details -->
            <x-card title="Reservation Details" bodyClass="card-body" class="mt-4">
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

                    <div class="row">
                        <div class="col-md-6">
                            <div class="form-group mb-3">
                                <label><strong>Check-In</strong></label>
                                <p>{{ $checkIn->format('F d, Y') }}</p>
                            </div>
                        </div>
                        <div class="col-md-6">
                            <div class="form-group mb-3">
                                <label><strong>Check-Out</strong></label>
                                <p>{{ $checkOut->format('F d, Y') }}</p>
                            </div>
                        </div>
                    </div>

                    <div class="row">
                        <div class="col-md-6">
                            <div class="form-group mb-3">
                                <label for="adults"><strong>Adults *</strong></label>
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
                                <label for="children"><strong>Children <span class="text-muted">(under 12)</span></strong></label>
                                <input type="number" class="form-control @error('children') is-invalid @enderror"
                                       id="children" name="children" min="0"
                                       value="{{ old('children', 0) }}">
                                @error('children')
                                    <div class="invalid-feedback">{{ $message }}</div>
                                @enderror
                            </div>
                        </div>
                    </div>
                    <p class="text-muted small mb-4">
                        Room type capacity: {{ $roomType->capacity }} guests. Children under 12 stay free of charge.
                    </p>

                    <!-- Discount Request -->
                    <div class="form-group mb-4">
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

                    <!-- Payment Choice -->
                    <div class="form-group mb-4">
                        <label class="form-label"><strong>Payment *</strong></label>
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
                        <label for="cash_amount">Amount you intend to pay <span class="text-muted">(optional)</span></label>
                        <input type="number" step="0.01" min="0" class="form-control @error('cash_amount') is-invalid @enderror"
                               id="cash_amount" name="cash_amount" value="{{ old('cash_amount') }}">
                        @error('cash_amount')
                            <div class="invalid-feedback">{{ $message }}</div>
                        @enderror
                    </div>

                    <!-- GCash panel (only for Pay Now + GCash) -->
                    <div class="form-group mb-4 d-none" id="gcashPanel">
                        <div class="card bg-light">
                            <div class="card-body text-center">
                                <img src="{{ asset('images/gcash-qr.png') }}" alt="GCash QR" class="img-fluid mb-3" style="max-width: 220px;" onerror="this.style.display='none'">
                                <div class="text-start">
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

                    <button type="submit" class="btn btn-primary btn-lg w-100">
                        <i class="fas fa-check"></i> Submit Reservation Request
                    </button>
                </form>
            </x-card>
        </div>

        <!-- Price Summary -->
        <div class="col-lg-4">
            <x-card title="Price Summary" bodyClass="card-body" class="sticky-top" style="top: 20px;">
                <div class="d-flex justify-content-between mb-2">
                    <span>Room Rate per Night:</span>
                    <strong>₱{{ number_format($roomType->rate, 2) }}</strong>
                </div>
                <div class="d-flex justify-content-between mb-2">
                    <span>Number of Nights:</span>
                    <strong>{{ $nights }}</strong>
                </div>
                <hr>
                <div class="d-flex justify-content-between mb-2">
                    <span>Subtotal:</span>
                    <strong>₱{{ number_format($totalRate, 2) }}</strong>
                </div>

                @if($discount > 0)
                    <div class="d-flex justify-content-between mb-2 text-success">
                        <span>Promotion Discount:</span>
                        <strong>-₱{{ number_format($discount, 2) }}</strong>
                    </div>
                @endif

                <hr>
                <div class="d-flex justify-content-between">
                    <strong>Estimated Total:</strong>
                    <strong class="text-brand" style="font-size: 1.5rem;">₱{{ number_format($finalRate, 2) }}</strong>
                </div>
                <p class="text-muted small mt-2 mb-0">Final amount is confirmed at billing after check-out. Any deposit paid now is deducted from your final bill.</p>
            </x-card>

            @if($applicablePromos->count() > 0)
                <x-card title="Active Promotion" variant="warning" bodyClass="card-body" class="mt-3">
                    @foreach($applicablePromos as $promo)
                        <h6>{{ $promo->promo_name }}</h6>
                        @if($promo->promo_type === 'amenity')
                            <p class="mb-2">
                                <strong>Includes free:</strong>
                                @foreach($promo->amenities as $amenity)
                                    {{ $amenity->pivot->quantity }}× {{ $amenity->amenity_name }}@if(!$loop->last), @endif
                                @endforeach
                            </p>
                        @else
                            <p class="mb-2">
                                <strong>Discount:</strong>
                                @if($promo->discount_type === 'percentage')
                                    {{ $promo->discount_value }}%
                                @else
                                    ₱{{ number_format($promo->discount_value, 2) }}
                                @endif
                            </p>
                        @endif
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
    document.getElementById('cashAmountGroup').classList.toggle('d-none', choice !== 'pay_later_cash');
    document.getElementById('gcashPanel').classList.toggle('d-none', choice !== 'pay_now_gcash');
}
document.addEventListener('DOMContentLoaded', updatePaymentPanels);
</script>
@endpush
@endsection
