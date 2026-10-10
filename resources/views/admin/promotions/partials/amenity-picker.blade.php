{{--
    Promotion amenity picker: "Add Amenity" opens a searchable multi-select;
    chosen amenities appear as a compact list with an included quantity each,
    submitted as amenities[<id>] = quantity (the contract
    Admin\PromotionManagementController::validatePromotion() expects).
    Needs $amenities (selectable catalog) and $selectedAmenities ([id => qty]).
--}}
@php
    $selectedAmenities = collect(old('amenities', $selectedAmenities ?? []))
        ->filter(fn ($qty) => (int) $qty > 0)
        ->map(fn ($qty) => (int) $qty);
    $amenityById = $amenities->keyBy('id');
@endphp
<div class="form-group mb-3" id="promoAmenityPicker">
    <div class="d-flex justify-content-between align-items-center mb-1">
        <label class="mb-0">Included Amenities *</label>
        <button type="button" class="btn btn-sm btn-outline-primary" data-bs-toggle="modal" data-bs-target="#promoAmenityModal">
            <i class="fas fa-plus"></i> Add Amenity
        </button>
    </div>
    <p class="text-muted small mb-2">Amenities included free with the stay, and how many of each.</p>
    @error('amenities')
        <div class="text-danger small mb-2">{{ $message }}</div>
    @enderror

    <ul class="list-group" id="promoAmenityList">
        @foreach($selectedAmenities as $id => $qty)
            @php $a = $amenityById->get($id); @endphp
            @continue(! $a)
            <li class="list-group-item d-flex align-items-center gap-2" data-amenity-id="{{ $a->id }}">
                <span class="flex-grow-1">{{ $a->amenity_name }} <small class="text-muted">₱{{ number_format($a->charge, 2) }} normally</small></span>
                <input type="number" class="form-control form-control-sm" style="width: 5rem;" min="1" max="99"
                       name="amenities[{{ $a->id }}]" value="{{ $qty }}" aria-label="Included quantity of {{ $a->amenity_name }}">
                <button type="button" class="btn btn-sm btn-outline-danger promo-amenity-remove" title="Remove {{ $a->amenity_name }}">
                    <i class="fas fa-times"></i>
                </button>
            </li>
        @endforeach
    </ul>
    <div id="promoAmenityEmpty" class="border rounded p-3 text-center text-muted small {{ $selectedAmenities->filter(fn ($q, $id) => $amenityById->has($id))->isEmpty() ? '' : 'd-none' }}">
        No amenities added yet. Use <strong>Add Amenity</strong> to choose what this promotion includes.
    </div>
</div>

@push('modals')
<div class="modal fade" id="promoAmenityModal" tabindex="-1" aria-labelledby="promoAmenityModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-dialog-scrollable">
        <div class="modal-content">
            <div class="modal-header modal-header-brand">
                <h5 class="modal-title" id="promoAmenityModalLabel"><i class="fas fa-plus"></i> Add Amenities</h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body">
                <input type="search" class="form-control mb-3" id="promoAmenitySearch" placeholder="Search amenities..." aria-label="Search amenities">
                <div class="list-group" id="promoAmenityOptions">
                    @foreach($amenities as $a)
                        <label class="list-group-item d-flex align-items-center gap-2 promo-amenity-option"
                               data-amenity-id="{{ $a->id }}" data-name="{{ $a->amenity_name }}" data-charge="{{ number_format($a->charge, 2) }}"
                               data-search="{{ strtolower($a->amenity_name . ' ' . $a->category) }}">
                            <input class="form-check-input m-0" type="checkbox" value="{{ $a->id }}">
                            <span class="flex-grow-1">{{ $a->amenity_name }} <small class="text-muted d-block">{{ $a->category ?: 'Uncategorized' }}</small></span>
                            <small class="text-muted">₱{{ number_format($a->charge, 2) }}</small>
                        </label>
                    @endforeach
                </div>
                <p class="text-muted small text-center mt-3 mb-0 d-none" id="promoAmenityNoMatch">No amenities match your search.</p>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancel</button>
                <button type="button" class="btn btn-primary" id="promoAmenityAddBtn" disabled>Add Selected</button>
            </div>
        </div>
    </div>
</div>
@endpush

@push('scripts')
<script>
document.addEventListener('DOMContentLoaded', function () {
    const list = document.getElementById('promoAmenityList');
    const empty = document.getElementById('promoAmenityEmpty');
    const modalEl = document.getElementById('promoAmenityModal');
    const search = document.getElementById('promoAmenitySearch');
    const addBtn = document.getElementById('promoAmenityAddBtn');
    const options = Array.from(document.querySelectorAll('.promo-amenity-option'));
    const noMatch = document.getElementById('promoAmenityNoMatch');

    const selectedIds = () => new Set(Array.from(list.querySelectorAll('li[data-amenity-id]')).map(li => li.dataset.amenityId));
    const refreshEmpty = () => empty.classList.toggle('d-none', list.children.length > 0);
    const refreshAddBtn = () => { addBtn.disabled = !options.some(o => o.querySelector('input').checked); };

    function filterOptions() {
        const term = search.value.trim().toLowerCase();
        const taken = selectedIds();
        let visible = 0;
        options.forEach(o => {
            const show = !taken.has(o.dataset.amenityId) && (!term || o.dataset.search.includes(term));
            o.classList.toggle('d-none', !show);
            if (show) visible++;
        });
        noMatch.classList.toggle('d-none', visible > 0);
    }

    modalEl.addEventListener('show.bs.modal', function () {
        search.value = '';
        options.forEach(o => { o.querySelector('input').checked = false; });
        filterOptions();
        refreshAddBtn();
    });
    modalEl.addEventListener('shown.bs.modal', () => search.focus());
    search.addEventListener('input', filterOptions);
    options.forEach(o => o.querySelector('input').addEventListener('change', refreshAddBtn));

    addBtn.addEventListener('click', function () {
        const taken = selectedIds();
        options.filter(o => o.querySelector('input').checked && !taken.has(o.dataset.amenityId)).forEach(o => {
            const li = document.createElement('li');
            li.className = 'list-group-item d-flex align-items-center gap-2';
            li.dataset.amenityId = o.dataset.amenityId;
            const label = document.createElement('span');
            label.className = 'flex-grow-1';
            label.textContent = o.dataset.name + ' ';
            const charge = document.createElement('small');
            charge.className = 'text-muted';
            charge.textContent = '₱' + o.dataset.charge + ' normally';
            label.appendChild(charge);
            const qty = document.createElement('input');
            qty.type = 'number'; qty.min = '1'; qty.max = '99'; qty.value = '1';
            qty.className = 'form-control form-control-sm'; qty.style.width = '5rem';
            qty.name = 'amenities[' + o.dataset.amenityId + ']';
            qty.setAttribute('aria-label', 'Included quantity of ' + o.dataset.name);
            const remove = document.createElement('button');
            remove.type = 'button';
            remove.className = 'btn btn-sm btn-outline-danger promo-amenity-remove';
            remove.title = 'Remove ' + o.dataset.name;
            remove.innerHTML = '<i class="fas fa-times"></i>';
            li.append(label, qty, remove);
            list.appendChild(li);
        });
        refreshEmpty();
        bootstrap.Modal.getOrCreateInstance(modalEl).hide();
    });

    list.addEventListener('click', function (e) {
        const btn = e.target.closest('.promo-amenity-remove');
        if (!btn) return;
        btn.closest('li').remove();
        refreshEmpty();
    });
});
</script>
@endpush
