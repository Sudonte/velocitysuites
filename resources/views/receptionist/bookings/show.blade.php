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
        $nights = $booking->number_of_nights;
    @endphp

    <!-- ===================== BOOKING DETAILS header ===================== -->
    <div class="page-header d-flex flex-wrap align-items-center justify-content-between gap-2">
        <h1 class="mb-0 fw-bold"><i class="fas fa-calendar-check"></i> Booking #{{ $booking->id }}</h1>
        <div class="d-flex align-items-center gap-2">
            <x-status-badge :status="$booking->display_status" domain="booking" class="fs-6" />
            @if($booking->verified_at)
                <span class="badge bg-success">Verified</span>
            @endif
            @if($booking->hidden_at)
                <span class="badge bg-secondary"><i class="fas fa-box-archive"></i> Archived</span>
            @endif
        </div>
    </div>

    @if($siblings)
        <div class="alert alert-info d-flex align-items-center gap-2 mt-3 mb-0">
            <i class="fas fa-layer-group"></i>
            <span>
                This transaction includes <strong>{{ $siblings->count() }} room types/bookings</strong>
                (#{{ $siblings->pluck('id')->join(', #') }}) detected as booked together by the same guest, for the
                same dates, around the same time. Totals below reflect the complete transaction.
            </span>
        </div>
    @endif

    <div class="row mt-3">
        <div class="col-lg-8">
            <!-- ===================== GUEST INFORMATION ===================== -->
            <x-card title="Guest Information" icon="fas fa-user" bodyClass="card-body" class="mb-4">
                <dl class="detail-list mb-0">
                    <div><dt>Account Holder</dt><dd>{{ $booking->account_guest_full_name ?? 'N/A' }}</dd></div>
                    <div><dt>Representative Name</dt><dd>{{ $booking->guest_display_name }}</dd></div>
                    <div><dt>Email</dt><dd>{{ $booking->account_guest?->user?->email ?? 'N/A' }}</dd></div>
                    <div><dt>Mobile Number</dt><dd>{{ $booking->account_guest?->mobile_number ?: 'Not provided' }}</dd></div>
                    <div><dt>Adults</dt><dd>{{ $booking->adults }}</dd></div>
                    <div><dt>Children</dt><dd>{{ $booking->children }}</dd></div>
                    <div><dt>Total Guests</dt><dd>{{ $booking->number_of_guests }}</dd></div>
                </dl>
            </x-card>

            <!-- ===================== STAY INFORMATION ===================== -->
            <x-card title="Stay Information" icon="fas fa-calendar-days" bodyClass="card-body" class="mb-4">
                <dl class="detail-list mb-0">
                    <div><dt>Check-In</dt><dd>{{ $booking->check_in->format('F d, Y') }}</dd></div>
                    <div><dt>Check-Out</dt><dd>{{ $booking->check_out->format('F d, Y') }}</dd></div>
                    <div><dt>Nights</dt><dd>{{ $nights }}</dd></div>
                    <div><dt>Booking / Creation Date</dt><dd>{{ $booking->created_at?->format('F d, Y') ?? 'N/A' }}</dd></div>
                    <div><dt>Creation Time</dt><dd>{{ $booking->created_at?->format('h:i A') ?? 'N/A' }}</dd></div>
                </dl>
            </x-card>

            <!-- ===================== ROOM INFORMATION ===================== -->
            <x-card title="Room Information" icon="fas fa-bed" bodyClass="card-body" class="mb-4">
                @foreach($roomLines as $line)
                    @php
                        $lineRoomType = \App\Models\RoomType::find($line['room_type_id'] ?? null);
                        $assigned = $line['assigned_room_numbers'] ?? [];
                    @endphp
                    <div class="d-flex align-items-start gap-3 {{ !$loop->last ? 'pb-3 mb-3 border-bottom' : '' }}">
                        @if($lineRoomType)
                            <img src="{{ $lineRoomType->image_url }}" alt="{{ $line['room_type'] }}"
                                 class="rounded" style="width: 80px; height: 80px; object-fit: cover; flex-shrink: 0;">
                        @endif
                        <div class="flex-grow-1">
                            <h6 class="mb-2">
                                {{ $line['room_type'] }}
                                <span class="badge bg-secondary">&times;{{ $line['quantity'] }}</span>
                            </h6>
                            <dl class="detail-list mb-0">
                                <div><dt>Price / Room / Night</dt><dd>₱{{ number_format($line['price_per_night'], 2) }}</dd></div>
                                <div><dt>Quantity</dt><dd>{{ $line['quantity'] }}</dd></div>
                                <div><dt>Assigned Room Numbers</dt>
                                    <dd>
                                        @if(count($assigned))
                                            {{ implode(', ', $assigned) }}
                                        @else
                                            <span class="text-muted">Not yet assigned</span>
                                        @endif
                                    </dd>
                                </div>
                                <div><dt>Room Subtotal ({{ $line['nights'] }} night{{ $line['nights'] == 1 ? '' : 's' }})</dt>
                                    <dd class="fw-bold">₱{{ number_format($line['subtotal'], 2) }}</dd>
                                </div>
                            </dl>
                        </div>
                    </div>
                @endforeach
                <div class="d-flex justify-content-between align-items-center pt-2 mt-1 border-top">
                    <span class="fw-bold">Room Total</span>
                    <span class="fw-bold text-brand">₱{{ number_format($roomTotal, 2) }}</span>
                </div>
            </x-card>

            <!-- ===================== AMENITIES ===================== -->
            <x-card title="Amenities" icon="fas fa-spa" bodyClass="card-body" class="mb-4">
                @if($amenityRows->isEmpty())
                    <x-empty-state icon="fas fa-spa" message="No amenities selected for this booking." />
                @else
                    <div class="table-responsive">
                        <table class="table table-sm table-hover mb-2">
                            <thead>
                                <tr>
                                    <th>Amenity</th>
                                    <th>Quantity</th>
                                    <th>Price</th>
                                    <th>Subtotal</th>
                                    <th>Status</th>
                                </tr>
                            </thead>
                            <tbody>
                                @foreach($amenityRows as $row)
                                    <tr>
                                        <td>{{ $row->amenity_name }}</td>
                                        <td>{{ $row->quantity }}</td>
                                        <td>₱{{ number_format($row->charge, 2) }}</td>
                                        <td>₱{{ number_format($row->charge * $row->quantity, 2) }}</td>
                                        <td><x-status-badge :status="$row->status" domain="amenity_request" /></td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                    <div class="d-flex justify-content-between align-items-center pt-2 border-top">
                        <span class="fw-bold">Amenities Total</span>
                        <span class="fw-bold text-brand">₱{{ number_format($amenitiesTotal, 2) }}</span>
                    </div>
                @endif
            </x-card>

            @if($gcashPayment)
                <!-- ===================== GCASH PAYMENT INFORMATION ===================== -->
                <x-card title="GCash Payment Information" icon="fas fa-qrcode" bodyClass="card-body" class="mb-4">
                    <div class="row">
                        <div class="col-md-6">
                            <dl class="detail-list mb-0">
                                <div><dt>GCash Mobile Number</dt><dd>{{ $gcashPayment->gcash_number ?: 'Not provided' }}</dd></div>
                                <div><dt>GCash Reference Number</dt><dd>{{ $gcashPayment->reference_number ?: 'Not provided' }}</dd></div>
                                <div><dt>Payment Percentage</dt>
                                    <dd>{{ $booking->selected_payment_percentage ? (int) $booking->selected_payment_percentage . '%' : 'N/A' }}</dd>
                                </div>
                                <div><dt>Submitted Amount</dt><dd>₱{{ number_format($gcashPayment->amount_paid, 2) }}</dd></div>
                                <div><dt>Payment Date &amp; Time</dt><dd>{{ $gcashPayment->payment_date?->format('M d, Y h:i A') ?? 'Not recorded' }}</dd></div>
                            </dl>
                        </div>
                        <div class="col-md-6">
                            <p class="mb-1"><strong>Receipt / Proof of Payment:</strong></p>
                            @if($gcashPayment->receipt_path)
                                <a href="{{ $gcashPayment->receipt_url }}" target="_blank" rel="noopener">
                                    <img src="{{ $gcashPayment->receipt_url }}" alt="GCash Payment Receipt" class="img-thumbnail" style="max-height: 220px;">
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

            <!-- ===================== PAYMENT SUMMARY ===================== -->
            <x-card title="Payment Summary" icon="fas fa-wallet" bodyClass="card-body" class="mb-4">
                <dl class="detail-list mb-3">
                    <div><dt>Room Total</dt><dd>₱{{ number_format($roomTotal, 2) }}</dd></div>
                    <div><dt>Amenities Total</dt><dd>₱{{ number_format($amenitiesTotal, 2) }}</dd></div>
                    <div><dt>Grand Total</dt><dd class="fw-bold text-brand">₱{{ number_format($totalDue, 2) }}</dd></div>
                    <div><dt>Payment Method</dt><dd>{{ $gcashPayment ? 'GCash' : 'Cash' }}</dd></div>
                    <div><dt>Payment Percentage</dt>
                        <dd>{{ $booking->selected_payment_percentage ? (int) $booking->selected_payment_percentage . '%' : 'N/A' }}</dd>
                    </div>
                    <div><dt>Amount Paid</dt><dd class="text-success">₱{{ number_format($amountPaid, 2) }}</dd></div>
                    <div><dt>Remaining Balance</dt>
                        <dd class="{{ $remainingBalance > 0.009 ? 'text-danger' : 'text-success' }}">₱{{ number_format($remainingBalance, 2) }}</dd>
                    </div>
                    <div><dt>Payment Status</dt>
                        <dd>
                            @if($remainingBalance <= 0.009)
                                <span class="badge bg-success">Fully Paid</span>
                            @elseif($amountPaid > 0)
                                <span class="badge bg-warning text-dark">Partially Paid</span>
                            @else
                                <span class="badge bg-secondary">Unpaid</span>
                            @endif
                        </dd>
                    </div>
                </dl>

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
                <x-card title="Billing" icon="fas fa-file-invoice-dollar" bodyClass="card-body" class="mb-4">
                    <dl class="detail-list mb-0">
                        <div><dt>Status</dt><dd><x-status-badge :status="$booking->billing->billing_status" domain="billing" /></dd></div>
                        <div><dt>Total Amount</dt><dd>₱{{ number_format($booking->billing->total_amount, 2) }}</dd></div>
                        <div><dt>Balance</dt><dd>₱{{ number_format($booking->billing->balance, 2) }}</dd></div>
                    </dl>
                </x-card>
            @endif

            <!-- ===================== SPECIAL REQUESTS ===================== -->
            <x-card title="Special Requests" icon="fas fa-comment-dots" bodyClass="card-body" class="mb-4">
                <x-empty-state icon="fas fa-comment-dots" message="No special requests on file for this booking." />
            </x-card>

            <!-- ===================== TRANSACTION / STATUS HISTORY ===================== -->
            @if($history->isNotEmpty())
                <x-card title="Transaction / Status History" icon="fas fa-clock-rotate-left" bodyClass="card-body" class="mb-4">
                    <ul class="list-unstyled mb-0">
                        @foreach($history as $entry)
                            <li class="pb-3 mb-3 border-bottom">
                                <div class="d-flex justify-content-between">
                                    <strong>{{ $entry->action }}</strong>
                                    <small class="text-muted">{{ $entry->created_at?->format('M d, Y h:i A') }}</small>
                                </div>
                                @if($entry->description)
                                    <div class="text-muted small">{{ $entry->description }}</div>
                                @endif
                                @if($entry->user)
                                    <div class="text-muted small">by {{ $entry->user->full_name ?? $entry->user->email }}</div>
                                @endif
                            </li>
                        @endforeach
                    </ul>
                </x-card>
            @endif

            <!-- ===================== AUTHORIZED RECEPTIONIST ACTIONS ===================== -->
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
                        <span class="text-muted">Room Type(s)</span>
                        <span>{{ collect($roomLines)->pluck('room_type')->join(', ') }}</span>
                    </li>
                    <li>
                        <span class="text-muted">Stay</span>
                        <span>{{ $booking->check_in->format('M d') }} &ndash; {{ $booking->check_out->format('M d, Y') }}</span>
                    </li>
                    <li>
                        <span class="text-muted">Nights</span>
                        <span>{{ $nights }}</span>
                    </li>
                    <li>
                        <span class="text-muted">Grand Total</span>
                        <span class="fw-bold">₱{{ number_format($totalDue, 2) }}</span>
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
