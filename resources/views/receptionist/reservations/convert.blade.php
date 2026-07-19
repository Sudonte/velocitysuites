@extends('layouts.app')

@section('title', 'Convert to Booking - Reservation #' . $reservation->id . ' - Receptionist')

@section('content')
<div class="container-fluid py-4">
    <div class="mb-2">
        <a href="{{ route('receptionist.reservations.show', $reservation) }}" class="btn btn-sm btn-secondary">
            <i class="fas fa-arrow-left"></i> Back to Reservation
        </a>
    </div>

    <x-page-header icon="fas fa-money-bill-wave" title="Convert to Booking"
        subtitle="Collect payment for Reservation #{{ $reservation->id }} to turn it into a Booking." />

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
        <div class="col-lg-6">
            <x-card title="Reservation Summary" bodyClass="card-body" class="mb-4">
                <p class="mb-2"><strong>Guest:</strong> {{ $reservation->guest->user->full_name ?? 'N/A' }}</p>
                <p class="mb-2"><strong>Room Type:</strong> {{ $reservation->roomType->name }}</p>
                <p class="mb-2"><strong>Check-In:</strong> {{ $reservation->check_in->format('M d, Y') }}</p>
                <p class="mb-2"><strong>Check-Out:</strong> {{ $reservation->check_out->format('M d, Y') }}</p>
                <p class="mb-2"><strong>Nights:</strong> {{ $quote['nights'] }}</p>
                <hr>
                <p class="mb-2"><strong>Room Charge:</strong> ₱{{ number_format($quote['room_charge'], 2) }}</p>
                @if($quote['discount'] > 0)
                    <p class="mb-2 text-success"><strong>Discount:</strong> -₱{{ number_format($quote['discount'], 2) }}</p>
                @endif
                <p class="mb-0 fw-bold fs-5">Total: <span class="text-brand">₱{{ number_format($quote['total'], 2) }}</span></p>
            </x-card>
        </div>

        <div class="col-lg-6">
            <x-card title="Record Payment" bodyClass="card-body">
                <form action="{{ route('receptionist.reservations.convert.store', $reservation) }}" method="POST">
                    @csrf

                    <div class="form-group mb-3">
                        <label for="payment_method"><strong>Payment Method *</strong></label>
                        <select class="form-select" id="payment_method" name="payment_method" required>
                            <option value="cash">Cash</option>
                            <option value="gcash">GCash</option>
                        </select>
                    </div>

                    <div class="form-group mb-3">
                        <label for="reference_number"><strong>Reference Number</strong></label>
                        <input type="text" class="form-control" id="reference_number" name="reference_number" placeholder="Required for GCash">
                    </div>

                    <div class="form-group mb-3">
                        <label for="amount_paid"><strong>Amount Paid *</strong></label>
                        <input type="number" step="0.01" min="0.01" class="form-control" id="amount_paid" name="amount_paid"
                               value="{{ number_format($quote['total'], 2, '.', '') }}" required>
                        <small class="text-muted">Full amount pre-filled - reduce for a partial payment.</small>
                    </div>

                    <button type="submit" class="btn btn-success btn-lg w-100">
                        <i class="fas fa-check"></i> Record Payment & Confirm Booking
                    </button>
                </form>
            </x-card>
        </div>
    </div>
</div>
@endsection
