@extends('layouts.app')

@section('title', 'Receipt - Receptionist')

@section('content')
<div class="container-fluid py-4">
    <div class="row mb-4">
        <div class="col-12">
            @if($billing->booking && $billing->booking->reservation)
                <a href="{{ route('receptionist.reservations.show', $billing->booking->reservation) }}" class="btn btn-sm btn-secondary mb-2">
                    <i class="fas fa-arrow-left"></i> Back to Reservation
                </a>
            @endif
            <h1 class="mb-0">
                <i class="fas fa-receipt"></i> Receipt
            </h1>
            @if($billing->booking && $billing->booking->reservation)
                <p class="text-muted">
                    Reservation #{{ $billing->booking->reservation->id }} —
                    Guest: {{ $billing->booking->reservation->guest->user->full_name ?? $billing->booking->reservation->guest->user->name ?? 'N/A' }} —
                    Room: {{ $billing->booking->reservation->room->room_number ?? 'N/A' }}
                    ({{ $billing->booking->reservation->room->room_name ?? 'N/A' }})
                </p>
            @endif
        </div>
    </div>

    <div class="row">
        <div class="col-lg-8">
            <div class="card border-0 shadow-sm mb-4">
                <div class="card-header d-flex justify-content-between align-items-center" style="background-color: #C1121F; color: white;">
                    <h5 class="mb-0"><i class="fas fa-calculator"></i> Charges</h5>
                    <span class="badge bg-{{ $billing->billing_status === 'paid' ? 'success' : 'warning' }} fs-6">
                        {{ ucfirst($billing->billing_status) }}
                    </span>
                </div>
                <div class="card-body">
                    <table class="table table-borderless mb-0">
                        <tr>
                            <td>Room Charge</td>
                            <td class="text-end">₱{{ number_format($billing->room_charge, 2) }}</td>
                        </tr>
                        <tr>
                            <td>Additional Guest Fee</td>
                            <td class="text-end">₱{{ number_format($billing->additional_guest_fee, 2) }}</td>
                        </tr>
                        <tr>
                            <td>Amenity Charges</td>
                            <td class="text-end">₱{{ number_format($billing->amenity_charge, 2) }}</td>
                        </tr>
                        @foreach($billing->additionalCharges as $charge)
                            <tr>
                                <td>{{ $charge->category_label }} — {{ $charge->description }}</td>
                                <td class="text-end">₱{{ number_format($charge->amount, 2) }}</td>
                            </tr>
                        @endforeach
                        <tr>
                            <td>Discount</td>
                            <td class="text-end text-success">-₱{{ number_format($billing->discount, 2) }}</td>
                        </tr>
                        <tr class="fw-bold fs-5">
                            <td>Total</td>
                            <td class="text-end" style="color: #C1121F;">₱{{ number_format($billing->running_total, 2) }}</td>
                        </tr>
                    </table>
                </div>
            </div>

            <div class="card border-0 shadow-sm">
                <div class="card-header" style="background-color: #17a2b8; color: white;">
                    <h5 class="mb-0"><i class="fas fa-history"></i> Payment History</h5>
                </div>
                <div class="table-responsive">
                    <table class="table table-hover mb-0">
                        <thead>
                            <tr>
                                <th>Date</th>
                                <th>Method</th>
                                <th>Reference</th>
                                <th class="text-end">Amount</th>
                            </tr>
                        </thead>
                        <tbody>
                            @forelse($billing->payments as $payment)
                                <tr>
                                    <td>{{ $payment->payment_date->format('M d, Y h:i A') }}</td>
                                    <td>{{ ucfirst($payment->payment_method) }}</td>
                                    <td>{{ $payment->reference_number ?? '—' }}</td>
                                    <td class="text-end">₱{{ number_format($payment->amount_paid, 2) }}</td>
                                </tr>
                            @empty
                                <tr><td colspan="4" class="text-center text-muted py-4">No payments recorded.</td></tr>
                            @endforelse
                            @if($billing->payments->count() > 0)
                                <tr class="table-light">
                                    <td colspan="3"><strong>Total Paid</strong></td>
                                    <td class="text-end"><strong>₱{{ number_format($amountPaid, 2) }}</strong></td>
                                </tr>
                            @endif
                        </tbody>
                    </table>
                </div>
            </div>
        </div>

        <div class="col-lg-4">
            <div class="card border-0 shadow-sm">
                <div class="card-header" style="background-color: #C1121F; color: white;">
                    <h5 class="mb-0"><i class="fas fa-wallet"></i> Balance</h5>
                </div>
                <div class="card-body text-center">
                    <h2 class="mb-0" style="color: {{ $balance > 0 ? '#dc3545' : '#28a745' }};">
                        ₱{{ number_format($balance, 2) }}
                    </h2>
                    @if($balance <= 0)
                        <p class="text-success mb-0 mt-2"><i class="fas fa-check-circle"></i> Fully paid</p>
                    @else
                        <p class="text-muted mb-0 mt-2">Remaining balance</p>
                    @endif
                </div>
            </div>
        </div>
    </div>
</div>
@endsection
