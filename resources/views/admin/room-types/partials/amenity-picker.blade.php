{{--
    Paid amenities included with a room type (Create + Edit Type). Starts with only the
    amenities already included; picking one in the list adds it straight away as a row
    with its "free per room" quantity and an X to remove it.

    @param \Illuminate\Support\Collection $amenities    paid amenities that can be included
    @param array                          $selectedIds  ids included now
    @param array                          $includedQty  id => free per room
    @param \Illuminate\Support\Collection $promotions   promotions that apply to this room type (optional)
--}}
@php
    $selectedIds = array_map('intval', old('amenities', $selectedIds ?? []));
    $includedQty = $includedQty ?? [];
    $byId = $amenities->keyBy('id');
    $peso = fn ($v) => '₱' . number_format((float) $v, 2);
@endphp
<div class="form-subsection">
    <h6 class="form-subsection-title">Paid Amenities Included</h6>
    <small class="text-muted d-block mb-2">
        Choose a paid amenity to include it with every room of this type and set how many each room gets free (e.g. 1 extra bed).
        Guests can still add more as paid add-ons; the included ones are taken from stock at check-in.
        Free amenities come with every room automatically, so they aren't listed here.
        <a href="{{ route('admin.amenities.create') }}" target="_blank">Create a new amenity <i class="fas fa-arrow-up-right-from-square fa-xs"></i></a>
    </small>

    @if($amenities->isEmpty())
        <p class="text-muted mb-0">No paid amenities exist yet. <a href="{{ route('admin.amenities.create') }}">Create one</a> to include it here.</p>
    @else
        <select class="form-select form-select-sm mb-3" id="amenityAddSelect" style="max-width: 420px;" aria-label="Add a paid amenity">
            <option value="">+ Add a paid amenity&hellip;</option>
            @foreach($amenities->groupBy('category') as $category => $group)
                <optgroup label="{{ $category ?: 'Uncategorized' }}">
                    @foreach($group as $amenity)
                        <option value="{{ $amenity->id }}" data-name="{{ $amenity->amenity_name }}" data-price="{{ $peso($amenity->charge) }}"
                                data-inactive="{{ $amenity->status !== 'active' ? '1' : '0' }}" {{ in_array($amenity->id, $selectedIds, true) ? 'disabled' : '' }}>
                            {{ $amenity->amenity_name }} ({{ $peso($amenity->charge) }})
                        </option>
                    @endforeach
                </optgroup>
            @endforeach
        </select>

        <div class="included-amenities" id="includedAmenityList">
            @foreach($selectedIds as $id)
                @continue(! $byId->has($id))
                @php $amenity = $byId->get($id); @endphp
                <div class="included-amenity" data-id="{{ $id }}">
                    <input type="hidden" name="amenities[]" value="{{ $id }}">
                    <div class="included-amenity-name">
                        {{ $amenity->amenity_name }}
                        <small class="text-muted">{{ $peso($amenity->charge) }} each as an add-on</small>
                        @if($amenity->status !== 'active')<span class="badge bg-secondary-subtle text-secondary-emphasis">Inactive</span>@endif
                    </div>
                    <label class="included-amenity-qty">
                        <input type="number" class="form-control form-control-sm" name="amenity_qty[{{ $id }}]" min="1" max="20"
                               value="{{ old('amenity_qty.' . $id, $includedQty[$id] ?? 1) }}" aria-label="{{ $amenity->amenity_name }} - free per room">
                        free per room
                    </label>
                    <button type="button" class="btn-close included-amenity-remove" aria-label="Remove {{ $amenity->amenity_name }}" title="Remove"></button>
                </div>
            @endforeach
        </div>
        <p class="text-muted small mb-0 {{ $selectedIds ? 'd-none' : '' }}" id="includedAmenityEmpty">No paid amenities included yet - choose one above.</p>

        <template id="includedAmenityTemplate">
            <div class="included-amenity" data-id="">
                <input type="hidden" name="amenities[]" value="">
                <div class="included-amenity-name"><span data-field="name"></span> <small class="text-muted"><span data-field="price"></span> each as an add-on</small></div>
                <label class="included-amenity-qty">
                    <input type="number" class="form-control form-control-sm" name="" min="1" max="20" value="1">
                    free per room
                </label>
                <button type="button" class="btn-close included-amenity-remove" aria-label="Remove" title="Remove"></button>
            </div>
        </template>
    @endif
    @error('amenities')<div class="invalid-feedback d-block">{{ $message }}</div>@enderror
    @error('amenities.*')<div class="invalid-feedback d-block">{{ $message }}</div>@enderror

    @isset($promotions)
        <div class="mt-3">
            <div class="small fw-semibold mb-1">Promotions for this room type</div>
            @forelse($promotions as $promo)
                <div class="included-promo">
                    <span class="fw-semibold">{{ $promo->promo_name }}</span>
                    <x-status-badge :status="$promo->status" domain="active_flag" />
                    <span class="text-muted small">
                        {{ $promo->room_type_id ? 'This room type' : 'All room types' }} &middot;
                        @if($promo->is_recurring) Every year, @endif
                        {{ optional($promo->start_date)->format('M d, Y') }} &ndash; {{ optional($promo->end_date)->format('M d, Y') }}
                        @if($promo->amenities->isNotEmpty())
                            &middot; Includes {{ $promo->amenities->map(fn ($a) => max(1, (int) $a->pivot->quantity) . ' × ' . $a->amenity_name)->join(', ') }}
                        @endif
                    </span>
                </div>
            @empty
                <p class="text-muted small mb-0">No promotions apply to this room type. <a href="{{ route('admin.promotions.index') }}">Manage promotions</a></p>
            @endforelse
        </div>
    @endisset
</div>

@push('scripts')
<script>
document.addEventListener('DOMContentLoaded', function () {
    const select = document.getElementById('amenityAddSelect');
    const list = document.getElementById('includedAmenityList');
    const empty = document.getElementById('includedAmenityEmpty');
    const tpl = document.getElementById('includedAmenityTemplate');
    if (!select || !list || !tpl) return;

    const syncEmpty = () => empty.classList.toggle('d-none', list.children.length > 0);

    // Picking an amenity adds it right away - no separate Add button.
    select.addEventListener('change', function () {
        const option = select.selectedOptions[0];
        if (!option || !option.value) return;
        const row = tpl.content.firstElementChild.cloneNode(true);
        row.dataset.id = option.value;
        row.querySelector('input[type="hidden"]').value = option.value;
        row.querySelector('[data-field="name"]').textContent = option.dataset.name;
        row.querySelector('[data-field="price"]').textContent = option.dataset.price;
        const qty = row.querySelector('input[type="number"]');
        qty.name = 'amenity_qty[' + option.value + ']';
        qty.setAttribute('aria-label', option.dataset.name + ' - free per room');
        row.querySelector('.included-amenity-remove').setAttribute('aria-label', 'Remove ' + option.dataset.name);
        list.appendChild(row);
        option.disabled = true;
        select.value = '';
        syncEmpty();
        qty.focus();
        qty.select();
    });

    // X removes it and puts it back in the list.
    list.addEventListener('click', function (e) {
        const btn = e.target.closest('.included-amenity-remove');
        if (!btn) return;
        const row = btn.closest('.included-amenity');
        const option = select.querySelector('option[value="' + row.dataset.id + '"]');
        if (option) option.disabled = false;
        row.remove();
        syncEmpty();
    });
});
</script>
@endpush
