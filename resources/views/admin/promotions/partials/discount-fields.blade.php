{{--
    A promo's discount on the ROOM RATE (alongside, or instead of, its free amenities): has_discount + discount_type +
    discount_value (+ discount_applies_to, always "rate" for a promo made here), the contract
    Admin\PromotionManagementController::validatePromotion() expects. It comes off the nightly rate of EACH room of the
    promo's room type, for every booked night - never amenities, other charges or other room types.
    New promos need a value; an older promo without one stays on the list as display only.
    @param \App\Models\Promotion|null $promotion
--}}
@php
    $isNew = $promotion === null;
    $hasDiscount = (bool) old('has_discount', $isNew ? true : ($promotion?->hasDiscount() ?? false));
    $discountType = old('discount_type', $promotion?->discount_type ?? 'percentage');
    $appliesTo = old('discount_applies_to', $promotion?->discount_applies_to ?? 'rate'); // an older "off the total" promo keeps its kind
    $discountValue = old('discount_value', $promotion?->hasDiscount() ? rtrim(rtrim(number_format((float) $promotion->discount_value, 2, '.', ''), '0'), '.') : '');
    $previewTypes = ($roomTypes ?? \App\Models\RoomType::where('status', 'active')->get())->map(fn ($t) => ['id' => $t->id, 'name' => $t->name, 'rate' => (float) $t->rate])->values();
@endphp
<div class="form-group mb-3 border rounded p-3" id="promoDiscountBox">
    <input type="hidden" name="discount_applies_to" value="{{ $appliesTo === 'total' ? 'total' : 'rate' }}">
    @if($isNew)
        {{-- A new promo always carries a value: this is what the guest's bill uses. --}}
        <input type="hidden" name="has_discount" value="1">
        <div class="fw-semibold mb-1">Discount on room rate *</div>
    @else
        <input type="hidden" name="has_discount" value="0">
        <div class="form-check form-switch mb-0">
            <input class="form-check-input" type="checkbox" role="switch" name="has_discount" value="1" id="has_discount" {{ $hasDiscount ? 'checked' : '' }}>
            <label class="form-check-label fw-semibold" for="has_discount">Discount on room rate</label>
        </div>
        @if(! $promotion->hasDiscount())
            <small class="text-warning d-block mt-1"><i class="fas fa-exclamation-triangle"></i> This promo has no value, so it is display only: it takes no money off a bill. Turn this on and enter a value to give a discount.</small>
        @endif
    @endif
    <div class="row g-2 mt-1 {{ $hasDiscount ? '' : 'd-none' }}" id="promoDiscountFields">
        <div class="col-sm-6">
            <label for="discount_type" class="form-label small mb-1">Discount type *</label>
            <select class="form-select @error('discount_type') is-invalid @enderror" id="discount_type" name="discount_type">
                <option value="percentage" {{ $discountType === 'percentage' ? 'selected' : '' }}>Percent (%) of the nightly rate</option>
                <option value="fixed" {{ $discountType === 'fixed' ? 'selected' : '' }}>Fixed ₱ off the nightly rate, per room</option>
            </select>
            @error('discount_type')<div class="invalid-feedback">{{ $message }}</div>@enderror
        </div>
        <div class="col-sm-6">
            <label for="discount_value" class="form-label small mb-1">Value *</label>
            <input type="number" step="0.01" min="0.01" class="form-control @error('discount_value') is-invalid @enderror"
                   id="discount_value" name="discount_value" value="{{ $discountValue }}" {{ $isNew ? 'required' : '' }}>
            @error('discount_value')<div class="invalid-feedback">{{ $message }}</div>@enderror
        </div>
        <div class="col-12">
            <div class="alert alert-light border py-2 small mb-1" id="promoPreview" aria-live="polite">Enter a value to see the new nightly rate.</div>
            <small class="text-muted d-block">Taken off the nightly rate of each room of the <strong>Applicable Room Type</strong> below (every room when it's
                All Room Types), for every booked night. The rate never goes below ₱0. It never touches amenities, other charges or other room types.
                Guests can still use a Senior / PWD ID discount on top: that one is taken from what is left of the room charges plus amenities.</small>
        </div>
    </div>
</div>

@push('scripts')
<script>
document.addEventListener('DOMContentLoaded', function () {
    const types = @json($previewTypes);
    const toggle = document.getElementById('has_discount');
    const fields = document.getElementById('promoDiscountFields');
    const type = document.getElementById('discount_type');
    const value = document.getElementById('discount_value');
    const roomType = document.getElementById('room_type_id');
    const preview = document.getElementById('promoPreview');
    const peso = function (n) { return '₱' + Number(n).toLocaleString('en-PH', {minimumFractionDigits: 2, maximumFractionDigits: 2}); };
    const after = function (rate, v, kind) {
        const off = kind === 'percentage' ? rate * Math.min(100, v) / 100 : v;
        return Math.max(0, rate - off);
    };
    const sync = function () {
        const on = !toggle || toggle.checked;
        fields.classList.toggle('d-none', !on);
        value.required = on && !toggle;
        value.max = type.value === 'percentage' ? '100' : '';
        const v = parseFloat(value.value);
        if (!(v > 0)) { preview.textContent = 'Enter a value to see the new nightly rate.'; return; }
        const picked = roomType && roomType.value ? types.filter(function (t) { return String(t.id) === roomType.value; }) : types.slice(0, 3);
        preview.innerHTML = picked.map(function (t) {
            return '<strong>' + t.name + '</strong> ' + peso(t.rate) + ' &rarr; ' + peso(after(t.rate, v, type.value)) + ' per night';
        }).join('<br>') + (!roomType || !roomType.value ? '<br><span class="text-muted">…and the same for every other room type</span>' : '');
    };
    [toggle, type, value, roomType].forEach(function (el) { if (el) { el.addEventListener('input', sync); el.addEventListener('change', sync); } });
    sync();
});
</script>
@endpush
