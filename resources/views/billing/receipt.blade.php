@extends('layouts.app')

@section('title', 'Receipt')

@section('content')
<div class="container-fluid py-4">
    <div class="row mb-4">
        <div class="col-12">
            @if($backRoute)
                <a href="{{ $backRoute }}" class="btn btn-sm btn-secondary mb-2">
                    <i class="fas fa-arrow-left"></i> Back
                </a>
            @endif
            <h1 class="mb-0">
                <i class="fas fa-receipt"></i>
                {{-- Reflects the backend's own official_receipt_available
                     rule (billing_status=paid AND booking_status=
                     COMPLETED_BOOKING) - never assumed "Official" just
                     because this page happens to be reachable. --}}
                {{ $paymentSummary['official_receipt_available'] ? 'Official Payment Receipt' : 'Payment Receipt' }}
            </h1>
            @if($billing->booking)
                <p class="text-muted">
                    Booking #{{ $billing->booking->id }} —
                    Guest: {{ $billing->booking->guest_display_name }} —
                    Room: {{ $billing->booking->room->room_number ?? 'N/A' }}
                    ({{ $billing->booking->room->room_name ?? $billing->booking->roomType->name }})
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
                        <td class="text-end text-brand">₱{{ number_format($paymentSummary['grand_total'], 2) }}</td>
                    </tr>
                </table>
            </x-card>

            {{-- Shared with the Receptionist checkout Payment Panel - see
                 resources/views/receptionist/partials/payment-history.blade.php.
                 Fed by the exact same paymentSummary/paymentTransactions
                 arrays (Booking::paymentSummary()/paymentTransactionsPayload(),
                 pure reads - never mints a receipt number just by being
                 viewed), so a guest and a receptionist looking at the same
                 booking always see identical figures/history. Contains no
                 staff-only field (no verifier name, no rejection reason) -
                 safe for this guest-reachable route. --}}
            <x-card title="Payment History" icon="fas fa-history" variant="info" bodyClass="p-3">
                @include('receptionist.partials.payment-history', [
                    'paymentSummary' => $paymentSummary,
                    'paymentTransactions' => $paymentTransactions,
                ])
            </x-card>
        </div>

        <div class="col-lg-4">
            <x-card title="Balance" icon="fas fa-wallet" bodyClass="card-body text-center">
                <h2 class="mb-0" style="color: {{ $paymentSummary['remaining_balance'] > 0 ? 'var(--danger-color)' : 'var(--success-color)' }};">
                    ₱{{ number_format($paymentSummary['remaining_balance'], 2) }}
                </h2>
                @if($paymentSummary['remaining_balance'] <= 0)
                    <p class="text-success mb-0 mt-2"><i class="fas fa-check-circle"></i> Fully paid</p>
                @else
                    <p class="text-muted mb-0 mt-2">Remaining balance</p>
                @endif
            </x-card>
        </div>
    </div>
</div>
@endsection
