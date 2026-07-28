@php
    $isBooking = (bool) $reservation->booking;
    $billing = $reservation->booking->billing ?? null;
@endphp

<div class="modal-header modal-header-brand">
    <div>
        <h5 class="modal-title mb-0">
            <i class="fas {{ $isBooking ? 'fa-credit-card' : 'fa-calendar-alt' }}"></i>
            {{ $isBooking ? 'Booking' : 'Reservation' }} #{{ $reservation->id }}
        </h5>
        <div class="small text-white-50 mt-1">
            {{ $reservation->stay_guest_full_name ?? $reservation->guest->user->full_name ?? 'N/A' }} &middot; {{ $reservation->roomType->name ?? 'N/A' }}
            &middot; {{ $reservation->check_in->format('M d') }} &ndash; {{ $reservation->check_out->format('M d, Y') }}
        </div>
    </div>
    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
</div>

<div class="modal-body reservation-detail-modal-body" data-reservation-id="{{ $reservation->id }}">
    <div class="d-none" id="detailErrorAlert-wrap">
        <div class="alert alert-danger d-flex align-items-center gap-2 mb-4" id="detailErrorAlert">
            <i class="fas fa-exclamation-circle"></i>
            <span></span>
        </div>
    </div>

    <div class="d-flex align-items-center justify-content-between mb-4">
        <x-status-badge :status="$reservation->status" domain="reservation" />
        @if($isBooking && $billing)
            <x-status-badge :status="$billing->billing_status" domain="billing" />
        @endif
    </div>

    <div class="row g-4">
        <!-- Left column: guest + stay -->
        <div class="col-lg-6">
            <section class="detail-section">
                <h6 class="detail-section-title"><i class="fas fa-user"></i> Guest Information</h6>
                <dl class="detail-list">
                    <div><dt>Staying Guest</dt><dd>{{ $reservation->stay_guest_full_name ?? 'N/A' }}</dd></div>
                    <div><dt>Account Holder</dt><dd>{{ $reservation->guest->user->full_name ?? 'N/A' }}</dd></div>
                    <div><dt>Email</dt><dd>{{ $reservation->guest->user->email ?? 'N/A' }}</dd></div>
                    <div><dt>Mobile</dt><dd>{{ $reservation->guest->mobile_number ?? 'N/A' }}</dd></div>
                </dl>
            </section>

            <section class="detail-section">
                <h6 class="detail-section-title"><i class="fas fa-bed"></i> Stay Details</h6>
                <dl class="detail-list">
                    <div><dt>Room Type</dt><dd>{{ $reservation->roomType->name ?? 'N/A' }}</dd></div>
                    <div><dt>Assigned Room</dt>
                        <dd>{{ $reservation->room ? $reservation->room->room_number . ' - ' . $reservation->room->room_name : 'Not yet assigned' }}</dd>
                    </div>
                    <div><dt>Check-In</dt><dd>{{ $reservation->check_in->format('M d, Y h:i A') }}</dd></div>
                    <div><dt>Check-Out</dt><dd>{{ $reservation->check_out->format('M d, Y h:i A') }}</dd></div>
                    <div><dt>Guests</dt>
                        <dd>
                            {{ $reservation->number_of_guests }}
                            @if($reservation->adults || $reservation->children)
                                <span class="text-muted">({{ $reservation->adults }} adult{{ $reservation->adults == 1 ? '' : 's' }}@if($reservation->children), {{ $reservation->children }} child{{ $reservation->children == 1 ? '' : 'ren' }}@endif)</span>
                            @endif
                        </dd>
                    </div>
                    @if(!empty($reservation->additional_guest_details))
                        <div><dt>Additional Guests</dt>
                            <dd>
                                @foreach($reservation->additional_guest_details as $g)
                                    {{ $g['name'] ?? 'N/A' }} ({{ $g['age'] ?? '?' }}@if(!empty($g['relationship'])), {{ $g['relationship'] }}@endif)<br>
                                @endforeach
                            </dd>
                        </div>
                    @endif
                </dl>
            </section>
        </div>

        <!-- Right column: payment/billing + actions -->
        <div class="col-lg-6">
            @if(!$isBooking)
                {{-- ===================== RESERVATION MODE ===================== --}}
                <section class="detail-section">
                    <h6 class="detail-section-title"><i class="fas fa-money-bill-wave"></i> Payment Information</h6>
                    <table class="table table-sm mb-0">
                        <tr>
                            <td>Room Charge ({{ $quote['nights'] }} night{{ $quote['nights'] == 1 ? '' : 's' }})</td>
                            <td class="text-end">₱{{ number_format($quote['room_charge'], 2) }}</td>
                        </tr>
                        @if($quote['discount'] > 0)
                            <tr class="text-success">
                                <td>Discount</td>
                                <td class="text-end">- ₱{{ number_format($quote['discount'], 2) }}</td>
                            </tr>
                        @endif
                        <tr class="fw-bold border-top">
                            <td>Estimated Total</td>
                            <td class="text-end text-brand">₱{{ number_format($quote['total'], 2) }}</td>
                        </tr>
                    </table>
                    <p class="text-muted small mb-0 mt-2">Final charges may include extra-guest fees and amenities added during the stay.</p>
                </section>

                @if($reservation->status === 'pending')
                    <section class="detail-section">
                        <h6 class="detail-section-title"><i class="fas fa-tasks"></i> Confirm Reservation</h6>
                        <p class="text-muted small mb-3">Verify the guest, stay, and payment details, then confirm or reject this request. Room assignment happens later, when preparing the Booking for check-in.</p>
                        <div class="d-flex flex-wrap gap-2">
                            <button type="button" class="btn btn-success" id="confirmReservationBtn">
                                <i class="fas fa-check"></i> Confirm Reservation
                            </button>
                            <button type="button" class="btn btn-outline-danger" id="showRejectFormBtn">
                                <i class="fas fa-times"></i> Reject Reservation
                            </button>
                        </div>

                        <form id="rejectReservationForm" class="mt-3 d-none border-top pt-3">
                            <label class="form-label"><strong>Reason for Rejection *</strong></label>
                            <textarea name="reason" class="form-control mb-2" rows="3" maxlength="500" required
                                      placeholder="This will be sent to the guest."></textarea>
                            <div class="d-flex gap-2">
                                <button type="submit" class="btn btn-danger btn-sm">
                                    <i class="fas fa-times-circle"></i> Confirm Rejection
                                </button>
                                <button type="button" class="btn btn-secondary btn-sm" id="cancelRejectFormBtn">Cancel</button>
                            </div>
                        </form>
                    </section>
                @elseif($reservation->status === 'confirmed')
                    <section class="detail-section">
                        <h6 class="detail-section-title"><i class="fas fa-credit-card"></i> Convert to Booking</h6>
                        <p class="text-muted small mb-3">Collect payment to convert this reservation into a Booking. It will then move to the Booking module for check-in.</p>
                        <form id="convertToBookingForm">
                            <div class="mb-3">
                                <label class="form-label"><strong>Payment Method *</strong></label>
                                <select class="form-select" name="payment_method" required>
                                    <option value="cash">Cash</option>
                                    <option value="gcash">GCash</option>
                                </select>
                            </div>
                            <div class="mb-3">
                                <label class="form-label"><strong>Reference Number</strong></label>
                                <input type="text" class="form-control" name="reference_number" placeholder="Required for GCash">
                            </div>
                            <div class="mb-3">
                                <label class="form-label"><strong>Amount Paid *</strong></label>
                                <input type="number" step="0.01" min="0.01" class="form-control" name="amount_paid"
                                       value="{{ number_format($quote['total'], 2, '.', '') }}" required>
                                <small class="text-muted">Full amount pre-filled - reduce for a partial payment.</small>
                            </div>
                            <button type="submit" class="btn btn-success w-100">
                                <i class="fas fa-check"></i> Record Payment &amp; Convert to Booking
                            </button>
                        </form>
                    </section>
                @endif
            @else
                {{-- ===================== BOOKING MODE ===================== --}}
                <section class="detail-section">
                    <h6 class="detail-section-title"><i class="fas fa-receipt"></i> Billing</h6>
                    @if($billing)
                        <table class="table table-sm mb-0">
                            <tr>
                                <td>Room Charge</td>
                                <td class="text-end">₱{{ number_format($billing->room_charge, 2) }}</td>
                            </tr>
                            <tr>
                                <td>Additional Guest Fee</td>
                                <td class="text-end">₱{{ number_format($billing->additional_guest_fee, 2) }}</td>
                            </tr>
                            <tr>
                                <td>Amenity Charge</td>
                                <td class="text-end">₱{{ number_format($billing->amenity_charge, 2) }}</td>
                            </tr>
                            <tr>
                                <td>Discount</td>
                                <td class="text-end text-success">- ₱{{ number_format($billing->discount, 2) }}</td>
                            </tr>
                            <tr class="fw-bold border-top">
                                <td>Total</td>
                                <td class="text-end text-brand">₱{{ number_format($billing->total_amount, 2) }}</td>
                            </tr>
                        </table>
                        @if($billing->payments->where('payment_status', 'pending')->isNotEmpty())
                            <div class="alert alert-warning py-2 mt-3 mb-0">
                                <i class="fas fa-clock"></i> Has a payment awaiting verification -
                                <a href="{{ route('receptionist.payments.pending') }}">go to the verification queue</a>.
                            </div>
                        @endif
                        <a href="{{ route('receptionist.billing.receipt', $billing) }}" target="_blank" class="btn btn-sm btn-outline-primary mt-3">
                            <i class="fas fa-external-link-alt"></i> View Receipt
                        </a>
                    @else
                        <p class="text-muted mb-0">No billing record yet.</p>
                    @endif
                </section>

                @if($reservation->status === 'confirmed')
                    <section class="detail-section">
                        <h6 class="detail-section-title"><i class="fas fa-sign-in-alt"></i> Prepare for Check-In</h6>
                        @if(!$reservation->room)
                            <p class="text-muted small mb-3">This booking has no room assigned yet. Assign one before checking the guest in.</p>
                            @if($assignableRooms->isEmpty())
                                <div class="alert alert-danger mb-0 py-2">
                                    <i class="fas fa-exclamation-triangle"></i> No {{ $reservation->roomType->name ?? '' }} room is currently free for these dates.
                                </div>
                            @else
                                <form id="assignRoomForm">
                                    <label class="form-label"><strong>Assign Room *</strong></label>
                                    <select name="room_id" class="form-select mb-2" required>
                                        <option value="">-- Select room --</option>
                                        @foreach($assignableRooms as $room)
                                            <option value="{{ $room->id }}">
                                                Room {{ $room->room_number }} — {{ $room->room_name }} ({{ $room->room_capacity }} guests)
                                            </option>
                                        @endforeach
                                    </select>
                                    <button type="submit" class="btn btn-primary">
                                        <i class="fas fa-door-open"></i> Assign Room
                                    </button>
                                </form>
                            @endif
                        @else
                            <p class="mb-2">
                                Room <strong>{{ $reservation->room->room_number }}</strong> ({{ $reservation->room->room_name }}) is assigned.
                                Status: <x-status-badge :status="$reservation->room->status" domain="room" />
                            </p>
                            @if(in_array($reservation->room->status, ['occupied', 'maintenance']))
                                <div class="alert alert-danger py-2 mb-3">
                                    <i class="fas fa-exclamation-triangle"></i> This room is not ready ({{ $reservation->room->status }}). Free it up or assign a different room before check-in.
                                </div>
                            @endif
                            <button type="button" class="btn btn-success btn-lg w-100" id="checkInGuestBtn"
                                    {{ in_array($reservation->room->status, ['occupied', 'maintenance']) ? 'disabled' : '' }}>
                                <i class="fas fa-check"></i> Check In Guest
                            </button>
                        @endif
                    </section>
                @endif
            @endif
        </div>
    </div>

    <!-- Amenity Requests (full width, both modes) -->
    <section class="detail-section mt-2">
        <div class="d-flex align-items-center justify-content-between">
            <h6 class="detail-section-title mb-0"><i class="fas fa-spa"></i> Amenity Requests</h6>
            <button type="button" class="btn btn-sm btn-outline-secondary" id="showAddAmenityFormBtn">
                <i class="fas fa-plus"></i> Add
            </button>
        </div>

        <form id="addAmenityForm" class="d-none mt-3 border rounded p-3 bg-light">
            <div class="row g-2 align-items-end">
                <div class="col-md-5">
                    <label class="form-label small mb-1">Amenity</label>
                    <select name="amenity_id" class="form-select form-select-sm" required>
                        <option value="">-- Select --</option>
                        @foreach($amenities as $amenity)
                            <option value="{{ $amenity->id }}">
                                {{ $amenity->amenity_name }} — ₱{{ number_format($amenity->charge, 2) }}
                            </option>
                        @endforeach
                    </select>
                </div>
                <div class="col-md-2">
                    <label class="form-label small mb-1">Qty</label>
                    <input type="number" name="quantity" class="form-control form-control-sm" min="1" value="1" required>
                </div>
                <div class="col-md-3">
                    <label class="form-label small mb-1">Status</label>
                    <select name="status" class="form-select form-select-sm" required>
                        <option value="approved">Approved</option>
                        <option value="pending">Pending</option>
                        <option value="rejected">Rejected</option>
                    </select>
                </div>
                <div class="col-md-2">
                    <button type="submit" class="btn btn-sm btn-primary w-100">Save</button>
                </div>
            </div>
        </form>

        <div class="table-responsive mt-3">
            <table class="table table-hover table-sm mb-0">
                <thead>
                    <tr>
                        <th>Amenity</th>
                        <th>Quantity</th>
                        <th>Charge</th>
                        <th>Status</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse($reservation->amenityRequests as $req)
                        <tr>
                            <td>{{ $req->amenity->amenity_name ?? 'N/A' }}</td>
                            <td>{{ $req->quantity }}</td>
                            <td>₱{{ number_format($req->charge * $req->quantity, 2) }}</td>
                            <td><x-status-badge :status="$req->status" domain="amenity_request" /></td>
                        </tr>
                    @empty
                        <tr><td colspan="4"><x-empty-state icon="fas fa-spa" message="No amenity requests." /></td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </section>
</div>

<div class="modal-footer">
    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
</div>
