@extends('layouts.app')

@section('title', 'Booking Details - Monitoring')

@section('content')
<div class="container-fluid py-4">
    <div class="d-flex justify-content-between align-items-start flex-wrap gap-2 mb-4">
        <div>
            <a href="{{ route($backRoute) }}" class="btn btn-sm btn-secondary mb-2">
                <i class="fas fa-arrow-left"></i> Back to Monitoring
            </a>
            <h1 class="mb-0">
                <i class="fas fa-calendar-check"></i> Booking #{{ $booking->id }}
            </h1>
        </div>
        <div>
            <span class="badge bg-primary fs-6"><i class="fas fa-credit-card"></i> Booking</span>
            <x-status-badge :status="$booking->booking_status" domain="booking" class="fs-6" />
        </div>
    </div>

    <div class="row">
        <div class="col-lg-8">
            <x-card title="Booking Details" icon="fas fa-info-circle" bodyClass="card-body" class="mb-4">
                <div class="row mb-2">
                    <div class="col-md-4"><strong>Status:</strong></div>
                    <div class="col-md-8"><x-status-badge :status="$booking->booking_status" domain="booking" /></div>
                </div>
                <div class="row mb-2">
                    <div class="col-md-4"><strong>Guest:</strong></div>
                    <div class="col-md-8">
                        {{ $booking->guest_display_name }} ({{ $booking->account_guest?->user?->email ?? '' }})
                        @if($booking->account_guest_full_name && $booking->account_guest_full_name !== $booking->stay_guest_full_name)
                            <br><small class="text-muted">Account: {{ $booking->account_guest_full_name }}</small>
                        @endif
                    </div>
                </div>
                <div class="row mb-2">
                    <div class="col-md-4"><strong>Room Type:</strong></div>
                    <div class="col-md-8">
                        {{ $booking->roomType->name ?? 'N/A' }}
                        @if($booking->rooms_requested > 1)
                            <span class="badge bg-secondary">&times;{{ $booking->rooms_requested }} rooms</span>
                        @endif
                    </div>
                </div>
                <div class="row mb-2">
                    <div class="col-md-4"><strong>Room:</strong></div>
                    <div class="col-md-8">
                        @if($booking->room)
                            {{ $booking->room->room_number }} - {{ $booking->room->room_name }}
                        @else
                            <span class="text-muted">Not yet assigned</span>
                        @endif
                    </div>
                </div>
                <div class="row mb-2">
                    <div class="col-md-4"><strong>Check-In:</strong></div>
                    <div class="col-md-8">{{ $booking->check_in->format('M d, Y') }}</div>
                </div>
                <div class="row mb-2">
                    <div class="col-md-4"><strong>Check-Out:</strong></div>
                    <div class="col-md-8">{{ $booking->check_out->format('M d, Y') }}</div>
                </div>
                <div class="row mb-2">
                    <div class="col-md-4"><strong>Nights:</strong></div>
                    <div class="col-md-8">{{ $booking->number_of_nights }}</div>
                </div>
                <div class="row mb-2">
                    <div class="col-md-4"><strong>Guests:</strong></div>
                    <div class="col-md-8">{{ $booking->adults }} adult{{ $booking->adults == 1 ? '' : 's' }}@if($booking->children > 0), {{ $booking->children }} child{{ $booking->children == 1 ? '' : 'ren' }}@endif</div>
                </div>
                <div class="row mb-0">
                    <div class="col-md-4"><strong>Confirmed At:</strong></div>
                    <div class="col-md-8">{{ $booking->confirmed_at?->format('M d, Y h:i A') ?? 'N/A' }}</div>
                </div>
            </x-card>

            @if($booking->discount_requested)
                <x-card title="Senior Citizen / PWD Identification" icon="fas fa-id-card" bodyClass="card-body" class="mb-4">
                    <p class="mb-2">
                        <strong>Status:</strong>
                        <x-status-badge :status="$booking->discount_verification_status" domain="discount_verification" />
                    </p>
                    @if($booking->id_card_image_path)
                        <p class="text-muted mb-0">
                            {{ $booking->id_card_type ? "{$booking->id_card_type} ID uploaded." : 'ID uploaded.' }}
                            Full document viewing is available in the Receptionist Bookings module.
                        </p>
                    @else
                        <p class="text-muted mb-0">Requested but no ID uploaded.</p>
                    @endif
                </x-card>
            @endif

            @if($gcashPayments->isNotEmpty())
                <x-card title="GCash Payment Details" icon="fas fa-qrcode" bodyClass="card-body" class="mb-4">
                    @foreach($gcashPayments as $payment)
                        <div class="gcash-payment-entry {{ !$loop->last ? 'mb-4 pb-4 border-bottom' : '' }}">
                            <div class="d-flex justify-content-between align-items-center mb-2 flex-wrap gap-2">
                                <span class="badge bg-light text-dark border">Payment</span>
                                @if($payment->isVerified())
                                    <span class="badge bg-success">Verified</span>
                                @elseif($payment->isRejected())
                                    <span class="badge bg-danger">Rejected</span>
                                @else
                                    <span class="badge bg-warning text-dark">Pending Verification</span>
                                @endif
                            </div>
                            <div class="row">
                                <div class="col-md-6">
                                    <p class="mb-1"><strong>Registered GCash Number:</strong></p>
                                    <p class="mb-3">{{ $payment->gcash_number ?: 'Not provided' }}</p>

                                    <p class="mb-1"><strong>Reference Number:</strong></p>
                                    <p class="mb-3">{{ $payment->reference_number ?: 'Not provided' }}</p>

                                    <p class="mb-1"><strong>Amount Paid:</strong></p>
                                    <p class="mb-0">₱{{ number_format($payment->amount_paid, 2) }}</p>
                                </div>
                                <div class="col-md-6">
                                    <p class="mb-1"><strong>Uploaded Receipt:</strong></p>
                                    @if($payment->receipt_path)
                                        <a href="{{ $payment->receipt_url }}" target="_blank" rel="noopener">
                                            <img src="{{ $payment->receipt_url }}" alt="GCash Payment Receipt" class="img-thumbnail" style="max-height: 220px;">
                                            <small class="d-block text-muted mt-1"><i class="fas fa-expand"></i> Click to open full size</small>
                                        </a>
                                    @else
                                        <p class="text-muted">No receipt uploaded.</p>
                                    @endif
                                </div>
                            </div>
                        </div>
                    @endforeach
                </x-card>
            @endif

            <x-card title="Payment Summary" icon="fas fa-receipt" bodyClass="card-body">
                @php $latestPayment = $booking->allPayments()->sortByDesc('created_at')->first(); @endphp
                @if($latestPayment)
                    <div class="row mb-2">
                        <div class="col-md-4"><strong>Method:</strong></div>
                        <div class="col-md-8">{{ $latestPayment->payment_method === 'gcash' ? 'GCash' : 'Cash' }}</div>
                    </div>
                    <div class="row mb-2">
                        <div class="col-md-4"><strong>Amount Paid:</strong></div>
                        <div class="col-md-8">₱{{ number_format($latestPayment->amount_paid, 2) }}</div>
                    </div>
                    <div class="row mb-0">
                        <div class="col-md-4"><strong>Payment Status:</strong></div>
                        <div class="col-md-8"><x-status-badge :status="$latestPayment->payment_status" domain="payment" /></div>
                    </div>
                @else
                    <p class="text-muted mb-0">No payment on file.</p>
                @endif
            </x-card>
        </div>

        <div class="col-lg-4">
            <x-card title="Quick Summary" icon="fas fa-clipboard-list" bodyClass="card-body" class="mb-4 monitoring-summary-card">
                <div class="d-flex align-items-center gap-3 mb-3">
                    <div class="monitoring-avatar">
                        {{ strtoupper(substr($booking->guest_display_name, 0, 1)) }}
                    </div>
                    <div>
                        <p class="mb-0 fw-bold">{{ $booking->guest_display_name }}</p>
                        <small class="text-muted">{{ $booking->account_guest?->user?->email ?? '' }}</small>
                    </div>
                </div>
                <ul class="list-unstyled mb-0 monitoring-summary-list">
                    <li>
                        <span class="text-muted">Room Type</span>
                        <span>{{ $booking->roomType->name ?? 'N/A' }}</span>
                    </li>
                    <li>
                        <span class="text-muted">Stay</span>
                        <span>{{ $booking->check_in->format('M d') }} &ndash; {{ $booking->check_out->format('M d, Y') }}</span>
                    </li>
                    <li>
                        <span class="text-muted">Nights</span>
                        <span>{{ $booking->number_of_nights }}</span>
                    </li>
                    <li>
                        <span class="text-muted">Guests</span>
                        <span>{{ $booking->adults + $booking->children }}</span>
                    </li>
                    <li>
                        <span class="text-muted">Staff Verification</span>
                        <span>
                            @if($booking->booking_status === \App\Models\Booking::STATUS_CANCELLED)
                                <span class="badge bg-danger">Cancelled</span>
                            @elseif($booking->verified_at)
                                <span class="badge bg-success">Verified</span>
                            @else
                                <span class="badge bg-warning text-dark">Pending</span>
                            @endif
                        </span>
                    </li>
                </ul>
            </x-card>
        </div>
    </div>
</div>
@endsection
