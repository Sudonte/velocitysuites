@php
    // $depositPayment stays "the most recent deposit-stage submission" for
    // display of its own GCash details/receipt/verify-reject actions below -
    // that's correct even while unverified or rejected, since the
    // receptionist needs to see and act on exactly that attempt.
    $depositPayment = $reservation->payments->firstWhere('payment_stage', 'deposit');
    // Amount Paid / Remaining Balance must NEVER be driven by that single,
    // possibly-unverified object though - only verified (payment_status
    // 'completed') deposit-stage payments count as paid, summed (not just
    // the latest), matching the same authoritative rule
    // Receptionist\BookingController::show() already applies.
    $verifiedDepositAmountPaid = (float) $reservation->payments
        ->where('payment_stage', 'deposit')
        ->where('payment_status', 'completed')
        ->sum('amount_paid');
    $nights = $reservation->number_of_nights;
    // roomCharge kept as the single legacy fallback ONLY for the pre-filled
    // "Amount Paid" input default below - $roomTotal (from the controller,
    // grouped-aware via TransactionGroupingService) is the real source of
    // truth for every displayed total on this page now.
    $roomCharge = $roomTotal;
    $grandTotal = round($roomTotal + $amenitiesTotal, 2);
@endphp

<!-- ===================== Reservation header ===================== -->
<div class="details-header-card d-flex flex-wrap align-items-start justify-content-between gap-2">
    <div>
        <p class="details-header-eyebrow mb-1"><i class="fas fa-calendar-alt"></i> Reservation Details</p>
        <h1 class="mb-1 fw-bold details-header-id">Reservation ID: {{ $reservation->id }}</h1>
        <p class="text-muted mb-0">
            {{ $reservation->guest_display_name }}
            &bull; {{ collect($roomLines)->pluck('room_type')->join(', ') }}
            &bull; {{ $reservation->check_in->format('M d') }} - {{ $reservation->check_out->format('M d, Y') }}
        </p>
    </div>
    <x-status-badge :status="$reservation->status" domain="reservation" class="fs-6" />
</div>

