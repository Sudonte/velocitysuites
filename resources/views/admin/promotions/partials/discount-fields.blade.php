{{--
    A promo's optional discount (alongside, or instead of, its free amenities): has_discount + discount_type +
    discount_value, the contract Admin\PromotionManagementController::validatePromotion() expects. It comes off the
    nightly rate of rooms of the promo's room type only (Promotion::discountForRoom).
    @param \App\Models\Promotion|null $promotion
--}}
@php
    $hasDiscount = (bool) old('has_discount', $promotion?->hasDiscount() ?? false);
    $discountType = old('discount_type', $promotion?->discount_type ?? 'percentage');
    $discountValue = old('discount_value', $promotion?->hasDiscount() ? rtrim(rtrim(number_format((float) $promotion->discount_value, 2, '.', ''), '0'), '.') : '');
@endphp
<div class="form-group mb-3 border rounded p-3" id="promoDiscountBox">
    <div class="form-check form-switch mb-0">
        <input type="hidden" name="has_discount" value="0">
        <input class="form-check-input" type="checkbox" role="switch" name="has_discount" value="1" id="has_discount" {{ $hasDiscount ? 'checked' : '' }}>
        <label class="form-check-label fw-semibold" for="has_discount">Also give a discount off the room rate</label>
    </div>
    <div class="row g-2 mt-1 {{ $hasDiscount ? '' : 'd-none' }}" id="promoDiscountFields">
        <div class="col-sm-6">
            <label for="discount_type" class="form-label small mb-1">Discount Type *</label>
            <select class="form-select @error('discount_type') is-invalid @enderror" id="discount_type" name="discount_type">
                <option value="percentage" {{ $discountType === 'percentage' ? 'selected' : '' }}>Percentage (%) off the rate</option>
                <option value="fixed" {{ $discountType === 'fixed' ? 'selected' : '' }}>Fixed amount (₱) off per night</option>
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
            <small class="text-muted"><i class="fas fa-info-circle"></i> Taken off each night's rate of the rooms of the
                <strong>Applicable Room Type</strong> below (every room when it's All Room Types). Other room types on the
                same booking pay their full rate. A promo can't be combined with an ID discount.</small>
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
    };
    toggle.addEventListener('change', sync);
    type.addEventListener('change', sync);
    sync();
});
</script>
@endpush
