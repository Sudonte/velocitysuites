{{--
    Check In panel, step 1 - "Discount & Bill". Every check-in starts here:
    the discount is checked first (approve / reject the guest's ID, upload an
    ID photo now, or apply a promotion instead), then the bill as of check-in
    and what's owed. Actions reload the panel (nothing typed yet is lost).
--}}
@php $peso = fn ($v) => '₱' . number_format((float) $v, 2); @endphp
<div id="checkInStepBill">
    <h6 class="mb-2"><i class="fas fa-percent"></i> Discount or Promotion</h6>
    <div class="border rounded p-2 mb-3" id="checkInOfferBox"
         data-approve-url="{{ route('receptionist.check-in.discount.approve', $booking) }}"
         data-reject-url="{{ route('receptionist.check-in.discount.reject', $booking) }}"
         data-promotion-url="{{ route('receptionist.check-in.promotion', $booking) }}">
        <div class="alert alert-danger py-1 px-2 small d-none mb-2" data-offer-error></div>
        @if($discountRequested && $discountStatus === 'pending')
            <div class="alert alert-warning py-1 px-2 small mb-2" id="discountPendingReminder">
                <i class="fas fa-exclamation-triangle"></i> The guest's discount ID is still pending. Approve or reject it before you can continue with check-in.
            </div>
        @endif

        @if($discountStatus === 'approved')
            <div class="d-flex justify-content-between align-items-center">
                <span><span class="badge bg-success">Discount approved</span> {{ $stay['discount_name'] ?? $claimedDiscount?->name }} &middot; &minus;{{ $peso($stay['discount']) }}</span>
                @if($idCardUrl)<a href="{{ $idCardUrl }}" target="_blank" rel="noopener" class="small">View ID</a>@endif
            </div>
        @elseif($appliedPromotion)
            <div class="d-flex justify-content-between align-items-center">
                <span><span class="badge bg-info text-dark">Promotion</span> {{ $appliedPromotion->promo_name }} - its amenities are included free</span>
                <button type="button" class="btn btn-sm btn-outline-secondary" data-offer-action="promotion" data-promotion-id="">Remove</button>
            </div>
        @else
            @if($discountRequested && $hasIdPhoto)
                <p class="small mb-2">
                    <span class="badge bg-warning text-dark">ID to verify</span>
                    The guest asked for {{ $claimedDiscount?->name ?? 'a discount' }}.
                    @if($idCardUrl)<a href="{{ $idCardUrl }}" target="_blank" rel="noopener">Open the ID photo</a>@endif
                </p>
            @elseif($discountStatus === 'rejected')
                <p class="small mb-2"><span class="badge bg-danger">ID rejected</span> No discount applies. You can upload a new ID photo or apply a promotion.</p>
            @else
                <p class="small text-muted mb-2">No discount requested. Upload the guest's ID to apply one, or apply a promotion instead.</p>
            @endif

            {{-- Inputs on one row, actions on their own row below so they always fit inside the panel. --}}
            <div class="row g-2">
                <div class="col-md-6">
                    <label class="form-label small mb-1">Discount</label>
                    <select class="form-select form-select-sm" data-offer-field="discount_id">
                        @foreach($discountChoices as $d)
                            <option value="{{ $d->id }}" data-label="{{ $d->name }}" {{ $claimedDiscount?->id === $d->id ? 'selected' : '' }}>{{ $d->name }} ({{ $d->discount_type === 'percentage' ? rtrim(rtrim(number_format($d->value, 2), '0'), '.') . '%' : '₱' . number_format($d->value, 2) }})</option>
                        @endforeach
                    </select>
                </div>
                <div class="col-md-6">
                    <label class="form-label small mb-1">{{ $hasIdPhoto ? 'Replace ID photo (optional)' : 'ID photo *' }}</label>
                    <input type="file" accept="image/*" class="form-control form-control-sm" data-offer-field="id_document">
                </div>
            </div>
            <div class="d-flex flex-wrap gap-2 justify-content-end mt-2">
                @if($discountRequested && $hasIdPhoto)
                    <button type="button" class="btn btn-sm btn-outline-danger" data-offer-action="reject-open"><i class="fas fa-times"></i> Reject ID</button>
                @endif
                <button type="button" class="btn btn-sm btn-success" data-offer-action="approve"><i class="fas fa-check"></i> Approve Discount</button>
            </div>
            @if($discountRequested && $hasIdPhoto)
                <div class="border rounded p-2 mt-2 d-none" data-reject-box>
                    <label class="form-label small mb-1">Reason for rejecting the ID * <small class="text-muted">(shown to the guest)</small></label>
                    <textarea class="form-control form-control-sm" rows="2" maxlength="500" data-offer-field="reason"></textarea>
                    <div class="d-flex gap-2 justify-content-end mt-2">
                        <button type="button" class="btn btn-sm btn-outline-secondary" data-offer-action="reject-cancel">Cancel</button>
                        <button type="button" class="btn btn-sm btn-danger" data-offer-action="reject">Reject ID</button>
                    </div>
                </div>
            @endif

            @if($promotionChoices->isNotEmpty())
                <hr class="my-2">
                <div class="row g-2 align-items-end">
                    <div class="col-sm-9">
                        <label class="form-label small mb-1">Or apply a promotion</label>
                        <select class="form-select form-select-sm" data-offer-field="promotion_id">
                            @foreach($promotionChoices as $promo)
                                <option value="{{ $promo->id }}">{{ $promo->promo_name }} - {{ $promo->amenities->map(fn ($a) => $a->pivot->quantity . ' x ' . $a->amenity_name)->implode(', ') }}</option>
                            @endforeach
                        </select>
                    </div>
                    <div class="col-sm-3"><button type="button" class="btn btn-sm btn-outline-primary w-100" data-offer-action="promotion">Apply</button></div>
                </div>
            @endif
        @endif
    </div>

    <h6 class="mb-2"><i class="fas fa-file-invoice"></i> Payment Summary</h6>
    <div class="border rounded p-2 mb-2 small">
        @foreach($stay['rooms'] as $line)
            <div class="d-flex justify-content-between"><span>{{ $line['room_type'] ?? 'Room' }} &middot; {{ $peso($line['rate']) }} &times; {{ \App\Support\StayBill::nightsLabel($line['nights']) }}</span><span>{{ $peso($line['subtotal']) }}</span></div>
        @endforeach
        @foreach($stay['amenity_lines'] as $a)
            <div class="d-flex justify-content-between"><span>{{ $a['amenity_name'] }} &times; {{ $a['quantity'] }}</span><span>{{ $a['subtotal'] > 0 ? $peso($a['subtotal']) : 'Free' }}</span></div>
        @endforeach
        @if($stay['discount'] > 0)
            <div class="d-flex justify-content-between text-success"><span>Discount</span><span>&minus;{{ $peso($stay['discount']) }}</span></div>
        @endif
        <hr class="my-1">
        <div class="d-flex justify-content-between fw-bold"><span>Total</span><span>{{ $peso($payable) }}</span></div>
        <div class="d-flex justify-content-between"><span>Already paid</span><span>{{ $peso($paidSoFar) }}</span></div>
        @if($balanceDue > 0)
            <div class="d-flex justify-content-between fw-bold text-danger"><span>To pay now (in full)</span><span>{{ $peso($balanceDue) }}</span></div>
        @elseif($changeDue > 0)
            <div class="d-flex justify-content-between fw-bold text-primary"><span>Change to hand back</span><span>{{ $peso($changeDue) }}</span></div>
            <div class="d-flex justify-content-between text-muted"><span>Kept as paid (the actual total)</span><span>{{ $peso($payable) }}</span></div>
        @else
            <div class="d-flex justify-content-between fw-bold text-success"><span>Paid in full</span><span><i class="fas fa-check"></i></span></div>
        @endif
    </div>
    <p class="text-muted small mb-0"><i class="fas fa-info-circle"></i> The full balance is paid at check-in. Anything added during the stay is paid at check-out.</p>
</div>
