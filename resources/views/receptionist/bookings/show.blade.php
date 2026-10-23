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
    <div class="details-header-card d-flex flex-wrap align-items-start justify-content-between gap-2">
        <div>
            <p class="details-header-eyebrow mb-1"><i class="fas fa-calendar-check"></i> Booking Details</p>
            <h1 class="mb-0 fw-bold details-header-id">Booking ID: {{ $booking->id }}</h1>
        </div>
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
            <div class="row">
                <!-- ===================== GUEST INFORMATION ===================== -->
                <div class="col-md-6">
                    <x-card title="1. Guest & Booking Info" icon="fas fa-user" bodyClass="card-body" class="mb-4 h-100">
                        <dl class="detail-list mb-0">
                            <div><dt>Account Holder</dt><dd>{{ $booking->account_guest_full_name ?? 'N/A' }}</dd></div>
                            <div><dt>Representative Name</dt><dd>{{ $booking->guest_display_name }}</dd></div>
                            <div><dt>Email</dt><dd>{{ $booking->account_guest?->user?->email ?? 'N/A' }}</dd></div>
                            <div><dt>Mobile Number</dt><dd>{{ $booking->account_guest?->mobile_number ?: 'Not provided' }}</dd></div>
                            <div><dt>Adults</dt><dd>{{ $booking->adults }}</dd></div>
                            <div><dt>Minors</dt><dd>{{ $booking->children }}</dd></div>
                            <div><dt>Total Guests</dt><dd>{{ $booking->number_of_guests }}</dd></div>
                        </dl>
                    </x-card>
                </div>

                <!-- ===================== STAY INFORMATION ===================== -->
                <div class="col-md-6">
                    <x-card title="1. Stay Details" icon="fas fa-calendar-days" bodyClass="card-body" class="mb-4 h-100">
                        <dl class="detail-list mb-0">
                            <div><dt>Check-In</dt><dd>{{ $booking->check_in->format('F d, Y') }}</dd></div>
                            <div><dt>Check-Out</dt><dd>{{ $booking->check_out->format('F d, Y') }}</dd></div>
                            <div><dt>Nights</dt><dd>{{ $nights }}</dd></div>
                            @if($booking->hasSeveralStays())
                                <div><dt>Stays</dt><dd>{{ $booking->stayCount() }} (listed below)</dd></div>
                            @endif
                            <div><dt>Booking / Creation Date</dt><dd>{{ $booking->created_at?->format('F d, Y') ?? 'N/A' }}</dd></div>
                            <div><dt>Creation Time</dt><dd>{{ $booking->created_at?->format('h:i A') ?? 'N/A' }}</dd></div>
                        </dl>
                    </x-card>
                </div>
            </div>

            <!-- ===================== ROOM INFORMATION =====================
                 One bordered mini-card per distinct selected room type - a
                 genuine multi-room-type transaction (Deluxe x2 + Executive x1,
                 etc.) must show every line, never just the first, and each
                 line's own image must never be a broken-image icon when
                 image_url is missing/null (falls back to a plain icon tile). -->
            @if($booking->hasSeveralStays())
                <x-card title="2. Stays" icon="fas fa-layer-group" bodyClass="card-body" class="mb-4">
                    <p class="text-muted small">One booking, {{ $booking->stayCount() }} stays: verification covers the whole booking; rooms, check-in and check-out are per stay.</p>
                    @include('partials.stays-list', ['stays' => $booking->staysPayload()])
                </x-card>
            @endif
            <x-card title="2. Charges - Rooms" icon="fas fa-bed" bodyClass="card-body" class="mb-4">
                <div class="room-type-card-list">
                    @foreach($roomLines as $line)
                        @php
                            $lineRoomType = \App\Models\RoomType::find($line['room_type_id'] ?? null);
                            $assigned = $line['assigned_room_numbers'] ?? [];
                            $lineImageUrl = $lineRoomType->image_url ?? null;
                        @endphp
                        <div class="room-type-card">
                            @if($lineImageUrl)
                                <img src="{{ $lineImageUrl }}" alt="{{ $line['room_type'] }}" class="room-type-card-image">
                            @else
                                <div class="room-type-card-image room-type-card-image-placeholder">
                                    <i class="fas fa-bed"></i>
                                </div>
                            @endif
                            <div class="flex-grow-1">
                                <h6 class="mb-2">
                                    {{ $line['room_type'] }}
                                    <span class="badge bg-secondary">&times;{{ $line['quantity'] }}</span>
                                </h6>
                                <dl class="detail-list mb-0">
                                    <div><dt>Price / Room / Night</dt><dd>₱{{ number_format($line['price_per_night'], 2) }}</dd></div>
                                    <div><dt>Quantity</dt><dd>{{ $line['quantity'] }}</dd></div>
                                    <div><dt>Assigned Room{{ $line['quantity'] > 1 ? ' Numbers' : ' Number' }}</dt>
                                        <dd>
                                            @if(count($assigned))
                                                {{ implode(', ', $assigned) }}
                                            @else
                                                <span class="text-muted">Room assignment pending</span>
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
                </div>
                <div class="d-flex justify-content-between align-items-center pt-2 mt-1 border-top">
                    <span class="fw-bold">Room Total</span>
                    <span class="fw-bold text-brand">₱{{ number_format($roomTotal, 2) }}</span>
                </div>
            </x-card>

            <!-- ===================== AMENITIES ===================== -->
            <x-card title="2. Charges - Amenities" icon="fas fa-spa" bodyClass="card-body" class="mb-4">
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
                                        <td>{{ $row->amenity_name }}@if($row->room_label)<div class="small text-muted">{{ $row->room_label }}</div>@endif</td>
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
                <x-card title="3. Payments Already Made - GCash Payment" icon="fas fa-qrcode" bodyClass="card-body" class="mb-4" id="gcashPayment">
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
                        <div class="verification-actions">
                            <form action="{{ route('receptionist.payments.verify', $gcashPayment) }}" method="POST" class="flex-sm-grow-1"
                                  data-confirm="Check that the GCash number, reference {{ $gcashPayment->reference_number }} and the receipt match ₱{{ number_format($gcashPayment->amount_paid, 2) }}. Verifying also completes the booking."
                                  data-confirm-title="Verify this GCash payment?" data-confirm-button="Verify Payment" data-confirm-variant="success">
                                @csrf
                                @method('PUT')
                                <button type="submit" class="btn btn-success w-100">
                                    <i class="fas fa-check"></i> Verify Payment
                                </button>
                            </form>
                            <button type="button" class="btn btn-outline-danger" data-bs-toggle="modal" data-bs-target="#rejectPaymentModal">
                                <i class="fas fa-times"></i> Reject Payment
                            </button>
                        </div>
                        <p class="text-muted small mt-2 mb-0"><i class="fas fa-info-circle"></i> Verifying the payment also completes the booking. Rejecting asks the guest for a new payment.</p>
                    @endunless
                </x-card>
            @endif

            @if($booking->discount_requested)
                {{-- View only: the ID is approved or rejected in the Check In module, never here. --}}
                <x-card title="Discount ID" icon="fas fa-id-card" bodyClass="card-body" class="mb-4">
                    <p class="mb-2">
                        <strong>Status:</strong>
                        <x-status-badge :status="$booking->discount_verification_status" domain="discount_verification" />
                        <span class="badge bg-light text-muted border ms-1">View only</span>
                    </p>
                    <dl class="detail-list mb-2">
                        <div><dt>Discount type</dt><dd>{{ $discountChoices->firstWhere('id', $booking->discount_id)?->name ?? ($booking->id_card_type ?: 'N/A') }}</dd></div>
                        @if($booking->discount_verification_status === 'rejected')
                            <div><dt>Rejection reason</dt><dd class="text-danger">{{ $booking->discount_rejection_reason ?: 'No reason recorded' }}</dd></div>
                        @endif
                        @if(in_array($booking->discount_verification_status, ['approved', 'rejected'], true))
                            <div><dt>Decided by</dt><dd>{{ $discountDecidedBy?->name ?? 'Not recorded' }}</dd></div>
                            <div><dt>Decided at</dt><dd>{{ $booking->discount_verified_at ? $booking->discount_verified_at->timezone('Asia/Manila')->format('M d, Y g:i A') : 'Not recorded' }}</dd></div>
                        @endif
                    </dl>
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
                    @if($booking->discount_verification_status === 'pending')
                        <p class="text-muted small mt-2 mb-0"><i class="fas fa-info-circle"></i> The ID is approved or rejected by the receptionist at check-in.</p>
                    @endif
                </x-card>
            @endif

            <!-- ===================== PAYMENT SUMMARY ===================== -->
            <x-card title="4. Payment Summary" icon="fas fa-wallet" bodyClass="card-body" class="mb-4">
                <dl class="detail-list mb-3">
                    <div><dt>Room Total</dt><dd>₱{{ number_format($roomTotal, 2) }}</dd></div>
                    <div><dt>Amenities Total</dt><dd>₱{{ number_format($amenitiesTotal, 2) }}</dd></div>
                    @if($billDiscount > 0.009)
                        <div><dt>Discount ({{ $discountLabel }})</dt><dd class="text-success">-₱{{ number_format($billDiscount, 2) }}</dd></div>
                    @endif
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
                        <dd><span class="badge {{ $stayPayment['badge'] }}">{{ $stayPayment['label'] }}</span></dd>
                    </div>
                </dl>

                {{-- The two figures the receptionist acts on, highlighted like the guest's receipt: what is left and what is due. --}}
                <div class="rounded p-3 mb-3 border {{ $remainingBalance > 0.009 ? 'border-danger' : 'border-success' }}" id="paymentHighlight">
                    <div class="d-flex justify-content-between align-items-center">
                        <span class="fw-semibold text-uppercase small">Remaining Balance</span>
                        <span class="fs-5 fw-bold {{ $remainingBalance > 0.009 ? 'text-danger' : 'text-success' }}">₱{{ number_format($remainingBalance, 2) }}</span>
                    </div>
                    <div class="d-flex justify-content-between align-items-center mt-1">
                        <span class="fw-semibold text-uppercase small">Amount to Pay</span>
                        <span class="fs-4 fw-bold text-brand">₱{{ number_format($remainingBalance, 2) }}</span>
                    </div>
                    <div class="small text-muted text-end">{{ $remainingBalance > 0.009 ? 'Due at check-in' : 'Fully paid' }}</div>
                </div>

                @if($stayPayment['state'] === 'overpaid' && in_array($booking->booking_status, [\App\Models\Booking::STATUS_ACTIVE, \App\Models\Booking::STATUS_CHECKED_IN]))
                    {{-- Paid more than the stay now costs (e.g. paid in full, then the discount was approved). --}}
                    <div class="alert alert-primary">
                        <strong><i class="fas fa-hand-holding-dollar"></i> Return ₱{{ number_format($stayPayment['excess'], 2) }} to the guest.</strong>
                        They paid ₱{{ number_format($amountPaid, 2) }} but the total is now ₱{{ number_format($totalDue, 2) }}. Only the actual total is kept as paid.
                        <form action="{{ route('receptionist.bookings.return-change', $booking) }}" method="POST" class="mt-2"
                              data-confirm="₱{{ number_format($stayPayment['excess'], 2) }} is recorded as handed back to the guest." data-confirm-title="Record the change as returned?" data-confirm-button="Record Returned" data-confirm-variant="primary">
                            @csrf
                            <div class="form-check mb-2">
                                <input class="form-check-input" type="checkbox" name="confirm" value="1" id="returnChangeConfirm" required>
                                <label class="form-check-label" for="returnChangeConfirm">I handed ₱{{ number_format($stayPayment['excess'], 2) }} back to the guest</label>
                            </div>
                            <button type="submit" class="btn btn-sm btn-primary">Record Change Returned</button>
                        </form>
                    </div>
                @endif

                @if($booking->payment_method === 'cash' && in_array($booking->booking_status, [\App\Models\Booking::STATUS_ACTIVE, \App\Models\Booking::STATUS_CHECKED_IN]) && $remainingBalance > 0.009)
                    <hr>
                    <h6 class="text-brand"><i class="fas fa-hand-holding-dollar"></i> 5. Payment Input - Record Walk-In Payment</h6>
                    <p class="text-muted small">Any remaining balance is settled through a walk-in cash payment at the hotel - record it here as it's received.</p>
                    <form action="{{ route('receptionist.bookings.record-payment', $booking) }}" method="POST" class="row g-2 align-items-start" id="walkInForm"
                          data-confirm="Record this cash payment against the booking's remaining balance?" data-confirm-title="Record this cash payment against the booking's remaining balance?" data-confirm-button="Record Payment" data-confirm-variant="success">
                        @csrf
                        <div class="col-sm-6">
                            <label class="form-label small mb-1" for="walkInReceived">Cash Received (₱)</label>
                            <input type="number" name="amount_received" id="walkInReceived" class="form-control" min="0.01" step="0.01" required placeholder="0.00">
                        </div>
                        <div class="col-sm-6">
                            <label class="form-label small mb-1" for="walkInAmount">Amount Applied (₱)</label>
                            <input type="number" name="amount_paid" id="walkInAmount" class="form-control" min="0.01" max="{{ $remainingBalance }}" step="0.01" required placeholder="0.00" data-balance="{{ $remainingBalance }}" aria-describedby="walkInHelp walkInError">
                            <div class="form-text" id="walkInHelp">Remaining balance: <strong>₱{{ number_format($remainingBalance, 2) }}</strong> - the amount applied can't be more than this.</div>
                        </div>
                        <div class="col-12">
                            <div class="text-danger small d-none" id="walkInError" role="alert"></div>
                            <div class="alert alert-info py-2 mb-2 d-none" id="walkInChange" role="status"></div>
                            <button type="submit" class="btn btn-success w-100" id="walkInSubmit" disabled>
                                <i class="fas fa-check"></i> Confirm Cash Payment
                            </button>
                        </div>
                        <script>
                            (function () {
                                const applied = document.getElementById('walkInAmount');
                                const received = document.getElementById('walkInReceived');
                                const error = document.getElementById('walkInError');
                                const changeBox = document.getElementById('walkInChange');
                                const submit = document.getElementById('walkInSubmit');
                                const balanceCents = Math.round(parseFloat(applied.dataset.balance) * 100);
                                const peso = (c) => '₱' + (c / 100).toLocaleString('en-PH', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
                                function check() {
                                    const a = Math.round(parseFloat(applied.value) * 100);
                                    const r = Math.round(parseFloat(received.value) * 100);
                                    let message = '';
                                    if (applied.value.trim() !== '' && (isNaN(a) || a <= 0)) message = 'The amount applied must be greater than ₱0.00.';
                                    else if (!isNaN(a) && a > balanceCents) message = 'The amount applied can\'t be more than the remaining balance of ' + peso(balanceCents) + '.';
                                    else if (!isNaN(a) && !isNaN(r) && r < a) message = 'The cash received must be at least the amount applied.';
                                    error.textContent = message;
                                    error.classList.toggle('d-none', message === '');
                                    applied.classList.toggle('is-invalid', message !== '' && !isNaN(a) && a > balanceCents);
                                    if (message === '' && !isNaN(a) && !isNaN(r) && r > a) {
                                        changeBox.textContent = 'Change due: ' + peso(r - a);
                                        changeBox.classList.remove('d-none');
                                    } else {
                                        changeBox.classList.add('d-none');
                                    }
                                    submit.disabled = message !== '' || isNaN(a) || a <= 0 || isNaN(r);
                                }
                                applied.addEventListener('input', check);
                                received.addEventListener('input', check);
                                check();
                            })();
                        </script>
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
                            This booking was cancelled: {{ $booking->rejection_reason }}
                        @elseif($gcashPayment && $gcashPayment->isRejected())
                            This booking was cancelled because its GCash payment was rejected{{ $gcashPayment->rejection_reason ? ' - ' . $gcashPayment->rejection_reason : '' }}.
                        @else
                            This booking has been cancelled.
                        @endif
                    </p>
                    @if($booking->statusChangedBy)
                        <p class="text-muted small mb-2"><i class="fas fa-user"></i> Cancelled by {{ $booking->statusChangedBy->full_name }}{{ $booking->cancelled_at ? ' on ' . $booking->cancelled_at->format('M d, Y h:i A') : '' }}</p>
                    @endif
                    @include('receptionist.bookings.partials.archive-toggle', ['booking' => $booking])
                </x-card>
            @elseif($booking->booking_status === \App\Models\Booking::STATUS_NO_SHOW)
                <x-card title="No-Show" icon="fas fa-user-slash" bodyClass="card-body">
                    <p class="mb-2">The guest didn't check in, so this booking was marked as a no-show{{ $booking->rejection_reason ? ': ' . $booking->rejection_reason : '.' }}</p>
                    <p class="text-muted small mb-3">
                        <i class="fas fa-user"></i> Marked by {{ $booking->statusChangedBy?->full_name ?? 'staff' }}{{ $booking->no_show_at ? ' on ' . $booking->no_show_at->format('M d, Y h:i A') : '' }}
                    </p>
                    <div class="d-flex flex-wrap gap-2">
                        <form action="{{ route('receptionist.bookings.undo-no-show', $booking) }}" method="POST"
                              data-confirm="The booking goes back to Confirmed (only if the room type still has space for these dates)." data-confirm-title="Undo no-show?" data-confirm-button="Undo No-Show" data-confirm-variant="warning">
                            @csrf
                            @method('PUT')
                            <button type="submit" class="btn btn-outline-warning"><i class="fas fa-rotate-left"></i> Undo No-Show</button>
                        </form>
                        @include('receptionist.bookings.partials.archive-toggle', ['booking' => $booking])
                    </div>
                </x-card>
            @elseif(!$booking->verified_at)
                <x-card title="5. Payment Input - Confirm Verification" icon="fas fa-clipboard-check" bodyClass="card-body">
                    @if($booking->gcashPaymentNeedsVerification())
                        <div class="alert alert-warning mb-0">
                            <i class="fas fa-exclamation-triangle"></i>
                            This booking's GCash payment must be verified above before the booking itself can be verified
                            - verifying the payment there completes the booking automatically, in one step.
                        </div>
                    @else
                        <div class="verification-actions mb-3">
                            <form action="{{ route('receptionist.bookings.verify', $booking) }}" method="POST" class="flex-sm-grow-1" data-confirm="The booking will be marked as verified and moves to Confirmed Bookings." data-confirm-title="Verify booking #{{ $booking->id }}?" data-confirm-button="Verify Booking" data-confirm-variant="success">
                                @csrf
                                @method('PUT')
                                <button type="submit" class="btn btn-success w-100">
                                    <i class="fas fa-check"></i> Verify Booking
                                </button>
                            </form>
                            <button type="button" class="btn btn-outline-danger" data-bs-toggle="collapse" data-bs-target="#rejectBookingForm" aria-expanded="false" aria-controls="rejectBookingForm">
                                <i class="fas fa-ban"></i> Reject Booking
                            </button>
                        </div>
                        <div class="collapse" id="rejectBookingForm">
                            <form action="{{ route('receptionist.bookings.reject', $booking) }}" method="POST" data-confirm="This cannot be undone." data-confirm-title="Reject this booking?" data-confirm-button="Reject Booking" data-confirm-variant="danger">
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
                    @include('receptionist.bookings.partials.archive-toggle', ['booking' => $booking])
                </x-card>
            @else
                <x-card title="Confirmed Booking" icon="fas fa-check-circle" bodyClass="card-body">
                    <p class="mb-3">This booking has been verified.</p>
                    @if($booking->booking_status === \App\Models\Booking::STATUS_ACTIVE)
                        {{-- Set by hand only - nothing marks a no-show or cancels automatically. --}}
                        <div class="d-flex flex-wrap gap-2 mb-3">
                            @if($booking->check_in->startOfDay()->lte(\App\Support\CheckInWindow::date()))
                                <button type="button" class="btn btn-outline-dark" data-bs-toggle="collapse" data-bs-target="#noShowForm" aria-expanded="false">
                                    <i class="fas fa-user-slash"></i> Mark as No-Show
                                </button>
                            @endif
                            <button type="button" class="btn btn-outline-danger" data-bs-toggle="collapse" data-bs-target="#cancelBookingForm" aria-expanded="false">
                                <i class="fas fa-ban"></i> Cancel Booking
                            </button>
                        </div>
                        @if($booking->check_in->startOfDay()->lte(\App\Support\CheckInWindow::date()))
                            <div class="collapse mb-3" id="noShowForm">
                                <form action="{{ route('receptionist.bookings.no-show', $booking) }}" method="POST"
                                      data-confirm="The room is released. You can undo this if it was a mistake." data-confirm-title="Mark booking #{{ $booking->id }} as a no-show?" data-confirm-button="Mark as No-Show" data-confirm-variant="dark">
                                    @csrf
                                    @method('PUT')
                                    <label for="noShowReason" class="form-label">Reason (optional)</label>
                                    <textarea name="reason" id="noShowReason" class="form-control mb-2" rows="2" maxlength="500" placeholder="e.g. Guest didn't arrive and couldn't be reached"></textarea>
                                    <button type="submit" class="btn btn-dark"><i class="fas fa-user-slash"></i> Confirm No-Show</button>
                                </form>
                            </div>
                        @endif
                        <div class="collapse mb-3" id="cancelBookingForm">
                            <form action="{{ route('receptionist.bookings.cancel', $booking) }}" method="POST"
                                  data-confirm="The guest is notified and the room is released." data-confirm-title="Cancel booking #{{ $booking->id }}?" data-confirm-button="Cancel Booking" data-confirm-variant="danger">
                                @csrf
                                @method('PUT')
                                <label for="cancelReason" class="form-label">Reason (optional)</label>
                                <textarea name="reason" id="cancelReason" class="form-control mb-2" rows="2" maxlength="500" placeholder="e.g. Guest called to cancel"></textarea>
                                <button type="submit" class="btn btn-danger"><i class="fas fa-ban"></i> Confirm Cancellation</button>
                            </form>
                        </div>
                    @endif
                    <form action="{{ route('receptionist.bookings.archive', $booking) }}" method="POST" data-confirm="It will move to the Archived list." data-confirm-title="Archive this completed booking?" data-confirm-button="Archive Booking" data-confirm-variant="secondary">
                        @csrf
                        @method('PUT')
                        <button type="submit" class="btn btn-outline-secondary">
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
        <form action="{{ route('receptionist.payments.reject', $gcashPayment) }}" method="POST" class="modal-content" data-submit-once>
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