@if($siblings)
    <div class="alert alert-info d-flex align-items-center gap-2 mb-3">
        <i class="fas fa-layer-group"></i>
        <span>
            This transaction includes <strong>{{ $siblings->count() }} room types/reservations</strong>
            (#{{ $siblings->pluck('id')->join(', #') }}) detected as reserved together by the same guest, for the
            same dates, around the same time. Totals below reflect the complete transaction.
        </span>
    </div>
@endif

<div class="row g-4">
    <!-- Left column: guest + stay + room -->
    <div class="col-lg-6">
        <x-card title="Guest Information" icon="fas fa-user" bodyClass="card-body" class="mb-4">
            <dl class="detail-list mb-0">
                <div><dt>Account Holder</dt><dd>{{ $reservation->guest?->user?->full_name ?? 'Walk-in (no account)' }}</dd></div>
                <div><dt>Representative Name</dt><dd>{{ $reservation->guest_display_name }}</dd></div>
                <div><dt>Email</dt><dd>{{ $reservation->guest?->user?->email ?? 'N/A' }}</dd></div>
                <div><dt>Mobile Number</dt><dd>{{ $reservation->guest?->mobile_number ?: 'Not provided' }}</dd></div>
                <div><dt>Adults</dt><dd>{{ $reservation->adults }}</dd></div>
                <div><dt>Children</dt><dd>{{ $reservation->children }}</dd></div>
                <div><dt>Total Guests</dt><dd>{{ $reservation->number_of_guests }}</dd></div>
            </dl>
            @if(!empty($reservation->additional_guest_details))
                <p class="mb-0 mt-2"><strong>Additional Guests:</strong></p>
                <ul class="mb-0 small text-muted">
                    @foreach($reservation->additional_guest_details as $g)
                        <li>{{ $g['name'] ?? 'N/A' }} ({{ $g['age'] ?? '?' }}@if(!empty($g['relationship'])), {{ $g['relationship'] }}@endif)</li>
                    @endforeach
                </ul>
            @endif
        </x-card>

        <x-card title="Stay Information" icon="fas fa-calendar-days" bodyClass="card-body" class="mb-4">
            <dl class="detail-list mb-0">
                <div><dt>Check-In</dt><dd>{{ $reservation->check_in->format('M d, Y') }}</dd></div>
                <div><dt>Check-Out</dt><dd>{{ $reservation->check_out->format('M d, Y') }}</dd></div>
                <div><dt>Nights</dt><dd>{{ $nights }}</dd></div>
                <div><dt>Booking / Creation Date</dt><dd>{{ $reservation->created_at?->format('M d, Y') ?? 'N/A' }}</dd></div>
                <div><dt>Creation Time</dt><dd>{{ $reservation->created_at?->format('h:i A') ?? 'N/A' }}</dd></div>
            </dl>
        </x-card>

        <!-- One bordered mini-card per selected room TYPE - never just the
             first, for a genuine multi-room-type reservation. -->
        <x-card title="Room Information" icon="fas fa-bed" bodyClass="card-body" class="mb-4">
            <div class="room-type-card-list">
                @foreach($roomLines as $line)
                    @php
                        $lineRoomType = \App\Models\RoomType::find($line['room_type_id'] ?? null);
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
                            <p class="mb-1 fw-bold">{{ $line['room_type'] }} <span class="badge bg-secondary">&times;{{ $line['quantity'] }}</span></p>
                            <dl class="detail-list mb-0">
                                <div><dt>Price / Room / Night</dt><dd>₱{{ number_format($line['price_per_night'], 2) }}</dd></div>
                                <div><dt>Assigned Room{{ $line['quantity'] > 1 ? 's' : '' }}</dt>
                                    <dd class="text-muted">Room assignment pending - a receptionist assigns specific room(s) once this converts to a booking.</dd>
                                </div>
                                <div><dt>Room Subtotal</dt><dd class="fw-bold">₱{{ number_format($line['subtotal'], 2) }}</dd></div>
                            </dl>
                        </div>
                    </div>
                @endforeach
            </div>
            @if(isset($available))
                <p class="mb-0 mt-3">
                    <strong>Availability:</strong>
                    @if($available >= $reservation->rooms_requested)
                        <span class="text-success"><i class="fas fa-check-circle"></i> {{ $available }} room(s) free for these dates</span>
                    @else
                        <span class="text-danger"><i class="fas fa-exclamation-triangle"></i> Only {{ $available }} free (needs {{ $reservation->rooms_requested }})</span>
                    @endif
                </p>
            @endif
        </x-card>

        <x-card title="Amenities" icon="fas fa-spa" bodyClass="card-body" class="mb-4">
            @if($amenityRows->isEmpty())
                <x-empty-state icon="fas fa-spa" message="No amenities selected for this reservation." />
            @else
                <div class="table-responsive">
                    <table class="table table-sm mb-2">
                        <thead>
                            <tr><th>Amenity</th><th>Qty</th><th>Price</th><th>Subtotal</th></tr>
                        </thead>
                        <tbody>
                            @foreach($amenityRows as $row)
                                <tr>
                                    <td>{{ $row->amenity_name }}</td>
                                    <td>{{ $row->quantity }}</td>
                                    <td>₱{{ number_format($row->charge, 2) }}</td>
                                    <td>₱{{ number_format($row->subtotal, 2) }}</td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
                <div class="d-flex justify-content-between align-items-center pt-1 border-top">
                    <span class="fw-bold">Amenities Total</span>
                    <span class="fw-bold text-brand">₱{{ number_format($amenitiesTotal, 2) }}</span>
                </div>
            @endif
        </x-card>
    </div>

    <!-- Right column: payment/billing + actions -->
    <div class="col-lg-6">
        <x-card title="Payment Summary" icon="fas fa-receipt" bodyClass="card-body" class="mb-4">
            <dl class="detail-list mb-2">
                <div><dt>Room Total ({{ $nights }} night{{ $nights == 1 ? '' : 's' }})</dt><dd>₱{{ number_format($roomTotal, 2) }}</dd></div>
                <div><dt>Amenities Total</dt><dd>₱{{ number_format($amenitiesTotal, 2) }}</dd></div>
                @if(($reservation->discount_preview['discount'] ?? 0) > 0)
                    <div><dt>Discount</dt><dd class="text-success">- ₱{{ number_format($reservation->discount_preview['discount'], 2) }}</dd></div>
                @endif
                <div><dt>Estimated Grand Total</dt><dd class="fw-bold text-brand">₱{{ number_format($grandTotal, 2) }}</dd></div>
                <div><dt>Payment Method</dt><dd>{{ $reservation->payment_method === 'gcash' ? 'GCash' : 'Cash' }}</dd></div>
                <div><dt>Payment Preference</dt><dd>{{ $reservation->payment_preference === 'pay_now' ? 'Pay Now' : 'Pay Later' }}</dd></div>
                <div><dt>Payment Percentage</dt>
                    <dd>{{ $reservation->selected_payment_percentage ? (int) $reservation->selected_payment_percentage . '%' : 'N/A' }}</dd>
                </div>
                @if($depositPayment)
                    <div><dt>Amount Paid</dt><dd class="text-success">₱{{ number_format($verifiedDepositAmountPaid, 2) }}</dd></div>
                    @php $depositRemaining = max(0, $grandTotal - $verifiedDepositAmountPaid); @endphp
                    <div><dt>Remaining Balance</dt><dd>₱{{ number_format($depositRemaining, 2) }}</dd></div>
                    <div><dt>Payment Status</dt><dd><x-status-badge :status="$depositPayment->payment_status" domain="payment" /></dd></div>
                @else
                    <div><dt>Amount Paid</dt><dd>₱0.00</dd></div>
                    <div><dt>Payment Status</dt><dd><span class="badge bg-secondary">No deposit submitted</span></dd></div>
                @endif
            </dl>
            <p class="text-muted small mb-0">
                <i class="fas fa-info-circle"></i> Final charges (extra-guest fees, additional charges, and any
                verified discount) are settled at checkout, not here.
            </p>
            @if($reservation->payment_deadline)
                <p class="mb-0 mt-2">
                    <strong>Payment Deadline:</strong>
                    <span class="text-danger">{{ \Illuminate\Support\Carbon::parse($reservation->payment_deadline)->format('M d, Y h:i A') }}</span>
                    <small class="text-muted d-block">Unpaid past this deadline auto-cancels the reservation.</small>
                </p>
            @endif
        </x-card>

        @if($depositPayment && $depositPayment->payment_method === 'gcash')
            <x-card title="GCash Payment Information" icon="fas fa-qrcode" bodyClass="card-body" class="mb-4">
                <dl class="detail-list mb-2">
                    <div><dt>GCash Mobile Number</dt><dd>{{ $depositPayment->gcash_number ?: 'Not provided' }}</dd></div>
                    <div><dt>GCash Reference Number</dt><dd>{{ $depositPayment->reference_number ?: 'Not provided' }}</dd></div>
                    <div><dt>Payment Percentage</dt>
                        <dd>{{ $reservation->selected_payment_percentage ? (int) $reservation->selected_payment_percentage . '%' : 'N/A' }}</dd>
                    </div>
                    <div><dt>Submitted Amount</dt><dd>₱{{ number_format($depositPayment->amount_paid, 2) }}</dd></div>
                </dl>
                @if($depositPayment->receipt_path)
                    <a href="{{ asset('storage/' . $depositPayment->receipt_path) }}" target="_blank">
                        <img src="{{ asset('storage/' . $depositPayment->receipt_path) }}" alt="Payment Receipt" class="img-thumbnail" style="max-height: 200px;">
                    </a>
                @else
                    <p class="text-muted">No receipt uploaded.</p>
                @endif

                <div class="mt-3">
                    <p class="mb-2">
                        <strong>Verification Status:</strong>
                        @if($depositPayment->isVerified())
                            <span class="badge bg-success">Verified</span>
                        @elseif($depositPayment->isRejected())
                            <span class="badge bg-danger">Rejected</span>
                            @if($depositPayment->rejection_reason)
                                <br><small class="text-muted">Reason: {{ $depositPayment->rejection_reason }}</small>
                            @endif
                        @else
                            <span class="badge bg-warning text-dark">Pending Verification</span>
                        @endif
                    </p>
                    @if(!$depositPayment->isVerified() && !$depositPayment->isRejected())
                        <div class="d-flex gap-2">
                            <form action="{{ route('receptionist.payments.verify', $depositPayment) }}" method="POST" class="d-inline">
                                @csrf
                                @method('PUT')
                                <button type="submit" class="btn btn-sm btn-success" onclick="return confirm('Verify this payment?')">
                                    <i class="fas fa-check"></i> Verify Payment
                                </button>
                            </form>
                            <form action="{{ route('receptionist.payments.reject', $depositPayment) }}" method="POST" class="d-inline" onsubmit="return window.preparePaymentReject(this)">
                                @csrf
                                @method('PUT')
                                <input type="hidden" name="reason" value="">
                                <button type="submit" class="btn btn-sm btn-outline-danger">
                                    <i class="fas fa-times"></i> Reject Payment
                                </button>
                            </form>
                        </div>
                    @endif
                </div>
            </x-card>
        @endif

        <x-card title="Discount Request" icon="fas fa-id-card" bodyClass="card-body" class="mb-4">
            @if($reservation->discount_requested)
                <p class="mb-1"><strong>Status:</strong> <x-status-badge :status="$reservation->discount_verification_status" domain="discount_verification" /></p>
                @if($reservation->id_document_path)
                    <a href="{{ asset('storage/' . $reservation->id_document_path) }}" target="_blank" class="d-block mt-1">
                        <img src="{{ asset('storage/' . $reservation->id_document_path) }}" alt="ID Document" class="img-thumbnail" style="max-height: 150px;">
                        <small class="d-block text-muted">Click to view full size</small>
                    </a>
                @elseif($reservation->id_card_image_path)
                    <a href="{{ route('receptionist.reservations.id-card', $reservation) }}" target="_blank" class="d-block mt-1">
                        <img src="{{ route('receptionist.reservations.id-card', $reservation) }}" alt="ID Card{{ $reservation->id_card_type ? " ({$reservation->id_card_type})" : '' }}" class="img-thumbnail" style="max-height: 150px;">
                        <small class="d-block text-muted">{{ $reservation->id_card_type ? "{$reservation->id_card_type} - " : '' }}Click to view full size</small>
                    </a>
                @else
                    <p class="text-muted mb-0">Requested but no ID uploaded yet.</p>
                @endif
            @else
                <p class="text-muted mb-0">No discount requested.</p>
            @endif
        </x-card>

        @if(in_array($reservation->status, [\App\Models\Reservation::STATUS_CANCELLED, \App\Models\Reservation::STATUS_REJECTED], true))
            <x-card title="Cancellation Information" icon="fas fa-ban" bodyClass="card-body" class="mb-4">
                <p class="mb-0">{{ $reservation->rejection_reason ?: 'No reason recorded.' }}</p>
            </x-card>
        @endif

        @if($history->isNotEmpty())
            <x-card title="Transaction / Status History" icon="fas fa-clock-rotate-left" bodyClass="card-body" class="mb-4">
                <ul class="list-unstyled mb-0">
                    @foreach($history as $entry)
                        <li class="pb-2 mb-2 border-bottom">
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
    </div>
</div>

<!-- ===================== AUTHORIZED RECEPTIONIST ACTIONS =====================
     Element IDs below are queried by id directly from
     receptionist/reservations/index.blade.php's own <script> block - keep
     every id exactly as-is even when restyling around them. -->
@if(in_array($reservation->status, \App\Models\Reservation::ACTIVE_STATUSES))
    <x-card title="Authorized Actions" icon="fas fa-user-shield" bodyClass="card-body">
    <div class="alert alert-danger d-none" id="detailsActionError"></div>

    <div id="detailsRejectForm" class="d-none">
        <h6 class="text-brand"><i class="fas fa-times-circle"></i> Reject Reservation</h6>
        <div class="mb-2">
            <label class="form-label">Reason <span class="text-danger">*</span></label>
            <textarea id="detailsRejectReason" class="form-control" rows="3" maxlength="500" required
                      placeholder="This will be sent to the guest.">{{ ($available ?? 0) < $reservation->rooms_requested ? 'The ' . ($reservation->roomType->name ?? '') . ' room type does not have enough rooms available for your requested dates.' : '' }}</textarea>
        </div>
        <div class="d-flex gap-2 justify-content-end">
            <button type="button" class="btn btn-secondary" id="detailsCancelRejectBtn">Cancel</button>
            <button type="button" class="btn btn-danger" id="detailsSubmitRejectBtn">Reject Reservation</button>
        </div>
    </div>

    @if($reservation->payment_method === 'cash')
        <div id="detailsCashPaymentForm" class="d-none">
            <h6 class="text-brand"><i class="fas fa-money-bill-wave"></i> Convert to Booking</h6>
            <p class="text-muted small">Enter the amount actually received from the guest at the front desk. Must be
                the full ₱{{ number_format($grandTotal, 2) }}, or a deposit between 20%-50% of that total.</p>
            <div class="mb-2">
                <label class="form-label">Amount Received (₱) <span class="text-danger">*</span></label>
                <input type="number" id="detailsCashAmount" class="form-control" min="0.01" step="0.01" required
                       placeholder="0.00">
            </div>
            <div class="d-flex gap-2 justify-content-end">
                <button type="button" class="btn btn-secondary" id="detailsCancelCashBtn">Cancel</button>
                <button type="button" class="btn btn-success" id="detailsSubmitCashBtn">
                    <i class="fas fa-check"></i> Confirm &amp; Convert to Booking
                </button>
            </div>
        </div>
    @endif

    @if($reservation->payment_method === 'gcash' && $reservation->status === \App\Models\Reservation::STATUS_AWAITING_GCASH && $reservation->payments->isEmpty())
        <!-- No manual Accept step for GCash - recordDepositPayment() moves this
             straight to ready_for_booking (and usually auto-converts) the
             moment the guest actually submits a payment; there's nothing for
             the receptionist to do here yet. Matches convertToBooking()'s own
             defense-in-depth guard against converting an unpaid GCash
             reservation. -->
        <div class="d-flex justify-content-between align-items-center">
            <p class="text-muted mb-0"><i class="fas fa-hourglass-half"></i> Waiting for Guest GCash Payment</p>
            <button type="button" class="btn btn-outline-danger btn-sm" id="detailsShowRejectBtn">
                <i class="fas fa-times"></i> Reject
            </button>
        </div>
    @else
    @php $notEnoughRooms = ($available ?? 0) < $reservation->rooms_requested; @endphp
    <div id="detailsMainActions" class="d-flex gap-2 justify-content-end">
        <button type="button" class="btn btn-outline-danger" id="detailsShowRejectBtn">
            <i class="fas fa-times"></i> Reject
        </button>
        @if($reservation->payment_method === 'cash')
            {{-- One action for Cash: asks how much was actually received,
                 then records that payment and converts to a Booking in the
                 same step (confirmCashPayment()) - there's no way to
                 convert a Cash reservation without also recording what the
                 guest paid. --}}
            <button type="button" class="btn btn-success" id="detailsShowCashBtn" {{ $notEnoughRooms ? 'disabled' : '' }}
                    title="{{ $notEnoughRooms ? 'Not enough rooms of this type available for the requested dates.' : '' }}">
                <i class="fas fa-calendar-check"></i> Convert to Booking
            </button>
        @else
            {{-- GCash, reached here only once a payment is actually on
                 file (the "Waiting for Guest GCash Payment" branch above
                 covers the unpaid case) - Convert works the same whether
                 this reservation is still pending_review or already
                 ready_for_booking, no separate Accept step either. --}}
            <button type="button" class="btn btn-success" id="detailsConvertBtn" {{ $notEnoughRooms ? 'disabled' : '' }}
                    title="{{ $notEnoughRooms ? 'Not enough rooms of this type available for the requested dates.' : '' }}">
                <i class="fas fa-calendar-check"></i> Convert to Booking
            </button>
        @endif
    </div>
    @endif
    </x-card>
@endif
