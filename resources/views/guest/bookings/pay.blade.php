@extends('layouts.app')

@section('title', 'Pay for Reservation - Guest')

@section('content')
<div class="container-fluid py-4">
    <x-page-header icon="fas fa-credit-card" title="Pay for Reservation #{{ $reservation->id }}" />

    @if ($errors->any())
        <div class="alert alert-danger">
            <ul class="mb-0">
                @foreach ($errors->all() as $error)
                    <li>{{ $error }}</li>
                @endforeach
            </ul>
        </div>
    @endif

    <div class="row">
        <div class="col-lg-8">
            <x-card title="Reservation Summary" bodyClass="card-body" class="mb-4">
                <p class="mb-2"><strong>Room Type:</strong> {{ $reservation->roomType->name }}</p>
                <p class="mb-2"><strong>Check-In:</strong> {{ $reservation->check_in->format('F d, Y') }}</p>
                <p class="mb-2"><strong>Check-Out:</strong> {{ $reservation->check_out->format('F d, Y') }}</p>
                <p class="mb-0"><strong>Nights:</strong> {{ $quote['nights'] }}</p>
            </x-card>

            <x-card title="Payment" bodyClass="card-body">
                <div class="alert alert-info">
                    <i class="fas fa-info-circle"></i>
                    You're paying online with a manually-entered reference number, so this payment stays
                    <strong>pending verification</strong> until our staff confirms it - your booking is
                    confirmed once verified, not the moment you submit.
                </div>
                <form action="{{ route('guest.reservations.pay.store', $reservation) }}" method="POST">
                    @csrf

                    <div class="form-group mb-3">
                        <label for="amount_paid"><strong>Payment Amount *</strong></label>
                        <div class="input-group">
                            <span class="input-group-text">₱</span>
                            <input type="number" step="0.01" class="form-control @error('amount_paid') is-invalid @enderror"
                                   id="amount_paid" name="amount_paid"
                                   min="{{ number_format($minimumPayment, 2, '.', '') }}"
                                   max="{{ number_format($quote['total'], 2, '.', '') }}"
                                   value="{{ old('amount_paid', number_format($quote['total'], 2, '.', '')) }}" required>
                            @error('amount_paid')<div class="invalid-feedback">{{ $message }}</div>@enderror
                        </div>
                        <small class="text-muted">
                            Pay anywhere from ₱{{ number_format($minimumPayment, 2) }} (minimum) up to ₱{{ number_format($quote['total'], 2) }} (full amount).
                        </small>
                    </div>

                    <div class="row">
                        <div class="col-md-6">
                            <div class="form-group mb-3">
                                <label for="payment_method"><strong>Payment Method *</strong></label>
                                <select class="form-select @error('payment_method') is-invalid @enderror" id="payment_method" name="payment_method" required>
                                    <option value="gcash" selected>GCash</option>
                                    <option value="cash">Cash (pay at front desk)</option>
                                </select>
                                @error('payment_method')<div class="invalid-feedback">{{ $message }}</div>@enderror
                            </div>
                        </div>
                        <div class="col-md-6">
                            <div class="form-group mb-3">
                                <label for="reference_number"><strong>GCash Reference Number</strong></label>
                                <input type="text" class="form-control @error('reference_number') is-invalid @enderror"
                                       id="reference_number" name="reference_number" value="{{ old('reference_number') }}"
                                       placeholder="Required for GCash">
                                @error('reference_number')<div class="invalid-feedback">{{ $message }}</div>@enderror
                            </div>
                        </div>
                    </div>

                    <button type="submit" class="btn btn-primary btn-lg w-100">
                        <i class="fas fa-check"></i> Submit Payment
                    </button>
                </form>
            </x-card>
        </div>

        <div class="col-lg-4">
            <x-card title="Price Summary" bodyClass="card-body">
                <div class="d-flex justify-content-between mb-2">
                    <span>Room Charge:</span>
                    <strong>₱{{ number_format($quote['room_charge'], 2) }}</strong>
                </div>
                @if($quote['discount'] > 0)
                    <div class="d-flex justify-content-between mb-2 text-success">
                        <span>Discount:</span>
                        <strong>-₱{{ number_format($quote['discount'], 2) }}</strong>
                    </div>
                @endif
                <hr>
                <div class="d-flex justify-content-between">
                    <strong>Total:</strong>
                    <strong class="text-brand" style="font-size: 1.25rem;">₱{{ number_format($quote['total'], 2) }}</strong>
                </div>
            </x-card>
        </div>
    </div>
</div>
@endsection
