@extends('layouts.app')

@section('title', 'Receipt')

@section('content')
<div class="container-fluid py-4">
    <div class="row mb-4">
        <div class="col-12">
            @if($backRoute)
                <a href="{{ $backRoute }}" class="btn btn-sm btn-secondary mb-2">
                    <i class="fas fa-arrow-left"></i> Back to Reservation
                </a>
            @endif
            <h1 class="mb-0">
                <i class="fas fa-receipt"></i> Receipt
            </h1>
            @if($billing->booking && $billing->booking->reservation)
                <p class="text-muted">
                    Reservation #{{ $billing->booking->reservation->id }} —
                    Guest: {{ $billing->booking->reservation->guest->user->full_name ?? 'N/A' }} —
                    Room: {{ $billing->booking->reservation->room->room_number ?? 'To be assigned' }}
                    ({{ $billing->booking->reservation->room->room_name ?? $billing->booking->reservation->roomType->name }})
                </p>
            @endif
            @if($billing->billing_status !== 'paid' && $billing->payments->where('payment_status', 'pending')->isNotEmpty())
                <div class="alert alert-warning d-inline-block">
                    <i class="fas fa-clock"></i> This payment is awaiting staff verification. Your booking will be confirmed once verified.
                </div>
            @endif
        </div>
    </div>

    <div class="row">
        <div class="col-lg-8">
            <x-card bodyClass="card-body" class="mb-4">
                <x-slot:title>Charges</x-slot:title>
                <x-slot:actions>
                    <x-status-badge :status="$billing->billing_status" domain="billing" class="fs-6" />
                </x-slot:actions>
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
                        <td class="text-end text-brand">₱{{ number_format($billing->running_total, 2) }}</td>
                    </tr>
                </table>
            </x-card>

            <x-card title="Payment History" icon="fas fa-history" variant="info" bodyClass="table-responsive">
                <table class="table table-hover mb-0">
                    <thead>
                        <tr>
                            <th>Date</th>
                            <th>Method</th>
                            <th>Reference</th>
                            <th>Status</th>
                            <th class="text-end">Amount</th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse($billing->payments as $payment)
                            <tr>
                                <td>{{ $payment->payment_date->format('M d, Y h:i A') }}</td>
                                <td>{{ ucfirst($payment->payment_method) }}</td>
                                <td>{{ $payment->reference_number ?? '—' }}</td>
                                <td><x-status-badge :status="$payment->payment_status" domain="payment" /></td>
                                <td class="text-end">₱{{ number_format($payment->amount_paid, 2) }}</td>
                            </tr>
                        @empty
                            <tr><td colspan="5"><x-empty-state icon="fas fa-history" message="No payments recorded." /></td></tr>
                        @endforelse
                        @if($billing->payments->count() > 0)
                            <tr class="table-light">
                                <td colspan="4"><strong>Total Verified Paid</strong></td>
                                <td class="text-end"><strong>₱{{ number_format($amountPaid, 2) }}</strong></td>
                            </tr>
                        @endif
                    </tbody>
                </table>
            </x-card>
        </div>

        <div class="col-lg-4">
            <x-card title="Balance" icon="fas fa-wallet" bodyClass="card-body text-center">
                <h2 class="mb-0" style="color: {{ $balance > 0 ? 'var(--danger-color)' : 'var(--success-color)' }};">
                    ₱{{ number_format($balance, 2) }}
                </h2>
                @if($balance <= 0)
                    <p class="text-success mb-0 mt-2"><i class="fas fa-check-circle"></i> Fully paid</p>
                @else
                    <p class="text-muted mb-0 mt-2">Remaining balance</p>
                @endif
            </x-card>
        </div>
    </div>
</div>
@endsection
