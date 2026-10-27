{{--
    Discount (ID) or promotion for the receptionist's Create Reservation,
    Create Booking and Walk-in Check-in forms (App\Services\StayOffersService).
    One or the other, never both.

    @param \Illuminate\Support\Collection $offerDiscounts
    @param \Illuminate\Support\Collection $offerPromotions  running promotions (room type checked on save)
    @param bool $canApproveNow  bookings can approve the ID right away
    @param bool $walkIn         Walk-in Check-in: the guest is checked in now, so the ID is checked and approved here
--}}
@php $offer = old('offer', 'none'); @endphp
<h6 class="form-section-heading mt-3">Discount or Promotion <small class="text-muted fw-normal">(optional - one or the other)</small></h6>
<div class="offer-field mb-3">
    <div class="btn-group mb-2" role="group" aria-label="Discount or promotion">
        @foreach(['none' => 'None', 'discount' => 'Discount (ID)', 'promotion' => 'Promotion'] as $value => $label)
            <input type="radio" class="btn-check" name="offer" id="offer{{ $value }}" value="{{ $value }}" {{ $offer === $value ? 'checked' : '' }}>
            <label class="btn btn-sm btn-outline-primary" for="offer{{ $value }}">{{ $label }}</label>
        @endforeach
    </div>

    <div class="row g-2 {{ $offer === 'discount' ? '' : 'd-none' }}" data-offer="discount">
        <div class="col-md-6">
            <label class="form-label small mb-1" for="offerDiscount">Discount *</label>
            <select name="discount_id" id="offerDiscount" class="form-select @error('discount_id') is-invalid @enderror">
                <option value="">Choose...</option>
                @foreach($offerDiscounts as $d)
                    <option value="{{ $d->id }}" data-type="{{ $d->discount_type }}" data-value="{{ (float) $d->value }}" data-label="{{ $d->name }}" {{ (string) old('discount_id') === (string) $d->id ? 'selected' : '' }}>{{ $d->name }} ({{ $d->discount_type === 'percentage' ? rtrim(rtrim(number_format($d->value, 2), '0'), '.') . '%' : '₱' . number_format($d->value, 2) }})</option>
                @endforeach
            </select>
            @error('discount_id')<div class="invalid-feedback">{{ $message }}</div>@enderror
        </div>
        <div class="col-md-6">
            <label class="form-label small mb-1" for="offerIdPhoto">Photo of the guest's ID{{ ($walkIn ?? false) ? ' *' : '' }}</label>
            <input type="file" name="id_document" id="offerIdPhoto" accept="image/*" class="form-control @error('id_document') is-invalid @enderror">
            @error('id_document')<div class="invalid-feedback">{{ $message }}</div>@enderror
        </div>
        @if($walkIn ?? false)
            <div class="col-12">
                <input type="hidden" name="approve_discount" value="1">
                <small class="text-muted"><i class="fas fa-id-card"></i> Check the guest's ID now - the discount is approved when you check them in.</small>
            </div>
        @elseif($canApproveNow ?? false)
            <div class="col-12">
                <div class="form-check">
                    <input type="hidden" name="approve_discount" value="0">
                    <input class="form-check-input" type="checkbox" name="approve_discount" value="1" id="offerApprove" {{ old('approve_discount') ? 'checked' : '' }}>
                    <label class="form-check-label small" for="offerApprove">I checked the ID - approve the discount now (otherwise it's verified at check-in)</label>
                </div>
            </div>
        @else
            <div class="col-12"><small class="text-muted">The ID is verified when the reservation becomes a booking, or at check-in.</small></div>
        @endif
    </div>

    <div class="{{ $offer === 'promotion' ? '' : 'd-none' }}" data-offer="promotion">
        <label class="form-label small mb-1" for="offerPromotion">Promotion *</label>
        <select name="promotion_id" id="offerPromotion" class="form-select @error('promotion_id') is-invalid @enderror">
            <option value="">Choose...</option>
            @foreach($offerPromotions as $promo)
                <option value="{{ $promo->id }}" {{ (string) old('promotion_id') === (string) $promo->id ? 'selected' : '' }}>
                    {{ $promo->promo_name }} - {{ $promo->offerSummary() }}{{ $promo->roomType && ! $promo->hasDiscount() ? ' (' . $promo->roomType->name . ' only)' : '' }}
                </option>
            @endforeach
        </select>
        <small class="text-muted">Its amenities are added free and its discount comes off the rate of its room type only. It must be running on the check-in date for the chosen room type.</small>
        @error('promotion_id')<div class="invalid-feedback d-block">{{ $message }}</div>@enderror
    </div>
</div>

@once
@push('scripts')
<script>
document.addEventListener('DOMContentLoaded', function () {
    document.querySelectorAll('.offer-field').forEach(function (box) {
        const sync = function () {
            const v = (box.querySelector('input[name="offer"]:checked') || {}).value || 'none';
            box.querySelectorAll('[data-offer]').forEach(function (el) { el.classList.toggle('d-none', el.dataset.offer !== v); });
        };
        box.querySelectorAll('input[name="offer"]').forEach(function (r) { r.addEventListener('change', sync); });
        sync();
    });
});
</script>
@endpush
@endonce
