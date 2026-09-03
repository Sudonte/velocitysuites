@extends('layouts.app')

@section('title', 'Booking Details - Receptionist')

@section('content')
<div class="container-fluid py-4">
    <a href="{{ route('receptionist.bookings.index') }}" class="btn btn-sm btn-secondary mb-3">
        <i class="fas fa-arrow-left"></i> Back to Bookings
    </a>

    @if (session('success'))
        <div class="alert alert-success alert-dismissible fade show" role="alert">
            <i class="fas fa-check-circle"></i> {{ session('success') }}
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
    @endif

    @php
        $gcashPayment = $booking->latestGcashPayment();
    @endphp

    <div class="page-header">
        <h1 class="mb-0"><i class="fas fa-calendar-check"></i> Booking #{{ $booking->id }}</h1>
        <div class="d-flex align-items-center gap-2">
            <x-status-badge :status="$booking->booking_status" domain="booking" class="fs-6" />
            @if($booking->verified_at)
                <span class="badge bg-success">Verified</span>
            @endif
            @if($booking->hidden_at)
                <span class="badge bg-secondary"><i class="fas fa-box-archive"></i> Archived</span>
            @endif
        </div>
    </div>

    <div class="row">
        <div class="col-lg-8">
            <x-card title="Guest Information" bodyClass="card-body" class="mb-4">
                <p class="mb-1"><strong>Account Holder:</strong> {{ $booking->account_guest_full_name ?? 'N/A' }}</p>
                <p class="mb-1">
                    <strong>Representative Name:</strong>
                    {{ $booking->guest_display_name }}
                </p>
                <p class="mb-1"><strong>Email:</strong> {{ $booking->account_guest?->user?->email ?? 'N/A' }}</p>
                <p class="mb-0"><strong>Phone:</strong> {{ $booking->account_guest?->mobile_number ?: 'Not provided' }}</p>
            </x-card>

            <x-card title="Booking Information" bodyClass="card-body" class="mb-4">
                <div class="d-flex align-items-start gap-3">
                    @if($booking->roomType)
                        <img src="{{ $booking->roomType->image_url }}" alt="{{ $booking->roomType->name }}"
                             class="rounded" style="width: 72px; height: 72px; object-fit: cover; flex-shrink: 0;">
                    @endif
                    <div class="flex-grow-1">
                        <div class="row">
                            <div class="col-md-6">
                                <p class="mb-1">
                                    <strong>Room Type Requested:</strong> {{ $booking->roomType->name ?? 'N/A' }}
                                    @if($booking->rooms_requested > 1)
                                        <span class="badge bg-secondary">&times;{{ $booking->rooms_requested }} rooms</span>
                                    @endif
                                </p>
                            </div>
                            <div class="col-md-6">
                                <p class="mb-1"><strong>Check-In:</strong> {{ $booking->check_in->format('F d, Y') }}</p>
                                <p class="mb-1"><strong>Check-Out:</strong> {{ $booking->check_out->format('F d, Y') }}</p>
                            </div>
                        </div>
                        <p class="mb-1"><strong>Guests:</strong> {{ $booking->adults }} adult{{ $booking->adults == 1 ? '' : 's' }}@if($booking->children > 0), {{ $booking->children }} child{{ $booking->children == 1 ? '' : 'ren' }}@endif</p>
                        <p class="mb-1"><strong>Total Amount:</strong> ₱{{ number_format($totalDue, 2) }}</p>
                        <p class="mb-0"><strong>Confirmed:</strong> {{ $booking->confirmed_at?->format('M d, Y h:i A') }}</p>
                    </div>
                </div>
            </x-card>

            @if($gcashPayment)
                <x-card title="GCash Payment Verification" icon="fas fa-qrcode" bodyClass="card-body" class="mb-4">
                    <div class="row">
                        <div class="col-md-6">
                            <p class="mb-1"><strong>Registered GCash Number:</strong></p>
                            <p class="mb-3">{{ $gcashPayment->gcash_number ?: 'Not provided' }}</p>

                            <p class="mb-1"><strong>Reference Number:</strong></p>
                            <p class="mb-3">{{ $gcashPayment->reference_number ?: 'Not provided' }}</p>

                            <p class="mb-1"><strong>Amount Paid:</strong></p>
                            <p class="mb-3">₱{{ number_format($gcashPayment->amount_paid, 2) }}</p>

                            <p class="mb-1"><strong>Payment Date &amp; Time:</strong></p>
                            <p class="mb-0">{{ $gcashPayment->payment_date?->format('M d, Y h:i A') ?? 'Not recorded' }}</p>
                        </div>
                        <div class="col-md-6">
                            <p class="mb-1"><strong>Uploaded Receipt:</strong></p>
                            @if($gcashPayment->receipt_path)
                                <a href="{{ $gcashPayment->receipt_url }}" target="_blank" rel="noopener">
                                    <img src="{{ $gcashPayment->receipt_url }}" alt="GCash Payment Receipt" class="img-thumbnail" style="max-height: 240px;">
                                    <small class="d-block text-muted mt-1"><i class="fas fa-expand"></i> Click to open full size</small>
                                </a>
                            @else
                                <p class="text-muted">No receipt uploaded.</p>
                            @endif
                        </div>
                    </div>

                    <hr>

                    <p class="mb-2">
                        <strong>Verification Status:</strong>
                        @if($gcashPayment->isVerified())
                            <span class="badge bg-success">Verified</span>
                        @elseif($gcashPayment->isRejected())
                            <span class="badge bg-danger">Rejected</span>
                            @if($gcashPayment->rejection_reason)
                                <br><small class="text-muted">Reason: {{ $gcashPayment->rejection_reason }}</small>
                            @endif
                        @else
                            <span class="badge bg-warning text-dark">Pending Verification</span>
                        @endif
                    </p>

                    @unless($gcashPayment->isVerified() || $gcashPayment->isRejected())
                        <div class="d-flex gap-2">
                            <form action="{{ route('receptionist.payments.verify', $gcashPayment) }}" method="POST" class="d-inline">
                                @csrf
                                @method('PUT')
                                <button type="submit" class="btn btn-sm btn-success" onclick="return confirm('Verify this GCash payment? Make sure the registered number and receipt have both been checked against the booking details.')">
                                    <i class="fas fa-check"></i> Verify Payment
                                </button>
                            </form>
                            <button type="button" class="btn btn-sm btn-outline-danger" data-bs-toggle="modal" data-bs-target="#rejectPaymentModal">
                                <i class="fas fa-times"></i> Reject Payment
                            </button>
                        </div>
                    @endunless
                </x-card>
            @endif

            @if($booking->discount_requested)
                <x-card title="Senior Citizen / PWD Identification" icon="fas fa-id-card" bodyClass="card-body" class="mb-4">
                    <p class="mb-2">
                        <strong>Status:</strong>
                        <x-status-badge :status="$booking->discount_verification_status" domain="discount_verification" />
                    </p>
                    @if($booking->id_card_image_path)
                        <a href="{{ route('receptionist.bookings.id-card', $booking) }}" target="_blank" rel="noopener" class="d-block mt-1">
                            <img src="{{ route('receptionist.bookings.id-card', $booking) }}"
                                 alt="ID Card{{ $booking->id_card_type ? " ({$booking->id_card_type})" : '' }}"
                                 class="img-thumbnail" style="max-height: 240px;">
                            <small class="d-block text-muted mt-1">
                                {{ $booking->id_card_type ? "{$booking->id_card_type} - " : '' }}Click to open full size
                            </small>
                        </a>
                    @else
                        <p class="text-muted mb-0">Requested but no ID uploaded.</p>
                    @endif
                </x-card>
            @endif

            <x-card title="Payment & Balance" icon="fas fa-wallet" bodyClass="card-body" class="mb-4">
                <div class="row text-center mb-3">
                    <div class="col-4">
                        <p class="text-muted small mb-1">Total Amount</p>
                        <p class="fw-bold mb-0">₱{{ number_format($totalDue, 2) }}</p>
                    </div>
                    <div class="col-4">
                        <p class="text-muted small mb-1">Amount Paid</p>
                        <p class="fw-bold mb-0 text-success">₱{{ number_format($amountPaid, 2) }}</p>
                    </div>
                    <div class="col-4">
                        <p class="text-muted small mb-1">Remaining Balance</p>
                        <p class="fw-bold mb-0 {{ $remainingBalance > 0.009 ? 'text-danger' : 'text-success' }}">₱{{ number_format($remainingBalance, 2) }}</p>
                    </div>
                </div>

                @if($booking->payment_method === 'cash' && in_array($booking->booking_status, [\App\Models\Booking::STATUS_ACTIVE, \App\Models\Booking::STATUS_CHECKED_IN]) && $remainingBalance > 0.009)
                    <hr>
                    <h6 class="text-brand"><i class="fas fa-hand-holding-dollar"></i> Record Walk-In Payment</h6>
                    <p class="text-muted small">Any remaining balance is settled through a walk-in cash payment at the hotel - record it here as it's received.</p>
                    <form action="{{ route('receptionist.bookings.record-payment', $booking) }}" method="POST" class="row g-2 align-items-end"
                          onsubmit="return confirm('Record this cash payment against the booking\'s remaining balance?')">
                        @csrf
                        <div class="col-sm-6">
                            <label class="form-label small mb-1">Amount Received (₱)</label>
                            <input type="number" name="amount_paid" class="form-control" min="0.01" max="{{ $remainingBalance }}" step="0.01" required placeholder="0.00">
                        </div>
                        <div class="col-sm-6">
                            <button type="submit" class="btn btn-success w-100">
                                <i class="fas fa-check"></i> Confirm Cash Payment
                            </button>
                        </div>
                    </form>
                @elseif($booking->payment_method === 'gcash' && in_array($booking->booking_status, [\App\Models\Booking::STATUS_ACTIVE, \App\Models\Booking::STATUS_CHECKED_IN]) && $remainingBalance > 0.009)
                    <hr>
                    <p class="text-muted small mb-0">
                        <i class="fas fa-info-circle"></i> This booking pays via GCash - any remaining balance is
                        settled through another GCash submission (verified above), not a walk-in cash entry.
                    </p>
                @endif
            </x-card>

            @if($booking->billing)
                <x-card title="Billing" bodyClass="card-body">
                    <p class="mb-1"><strong>Status:</strong> <x-status-badge :status="$booking->billing->billing_status" domain="billing" /></p>
                    <p class="mb-1"><strong>Total Amount:</strong> ₱{{ number_format($booking->billing->total_amount, 2) }}</p>
                    <p class="mb-0"><strong>Balance:</strong> ₱{{ number_format($booking->billing->balance, 2) }}</p>
                </x-card>
            @endif

            @if($booking->booking_status === \App\Models\Booking::STATUS_CANCELLED)
                <x-card title="Booking Rejected / Failed" icon="fas fa-ban" bodyClass="card-body">
                    <p class="mb-3">
                        @if($booking->rejection_reason)
                            This booking was rejected: {{ $booking->rejection_reason }}
                        @elseif($gcashPayment && $gcashPayment->isRejected())
                            This booking was cancelled because its GCash payment was rejected{{ $gcashPayment->rejection_reason ? ' - ' . $gcashPayment->rejection_reason : '' }}.
                        @else
                            This booking has been cancelled.
                        @endif
                    </p>
                    @if($booking->hidden_at)
                        <p class="text-muted small mb-3"><i class="fas fa-box-archive"></i> Archived {{ $booking->hidden_at->format('M d, Y h:i A') }}</p>
                    @endif
                    <div class="d-flex gap-2">
                        @unless($booking->hidden_at)
                            <form action="{{ route('receptionist.bookings.archive', $booking) }}" method="POST">
                                @csrf
                                @method('PUT')
                                <button type="submit" class="btn btn-outline-secondary">
                                    <i class="fas fa-box-archive"></i> Archive
                                </button>
                            </form>
                        @endunless
                        <form action="{{ route('receptionist.bookings.destroy', $booking) }}" method="POST" onsubmit="return confirm('Delete this booking? It will no longer appear anywhere in the Bookings module.');">
                            @csrf
                            @method('DELETE')
                            <button type="submit" class="btn btn-outline-danger">
                                <i class="fas fa-trash"></i> Delete
                            </button>
                        </form>
                    </div>
                </x-card>
            @elseif(!$booking->verified_at)
                <x-card title="Booking Verification" icon="fas fa-clipboard-check" bodyClass="card-body">
                    @if($booking->gcashPaymentNeedsVerification())
                        <div class="alert alert-warning mb-0">
                            <i class="fas fa-exclamation-triangle"></i>
                            This booking's GCash payment must be verified above before the booking itself can be verified
                            - verifying the payment there completes the booking automatically, in one step.
                        </div>
                    @else
                        <div class="d-flex gap-2 mb-3">
                            <form action="{{ route('receptionist.bookings.verify', $booking) }}" method="POST">
                                @csrf
                                @method('PUT')
                                <button type="submit" class="btn btn-success" onclick="return confirm('Verify this booking?')">
                                    <i class="fas fa-check"></i> Verify Booking
                                </button>
                            </form>
                            <button type="button" class="btn btn-outline-danger" data-bs-toggle="collapse" data-bs-target="#rejectBookingForm">
                                <i class="fas fa-ban"></i> Reject
                            </button>
                        </div>
                        <div class="collapse" id="rejectBookingForm">
                            <form action="{{ route('receptionist.bookings.reject', $booking) }}" method="POST" onsubmit="return confirm('Reject this booking? This cannot be undone.')">
                                @csrf
                                @method('PUT')
                                <div class="mb-2">
                                    <label for="rejectReasonInput" class="form-label">Rejection reason / feedback</label>
                                    <textarea name="reason" id="rejectReasonInput" class="form-control" rows="3" required maxlength="500" placeholder="Let the guest know why this booking is being rejected..."></textarea>
                                </div>
                                <button type="submit" class="btn btn-danger">
                                    <i class="fas fa-ban"></i> Confirm Rejection
                                </button>
                            </form>
                        </div>
                    @endif
                </x-card>
            @elseif($booking->hidden_at)
                <x-card title="Archived Booking" icon="fas fa-box-archive" bodyClass="card-body">
                    <p class="mb-2">
                        <span class="badge bg-success"><i class="fas fa-check-circle"></i> Completed</span>
                        This booking has been verified, completed, and archived - it's read-only now, no further
                        verification, assignment, or other changes can be made.
                    </p>
                    <p class="text-muted small mb-3"><i class="fas fa-box-archive"></i> Archived {{ $booking->hidden_at->format('M d, Y h:i A') }}</p>
                    <form action="{{ route('receptionist.bookings.destroy', $booking) }}" method="POST" onsubmit="return confirm('Delete this booking? It will no longer appear anywhere in the Bookings module.');">
                        @csrf
                        @method('DELETE')
                        <button type="submit" class="btn btn-outline-danger">
                            <i class="fas fa-trash"></i> Delete
                        </button>
                    </form>
                </x-card>
            @else
                <x-card title="Completed Booking" icon="fas fa-check-circle" bodyClass="card-body">
                    <p class="mb-3">This booking has been verified and completed.</p>
                    <form action="{{ route('receptionist.bookings.archive', $booking) }}" method="POST">
                        @csrf
                        @method('PUT')
                        <button type="submit" class="btn btn-outline-secondary" onclick="return confirm('Archive this completed booking? It will move to the Archived list.')">
                            <i class="fas fa-box-archive"></i> Archive
                        </button>
                    </form>
                </x-card>
            @endif
        </div>

        <!-- Quick Summary sidebar - stacks below the main column on
             narrower screens (Bootstrap's default col-lg-4 behavior). -->
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
                        <span class="text-muted">Payment Method</span>
                        <span>{{ $gcashPayment ? 'GCash' : 'Cash' }}</span>
                    </li>
                    @if($gcashPayment)
                        <li>
                            <span class="text-muted">GCash Verification</span>
                            <span>
                                @if($gcashPayment->isVerified())
                                    <span class="badge bg-success">Verified</span>
                                @elseif($gcashPayment->isRejected())
                                    <span class="badge bg-danger">Rejected</span>
                                @else
                                    <span class="badge bg-warning text-dark">Pending</span>
                                @endif
                            </span>
                        </li>
                    @endif
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

@if($gcashPayment && !$gcashPayment->isVerified() && !$gcashPayment->isRejected())
<div class="modal fade" id="rejectPaymentModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog">
        <form action="{{ route('receptionist.payments.reject', $gcashPayment) }}" method="POST" class="modal-content">
            @csrf
            @method('PUT')
            <div class="modal-header">
                <h5 class="modal-title">Reject GCash Payment</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <label for="rejectPaymentReason" class="form-label">Reason <span class="text-danger">*</span></label>
                <textarea name="reason" id="rejectPaymentReason" class="form-control" rows="3" required maxlength="500"
                    placeholder="e.g. receipt doesn't match the declared amount, reference number can't be verified. This will be sent to the guest."></textarea>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancel</button>
                <button type="submit" class="btn btn-danger"><i class="fas fa-times"></i> Reject Payment</button>
            </div>
        </form>
    </div>
</div>
@endif

@endsection



