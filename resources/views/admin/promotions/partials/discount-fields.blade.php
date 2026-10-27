{{--
    A promo's optional discount (alongside, or instead of, its free amenities): has_discount + discount_type +
    discount_value + discount_applies_to, the contract Admin\PromotionManagementController::validatePromotion() expects.
    It comes off EITHER the nightly rate of rooms of the promo's room type, OR the stay's total from before check-in
    (Promotion::discountFor).
    @param \App\Models\Promotion|null $promotion
--}}
@php
    $hasDiscount = (bool) old('has_discount', $promotion?->hasDiscount() ?? false);
    $discountType = old('discount_type', $promotion?->discount_type ?? 'percentage');
    $appliesTo = old('discount_applies_to', $promotion?->discount_applies_to ?? 'rate');
    $discountValue = old('discount_value', $promotion?->hasDiscount() ? rtrim(rtrim(number_format((float) $promotion->discount_value, 2, '.', ''), '0'), '.') : '');
@endphp
<div class="form-group mb-3 border rounded p-3" id="promoDiscountBox">
    <div class="form-check form-switch mb-0">
        <input type="hidden" name="has_discount" value="0">
        <input class="form-check-input" type="checkbox" role="switch" name="has_discount" value="1" id="has_discount" {{ $hasDiscount ? 'checked' : '' }}>
        <label class="form-check-label fw-semibold" for="has_discount">Also give a discount</label>
    </div>
    <div class="row g-2 mt-1 {{ $hasDiscount ? '' : 'd-none' }}" id="promoDiscountFields">
        <div class="col-12">
            <label class="form-label small mb-1 d-block">Discount Comes Off *</label>
            <div class="btn-group w-100" role="group" aria-label="What the discount comes off">
                <input type="radio" class="btn-check" name="discount_applies_to" id="appliesRate" value="rate" {{ $appliesTo !== 'total' ? 'checked' : '' }}>
                <label class="btn btn-outline-primary" for="appliesRate"><i class="fas fa-bed"></i> Room rate</label>
                <input type="radio" class="btn-check" name="discount_applies_to" id="appliesTotal" value="total" {{ $appliesTo === 'total' ? 'checked' : '' }}>
                <label class="btn btn-outline-primary" for="appliesTotal"><i class="fas fa-receipt"></i> Total (before check-in)</label>
            </div>
            @error('discount_applies_to')<div class="text-danger small">{{ $message }}</div>@enderror
        </div>
        <div class="col-sm-6">
            <label for="discount_type" class="form-label small mb-1">Discount Type *</label>
            <select class="form-select @error('discount_type') is-invalid @enderror" id="discount_type" name="discount_type">
                <option value="percentage" {{ $discountType === 'percentage' ? 'selected' : '' }}>Percentage (%)</option>
                <option value="fixed" {{ $discountType === 'fixed' ? 'selected' : '' }}>Fixed amount (₱)</option>
            </select>
            @error('discount_type')<div class="invalid-feedback">{{ $message }}</div>@enderror
        </div>
        <div class="col-sm-6">
            <label for="discount_value" class="form-label small mb-1">Discount Value *</label>
            <input type="number" step="0.01" min="0.01" class="form-control @error('discount_value') is-invalid @enderror"
                   id="discount_value" name="discount_value" value="{{ $discountValue }}">
            @error('discount_value')<div class="invalid-feedback">{{ $message }}</div>@enderror
        </div>
        <div class="col-12">
            <small class="text-muted d-block {{ $appliesTo === 'total' ? 'd-none' : '' }}" data-applies-help="rate"><i class="fas fa-info-circle"></i>
                Taken off each night's rate of the rooms of the <strong>Applicable Room Type</strong> below (every room when it's
                All Room Types) - a fixed amount is per room, per night. Other room types on the same booking pay their full rate.</small>
            <small class="text-muted d-block {{ $appliesTo === 'total' ? '' : 'd-none' }}" data-applies-help="total"><i class="fas fa-info-circle"></i>
                Taken off the booking's total from <strong>before check-in</strong> - the rooms plus the amenities booked up to
                check-in - once. Anything added during the stay or at check-out (extra amenities, damages, late check-out) is
                charged in full. With a room type set, only bookings with that room type can use it.</small>
            <small class="text-muted d-block mt-1">A promo can't be combined with an ID discount.</small>
        </div>
    </div>
</div>

@push('scripts')
<script>
document.addEventListener('DOMContentLoaded', function () {
    const toggle = document.getElementById('has_discount');
    const fields = document.getElementById('promoDiscountFields');
    const type = document.getElementById('discount_type');
    const value = document.getElementById('discount_value');
    const sync = function () {
        fields.classList.toggle('d-none', !toggle.checked);
        value.required = toggle.checked;
        value.max = type.value === 'percentage' ? '100' : '';
        const total = document.getElementById('appliesTotal').checked;
        document.querySelectorAll('[data-applies-help]').forEach(function (el) {
            el.classList.toggle('d-none', (el.dataset.appliesHelp === 'total') !== total);
        });
    };
    document.querySelectorAll('input[name="discount_applies_to"]').forEach(function (r) { r.addEventListener('change', sync); });
    toggle.addEventListener('change', sync);
    type.addEventListener('change', sync);
    sync();
});
</script>
@endpush
