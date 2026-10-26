<div class="modal-header modal-header-brand">
    <h5 class="modal-title"><i class="fas fa-cash-register"></i> Billing</h5>
    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
</div>
<div class="modal-body" data-billing-id="{{ $billing->id }}" data-booking-id="{{ $booking->id }}">
    <div class="alert alert-danger d-none" id="billingErrorAlert"></div>

    <h6><i class="fas fa-user"></i> 1. Guest &amp; Booking Info</h6>
    <div class="row mb-3">
        <div class="col-md-6">
            <strong>Guest:</strong> {{ $booking->guest_display_name }}<br>
            <strong>Booking:</strong> #{{ $booking->id }}<br>
            <strong>Room{{ $booking->rooms->count() > 1 ? 's' : '' }}:</strong> {{ $booking->rooms->pluck('room_number')->implode(', ') ?: 'N/A' }}
        </div>
        <div class="col-md-6 text-md-end">
            <strong>Check-In:</strong> {{ $booking->check_in->format('M d, Y') }}<br>
            <strong>Check-Out:</strong> {{ $effectiveCheckOutDate->format('M d, Y') }}
            @if($isEarlyCheckout)
                <span class="badge bg-info" title="Leaving before the booked check-out date. The booked nights are still charged.">Leaving early</span>
            @elseif($isLateCheckout)
                <span class="badge bg-warning text-dark" title="Leaving after the booked check-out date. Add a late check-out charge below if one applies.">Late check-out</span>
            @endif
            <br>
            <strong>Nights:</strong> {{ $effectiveNights }} (booked)
            <br><span class="small text-muted"><i class="fas fa-info-circle"></i> {{ $stay['no_refund_note'] }}</span>
        </div>
    </div>

    @if($settledAtCheckIn)
        <div class="alert alert-success py-2 small">
            <i class="fas fa-check-circle"></i> The stay was paid in full at check-in ({{ $settledAtCheckIn->format('M j, Y g:i A') }}).
            Items marked <span class="badge bg-success">Paid at check-in</span> are not charged again - only what was added since is due now.
        </div>
    @endif

    @php
        $peso = fn ($v) => '₱'.number_format((float) $v, 2);
        $rb = $stay['receipt_breakdown'] ?? null;
        $bookedAmenities = $settledAtCheckIn ? $amenityRequests->filter(fn ($r) => \App\Support\CheckInSettlement::wasOnCheckInBill($billing, $r->created_at)) : $amenityRequests;
        $addedAmenities = $settledAtCheckIn ? $amenityRequests->reject(fn ($r) => \App\Support\CheckInSettlement::wasOnCheckInBill($billing, $r->created_at)) : collect();
    @endphp

    {{-- SECTION 1 - the bill as it stood at check-in: read-only, the discount is already taken off and is never touched again. --}}
    <div class="card mb-3 border-secondary">
        <div class="card-header bg-light d-flex justify-content-between align-items-center">
            <strong><i class="fas fa-lock"></i> 2. Check-in Billing</strong>
            <span class="badge bg-secondary">Read-only{{ $settledAtCheckIn ? ' - discounted' : '' }}</span>
        </div>
        <div class="card-body py-2">
            <p class="small text-muted mb-2">
                Booked {{ \Carbon\Carbon::parse($stay['check_in'])->format('M j, Y') }} to {{ \Carbon\Carbon::parse($stay['scheduled_check_out'])->format('M j, Y') }}
                ({{ $stay['scheduled_nights'] }} night{{ $stay['scheduled_nights'] === 1 ? '' : 's' }}). The booked nights are always charged.
            </p>
            <div class="table-responsive">
            <table class="table table-sm table-borderless align-middle mb-2">
                <colgroup><col><col style="width: 9rem;"></colgroup>
                @foreach($stay['rooms'] as $line)
                    <tr>
                        <td>
                            Room {{ $line['room_number'] }}@if($line['room_type']) <span class="text-muted">({{ $line['room_type'] }})</span>@endif
                            - {{ $peso($line['rate']) }} x {{ $line['nights'] }} night{{ $line['nights'] === 1 ? '' : 's' }}
                            @if($line['status'] === 'checked_out')<span class="badge bg-secondary">out {{ \Carbon\Carbon::parse($line['checked_out_on'])->format('M j') }}</span>@endif
                        </td>
                        <td class="text-end">{{ $peso($line['subtotal']) }}</td>
                    </tr>
                @endforeach
                <tr class="fw-semibold border-top">
                    <td>Room Charge</td>
                    <td class="text-end">{{ $peso($billing->room_charge) }}</td>
                </tr>
                @if($billing->additional_guest_fee > 0)
                    <tr>
                        <td>Additional Guest Fee</td>
                        <td class="text-end">{{ $peso($billing->additional_guest_fee) }}</td>
                    </tr>
                @endif
                @foreach($bookedAmenities as $req)
                    <tr>
                        <td>{{ $req->amenity->amenity_name ?? $req->amenity_name ?? 'N/A' }} <span class="text-muted">x{{ $req->quantity }}</span> @if($req->origin === \App\Models\AmenityRequest::ORIGIN_INCLUDED) <span class="badge rounded-pill bg-success-subtle text-success-emphasis">Included with room</span>@endif</td>
                        <td class="text-end">{{ $peso($req->charge * $req->quantity) }}</td>
                    </tr>
                @endforeach
                @if($rb)
                    @if($rb['checkin']['discount'] > 0)
                        <tr>
                            <td>Less: Discount{{ $rb['checkin']['discount_name'] ? ' ('.$rb['checkin']['discount_name'].')' : '' }}</td>
                            <td class="text-end text-success">-{{ $peso($rb['checkin']['discount']) }}</td>
                        </tr>
                    @endif
                    <tr class="fw-bold border-top">
                        <td>Check-in Grand Total</td>
                        <td class="text-end">{{ $peso($rb['checkin']['grand_total']) }}</td>
                    </tr>
                    <tr>
                        <td>Paid at Check-in <span class="badge {{ $rb['checkin']['paid_in_full'] ? 'bg-success' : 'bg-warning text-dark' }}">{{ $rb['checkin']['status'] }}</span></td>
                        <td class="text-end">{{ $peso($rb['checkin']['amount_paid']) }}</td>
                    </tr>
                @elseif($billing->discount > 0)
                    <tr>
                        <td>Less: Discount{{ $billing->discountApplied ? ' ('.$billing->discountApplied->name.')' : '' }}</td>
                        <td class="text-end text-success">-{{ $peso($billing->discount) }}</td>
                    </tr>
                @endif
            </table>
            </div>
            <p class="small text-muted mb-0"><i class="fas fa-percentage"></i> The discount applies to the check-in bill only. Check-out charges below are never discounted.</p>
        </div>
    </div>

    @if($pendingAmenityCount > 0)
        <div class="alert alert-warning d-flex align-items-start gap-2" role="alert">
            <i class="fas fa-triangle-exclamation mt-1"></i>
            <div>
                <strong>{{ $pendingAmenityCount }} amenity request{{ $pendingAmenityCount === 1 ? '' : 's' }} still pending.</strong>
                Pending requests are not on this bill. Approve or reject them first so the guest is billed correctly -
                <a href="{{ route('receptionist.amenities.index', ['status' => 'pending', 'search' => $booking->reservation_id ?? $booking->id]) }}" target="_blank" rel="noopener">review them</a>.
            </div>
        </div>
    @endif

    {{-- SECTION 2 - what is charged now (the remaining balance): approved amenities added since check-in + the desk's charges. No discount. --}}
    <div class="card mb-3 border-primary">
        <div class="card-header d-flex justify-content-between align-items-center">
            <strong><i class="fas fa-pen-to-square"></i> 3. Check-out Charges</strong>
            <span class="badge bg-primary">Editable - no discount</span>
        </div>
        <div class="card-body py-2">
            <h6 class="small text-uppercase text-muted"><i class="fas fa-spa"></i> Approved amenities{{ $settledAtCheckIn ? ' added during the stay' : '' }}</h6>
            <div class="table-responsive">
            <table class="table table-sm table-borderless align-middle mb-3">
                <colgroup><col><col style="width: 5rem;"><col style="width: 9rem;"></colgroup>
                <tbody>
                    @forelse($addedAmenities as $req)
                        <tr>
                            <td>{{ $req->amenity->amenity_name ?? $req->amenity_name ?? 'N/A' }} @if($req->origin === \App\Models\AmenityRequest::ORIGIN_INCLUDED) <span class="badge rounded-pill bg-success-subtle text-success-emphasis">Included with room</span>@endif</td>
                            <td class="text-end">x{{ $req->quantity }}</td>
                            <td class="text-end">{{ $peso($req->charge * $req->quantity) }}</td>
                        </tr>
                    @empty
                        <tr><td colspan="3" class="text-muted py-1">No amenities approved after check-in.</td></tr>
                    @endforelse
                    @if($addedAmenities->isNotEmpty())
                        <tr class="fw-semibold border-top">
                            <td colspan="2">Amenities total</td>
                            <td class="text-end">{{ $peso($addedAmenities->sum(fn ($r) => $r->charge * $r->quantity)) }}</td>
                        </tr>
                    @endif
                </tbody>
            </table>
            </div>

            <div id="chargesTableContainer">
                @include('receptionist.check-out.partials.charges-table', ['billing' => $billing])
            </div>
        </div>
    </div>

    {{-- Payments already made: the receipts issued before check-out (stored, frozen). --}}
    @php
        $storedReceipts = \App\Models\Receipt::where('booking_id', $booking->id)->orderBy('receipt_type')->get();
        $receiptNames = [1 => 'Receipt 1 - Booking verification', 2 => 'Receipt 2 - Check-in payment'];
        $paidBeforeCheckout = round((float) $booking->paidTotal(), 2);
    @endphp
    <div class="card mb-3">
        <div class="card-header bg-light"><strong><i class="fas fa-receipt"></i> 4. Payments Already Made</strong></div>
        <div class="card-body py-2">
            <div class="table-responsive">
            <table class="table table-sm table-borderless align-middle mb-0">
                <colgroup><col><col style="width: 9rem;"></colgroup>
                @foreach($storedReceipts->whereIn('receipt_type', [1, 2]) as $r)
                    <tr>
                        <td>{{ $receiptNames[$r->receipt_type] }} <span class="text-muted small">{{ $r->receipt_no }}</span></td>
                        <td class="text-end">{{ $peso((float) ($r->snapshot['highlight']['amount_paid_now'] ?? 0)) }}</td>
                    </tr>
                @endforeach
                <tr class="fw-semibold border-top">
                    <td>Total paid so far</td>
                    <td class="text-end">{{ $peso($paidBeforeCheckout) }}</td>
                </tr>
            </table>
            </div>
        </div>
    </div>

    {{-- The one figure the desk collects (or hands back) - prominent and right-aligned. --}}
    <div class="card border-0 mb-0" style="background: rgba(139, 0, 0, .06);">
        <div class="card-body py-2">
            <h6 class="mb-2"><i class="fas fa-calculator"></i> 5. Payment Summary</h6>
            <div class="table-responsive">
            <table class="table table-sm table-borderless align-middle mb-0">
                <colgroup><col><col style="width: 9rem;"></colgroup>
                <tr class="{{ $settledAtCheckIn ? 'fw-semibold' : 'fw-bold fs-5' }}">
                    <td>{{ $settledAtCheckIn ? 'Final Grand Total' : 'Running Total' }}</td>
                    <td class="text-end {{ $settledAtCheckIn ? '' : 'text-brand' }}" id="runningTotalDisplay">{{ $peso($billing->running_total) }}</td>
                </tr>
                @if($settledAtCheckIn)
                    <tr>
                        <td>Total paid so far</td>
                        <td class="text-end text-success">-{{ $peso($alreadyPaid) }}</td>
                    </tr>
                    @php $toReturn = round(max(0, $alreadyPaid - (float) $billing->running_total), 2); @endphp
                    <tr class="fw-bold fs-4 border-top">
                        <td id="dueAtCheckOutLabel">{{ $toReturn > 0 ? 'Return to the guest' : 'AMOUNT DUE' }}</td>
                        <td class="text-end text-brand" id="dueAtCheckOutDisplay" data-paid="{{ $alreadyPaid }}">{{ $peso($toReturn > 0 ? $toReturn : $dueNow) }}</td>
                    </tr>
                @endif
            </table>
            </div>
        </div>
    </div>
</div>
<div class="modal-footer">
    @unless($settledAtCheckIn)
        <button type="button" class="btn btn-secondary" id="cancelBillingBtn">Cancel Billing</button>
    @endunless
    <button type="button" class="btn btn-primary" id="proceedToPaymentBtn">
        <i class="fas fa-arrow-right"></i> Continue to Payment
    </button>
</div>
