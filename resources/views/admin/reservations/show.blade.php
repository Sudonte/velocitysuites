@extends('layouts.app')

@section('title', 'Reservation Details - System Administrator')

@section('content')
<div class="container-fluid py-4">
    <div class="d-flex justify-content-between align-items-start flex-wrap gap-2 mb-4">
        <div>
            <a href="{{ route('admin.reservations.index') }}" class="btn btn-sm btn-secondary mb-2">
                <i class="fas fa-arrow-left"></i> Back to Monitoring
            </a>
            <h1 class="mb-0">
                <i class="fas fa-calendar-alt"></i> Reservation #{{ $reservation->id }}
            </h1>
        </div>
        <div>
            @if($reservation->booking)
                <span class="badge bg-primary fs-6"><i class="fas fa-credit-card"></i> Booking</span>
                <x-status-badge :status="$reservation->booking->booking_status" domain="booking" class="fs-6" />
            @else
                <span class="badge bg-secondary fs-6"><i class="fas fa-calendar-alt"></i> Reservation</span>
                <x-status-badge :status="$reservation->status" domain="reservation" class="fs-6" />
            @endif
        </div>
    </div>

    @if (session('success'))
        <div class="alert alert-success alert-dismissible fade show" role="alert">
            <i class="fas fa-check-circle"></i> {{ session('success') }}
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
    @endif

    <div class="row">
        <div class="col-lg-8">
            <!-- Reservation Info -->
            <x-card title="Reservation Details" icon="fas fa-info-circle" bodyClass="card-body" class="mb-4">
                <div class="row mb-2">
                    <div class="col-md-4"><strong>Status:</strong></div>
                    <div class="col-md-8">
                        @if($reservation->booking)
                            <x-status-badge :status="$reservation->booking->booking_status" domain="booking" />
                        @else
                            <x-status-badge :status="$reservation->status" domain="reservation" />
                        @endif
                    </div>
                </div>
                <div class="row mb-2">
                    <div class="col-md-4"><strong>Guest:</strong></div>
                    <div class="col-md-8">
                        {{ $reservation->stay_guest_full_name ?? $reservation->guest->user->full_name ?? 'N/A' }} ({{ $reservation->guest->user->email ?? '' }})
                        @if($reservation->stay_guest_full_name && $reservation->guest->user->full_name !== $reservation->stay_guest_full_name)
                            <br><small class="text-muted">Account: {{ $reservation->guest->user->full_name }}</small>
                        @endif
                    </div>
                </div>
                <div class="row mb-2">
                    <div class="col-md-4"><strong>Room Type:</strong></div>
                    <div class="col-md-8">{{ $reservation->roomType->name ?? 'N/A' }}</div>
                </div>
                <div class="row mb-2">
                    <div class="col-md-4"><strong>Room:</strong></div>
                    <div class="col-md-8">
                        @if($reservation->booking?->room)
                            {{ $reservation->booking->room->room_number }} - {{ $reservation->booking->room->room_name }}
                        @else
                            <span class="text-muted">Assigned at check-in</span>
                        @endif
                    </div>
                </div>
                <div class="row mb-2">
                    <div class="col-md-4"><strong>Check-In:</strong></div>
                    <div class="col-md-8">{{ $reservation->check_in->format('M d, Y h:i A') }}</div>
                </div>
                <div class="row mb-2">
                    <div class="col-md-4"><strong>Check-Out:</strong></div>
                    <div class="col-md-8">{{ $reservation->check_out->format('M d, Y h:i A') }}</div>
                </div>
                <div class="row mb-2">
                    <div class="col-md-4"><strong>Nights:</strong></div>
                    <div class="col-md-8">{{ $reservation->number_of_nights }}</div>
                </div>
                <div class="row mb-0">
                    <div class="col-md-4"><strong>Number of Guests:</strong></div>
                    <div class="col-md-8">{{ $reservation->number_of_guests }}</div>
                </div>
            </x-card>

            <!-- Booking Info -->
            @if($reservation->booking)
                <x-card title="Booking Details" icon="fas fa-bookmark" bodyClass="card-body" class="mb-4">
                    <div class="row mb-2">
                        <div class="col-md-4"><strong>Confirmed At:</strong></div>
                        <div class="col-md-8">{{ $reservation->booking->confirmed_at->format('M d, Y') }}</div>
                    </div>
                    <div class="row mb-0">
                        <div class="col-md-4"><strong>Booking Status:</strong></div>
                        <div class="col-md-8">
                            <x-status-badge :status="$reservation->booking->booking_status" domain="booking" />
                        </div>
                    </div>
                </x-card>
            @endif

            <!-- GCash Payment Details: registered number + uploaded receipt,
                 for every GCash payment tied to this transaction (deposit
                 stage before conversion, and/or the final stage at
                 checkout). View-only here - verification itself stays a
                 receptionist action (Receptionist\PaymentController). -->
            @if($gcashPayments->isNotEmpty())
                <x-card title="GCash Payment Details" icon="fas fa-qrcode" bodyClass="card-body" class="mb-4">
                    @foreach($gcashPayments as $payment)
                        <div class="gcash-payment-entry {{ !$loop->last ? 'mb-4 pb-4 border-bottom' : '' }}">
                            <div class="d-flex justify-content-between align-items-center mb-2 flex-wrap gap-2">
                                <span class="badge bg-light text-dark border">
                                    {{ $payment->payment_stage === 'final' ? 'Final Payment' : 'Deposit Payment' }}
                                </span>
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
                                    <p class="mb-3">₱{{ number_format($payment->amount_paid, 2) }}</p>

                                    <p class="mb-1"><strong>Payment Date &amp; Time:</strong></p>
                                    <p class="mb-0">{{ $payment->payment_date?->format('M d, Y h:i A') ?? 'Not recorded' }}</p>
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
                            @if($payment->isRejected() && $payment->rejection_reason)
                                <small class="text-muted d-block mt-2">Rejection reason: {{ $payment->rejection_reason }}</small>
                            @endif
                        </div>
                    @endforeach
                </x-card>
            @endif

            <!-- Billing Info -->
            @if($reservation->booking && $reservation->booking->billing)
                @php $billing = $reservation->booking->billing; @endphp
                <x-card title="Billing Summary" icon="fas fa-receipt" bodyClass="card-body" class="mb-4">
                    <div class="table-responsive">
                        <table class="table table-sm">
                            <tr>
                                <td>Room Charge:</td>
                                <td class="text-end">₱{{ number_format($billing->room_charge, 2) }}</td>
                            </tr>
                            <tr>
                                <td>Additional Guest Fee:</td>
                                <td class="text-end">₱{{ number_format($billing->additional_guest_fee, 2) }}</td>
                            </tr>
                            <tr>
                                <td>Amenity Charge:</td>
                                <td class="text-end">₱{{ number_format($billing->amenity_charge, 2) }}</td>
                            </tr>
                            <tr>
                                <td>Discount:</td>
                                <td class="text-end text-success">- ₱{{ number_format($billing->discount, 2) }}</td>
                            </tr>
                            <tr class="fw-bold">
                                <td>Total Amount:</td>
                                <td class="text-end text-brand">₱{{ number_format($billing->total_amount, 2) }}</td>
                            </tr>
                            <tr>
                                <td>Billing Status:</td>
                                <td class="text-end">
                                    <x-status-badge :status="$billing->billing_status" domain="billing" />
                                </td>
                            </tr>
                        </table>
                    </div>

                    @if($billing->payments->count())
                        <h6 class="mt-3">Payments</h6>
                        <div class="table-responsive">
                            <table class="table table-sm">
                                <thead>
                                    <tr>
                                        <th>Date</th>
                                        <th>Method</th>
                                        <th>Reference</th>
                                        <th class="text-end">Amount</th>
                                        <th>Status</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    @foreach($billing->payments as $payment)
                                        <tr>
                                            <td>{{ $payment->payment_date->format('M d, Y') }}</td>
                                            <td>{{ ucfirst($payment->payment_method) }}</td>
                                            <td>{{ $payment->reference_number ?? 'N/A' }}</td>
                                            <td class="text-end">₱{{ number_format($payment->amount_paid, 2) }}</td>
                                            <td>
                                                <x-status-badge :status="$payment->payment_status" domain="payment" />
                                            </td>
                                        </tr>
                                    @endforeach
                                </tbody>
                            </table>
                        </div>
                    @endif
                </x-card>
            @endif
        </div>

        <!-- Quick Summary sidebar - stacks below the main column on
             narrower screens (Bootstrap's default col-lg-4 behavior). -->
        <div class="col-lg-4">
            <x-card title="Quick Summary" icon="fas fa-clipboard-list" bodyClass="card-body" class="mb-4 monitoring-summary-card">
                <div class="d-flex align-items-center gap-3 mb-3">
                    <div class="monitoring-avatar">
                        {{ strtoupper(substr($reservation->stay_guest_full_name ?? $reservation->guest->user->full_name ?? '?', 0, 1)) }}
                    </div>
                    <div>
                        <p class="mb-0 fw-bold">{{ $reservation->stay_guest_full_name ?? $reservation->guest->user->full_name ?? 'N/A' }}</p>
                        <small class="text-muted">{{ $reservation->guest->user->email ?? '' }}</small>
                    </div>
                </div>
                <ul class="list-unstyled mb-0 monitoring-summary-list">
                    <li>
                        <span class="text-muted">Room Type</span>
                        <span>{{ $reservation->roomType->name ?? 'N/A' }}</span>
                    </li>
                    <li>
                        <span class="text-muted">Stay</span>
                        <span>{{ $reservation->check_in->format('M d') }} &ndash; {{ $reservation->check_out->format('M d, Y') }}</span>
                    </li>
                    <li>
                        <span class="text-muted">Nights</span>
                        <span>{{ $reservation->number_of_nights }}</span>
                    </li>
                    <li>
                        <span class="text-muted">Guests</span>
                        <span>{{ $reservation->number_of_guests }}</span>
                    </li>
                    <li>
                        <span class="text-muted">Payment Method</span>
                        <span>{{ $reservation->payment_method === 'gcash' ? 'GCash' : 'Cash' }}</span>
                    </li>
                    @if($gcashPayments->isNotEmpty())
                        <li>
                            <span class="text-muted">GCash Verification</span>
                            <span>
                                @if($gcashPayments->contains(fn($p) => !$p->isVerified() && !$p->isRejected()))
                                    <span class="badge bg-warning text-dark">Pending</span>
                                @elseif($gcashPayments->every(fn($p) => $p->isVerified()))
                                    <span class="badge bg-success">Verified</span>
                                @else
                                    <span class="badge bg-danger">Rejected</span>
                                @endif
                            </span>
                        </li>
                    @endif
                </ul>
            </x-card>
        </div>
    </div>
</div>
@endsection
