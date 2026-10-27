@extends('layouts.app')

@section('title', 'Walk-in Check-in - Receptionist')

@section('content')
<div class="container-fluid py-4">
    <x-page-header icon="fas fa-person-walking-arrow-right" title="Walk-in Check-in"
        subtitle="For a guest at the desk with no reservation or booking. Everything happens on this page - pick the rooms, take the payment, and the guest is checked in." />

    <form action="{{ route('receptionist.check-in.walk-in.store') }}" method="POST" enctype="multipart/form-data" id="walkInForm"
          data-confirm="The selected rooms will be marked occupied, the payment is recorded and the stay starts now."
          data-confirm-title="Check in this walk-in guest?" data-confirm-button="Check In Guest" data-confirm-variant="success">
        @csrf
        <div class="row">
            <div class="col-lg-8">
                @if ($errors->any())
                    <div class="alert alert-danger alert-dismissible fade show" role="alert">
                        <strong>Please fix the following:</strong>
                        <ul class="mb-0">
                            @foreach ($errors->all() as $error)
                                <li>{{ $error }}</li>
                            @endforeach
                        </ul>
                        @if($errors->has('id_document') || $errors->has('gcash_receipt') || old('offer') === 'discount' || old('payment_method') === 'gcash')
                            <small class="d-block mt-1">Photos aren't kept when the form comes back - please choose them again.</small>
                        @endif
                        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                    </div>
                @endif
                @if (session('error'))
                    <div class="alert alert-danger alert-dismissible fade show" role="alert">
                        <i class="fas fa-exclamation-circle"></i> {{ session('error') }}
                        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                    </div>
                @endif

                {{-- Guest --}}
                <x-card title="Guest" icon="fas fa-id-card" bodyClass="card-body" class="mb-4">
                    @include('receptionist.partials.guest-account-field')
                    <div class="row g-3">
                        <div class="col-md-4">
                            <label class="form-label" for="guest_first_name">First Name *</label>
                            <input type="text" class="form-control @error('guest_first_name') is-invalid @enderror" id="guest_first_name" name="guest_first_name" value="{{ old('guest_first_name') }}" required maxlength="100">
                        </div>
                        <div class="col-md-4">
                            <label class="form-label" for="guest_middle_name">Middle Name</label>
                            <input type="text" class="form-control" id="guest_middle_name" name="guest_middle_name" value="{{ old('guest_middle_name') }}" maxlength="100">
                        </div>
                        <div class="col-md-4">
                            <label class="form-label" for="guest_last_name">Last Name *</label>
                            <input type="text" class="form-control @error('guest_last_name') is-invalid @enderror" id="guest_last_name" name="guest_last_name" value="{{ old('guest_last_name') }}" required maxlength="100">
                        </div>
                        <div class="col-12">
                            <label class="form-label" for="checkin_permanent_address">Permanent Address *</label>
                            <input type="text" class="form-control @error('checkin_permanent_address') is-invalid @enderror" id="checkin_permanent_address" name="checkin_permanent_address" value="{{ old('checkin_permanent_address') }}" required maxlength="255">
                        </div>
                        <div class="col-12">
                            <label class="form-label" for="checkin_current_address">Current Address *</label>
                            <input type="text" class="form-control @error('checkin_current_address') is-invalid @enderror" id="checkin_current_address" name="checkin_current_address" value="{{ old('checkin_current_address') }}" maxlength="255">
                            <div class="form-check mt-1">
                                <input type="hidden" name="current_address_same_as_permanent" value="0">
                                <input type="checkbox" class="form-check-input" name="current_address_same_as_permanent" value="1" id="walkInSameAddress" {{ old('current_address_same_as_permanent') ? 'checked' : '' }}>
                                <label class="form-check-label small" for="walkInSameAddress">Same as permanent address</label>
                            </div>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label" for="checkin_contact_number">Contact Number *</label>
                            <input type="text" class="form-control @error('checkin_contact_number') is-invalid @enderror" id="checkin_contact_number" name="checkin_contact_number" value="{{ old('checkin_contact_number') }}" required placeholder="09XXXXXXXXX" data-phone="ph">
                        </div>
                    </div>
                </x-card>

                {{-- Stay & rooms --}}
                <x-card title="Stay & Rooms" icon="fas fa-door-open" bodyClass="card-body" class="mb-4">
                    <div class="row g-3 mb-3">
                        <div class="col-md-3">
                            <label class="form-label">Check-In</label>
                            <input type="text" class="form-control" value="{{ now()->format('M d, Y') }} (today)" disabled>
                        </div>
                        <div class="col-md-3">
                            <label class="form-label" for="check_out">Check-Out *</label>
                            <input type="date" class="form-control @error('check_out') is-invalid @enderror" id="check_out" name="check_out" value="{{ old('check_out') }}" min="{{ now()->addDay()->toDateString() }}" required>
                        </div>
                        <div class="col-md-3">
                            <label class="form-label" for="adults">Adults *</label>
                            <input type="number" min="1" class="form-control @error('adults') is-invalid @enderror" id="adults" name="adults" value="{{ old('adults', 1) }}" required>
                        </div>
                        <div class="col-md-3">
                            <label class="form-label" for="children">Minors</label>
                            <input type="number" min="0" class="form-control" id="children" name="children" value="{{ old('children', 0) }}">
                        </div>
                    </div>

                    <label class="form-label mb-1">Rooms *</label>
                    <p class="small text-muted mb-2">Choose a room type to see its free rooms, then tick the room numbers. Add another room type if the guest needs more than one kind.</p>
                    <div id="walkInRoomGroups"></div>
                    <button type="button" class="btn btn-sm btn-outline-primary" id="walkInAddType"><i class="fas fa-plus"></i> Add Another Room Type</button>
                    @error('room_ids')<div class="text-danger small mt-2">{{ $message }}</div>@enderror

                    <template id="walkInGroupTemplate">
                        <div class="border rounded p-2 mb-2 walkin-group">
                            <div class="d-flex gap-2 align-items-center mb-2">
                                <select class="form-select form-select-sm walkin-type" aria-label="Room type">
                                    <option value="">-- Select a room type --</option>
                                    @foreach($roomTypes as $type)
                                        <option value="{{ $type->id }}">{{ $type->name }} - ₱{{ number_format((float) $type->rate, 2) }}/night</option>
                                    @endforeach
                                </select>
                                <button type="button" class="btn btn-sm btn-outline-danger walkin-remove" title="Remove this room type"><i class="fas fa-trash"></i></button>
                            </div>
                            <div class="walkin-rooms small text-muted">Pick a check-out date and a room type.</div>
                        </div>
                    </template>
                </x-card>

                {{-- Extras --}}
                <x-card title="Amenities, Discount or Promotion" icon="fas fa-concierge-bell" bodyClass="card-body" class="mb-4">
                    @include('receptionist.partials.walk-in-amenities')
                    @include('receptionist.partials.offer-field', ['canApproveNow' => false, 'walkIn' => true])
                </x-card>
            </div>

            <div class="col-lg-4">
                <div class="position-sticky" style="top: 1rem;">
                    <x-card title="Bill" icon="fas fa-file-invoice" bodyClass="card-body" class="mb-4">
                        <div id="walkInBill" class="small">
                            <p class="text-muted mb-0">Pick the check-out date and rooms to see the bill.</p>
                        </div>
                    </x-card>
                    <x-card title="Payment" icon="fas fa-money-bill-wave" bodyClass="card-body" class="mb-4">
                        <p class="small text-muted">The bill is paid in full now. Anything added during the stay is paid at check-out.</p>
                        @include('receptionist.partials.desk-payment-fields', ['due' => null, 'mode' => 'full', 'containerId' => 'walkInPayment'])
                        <button type="submit" class="btn btn-success w-100 mt-3"><i class="fas fa-sign-in-alt"></i> Check In Guest</button>
                        <a href="{{ route('receptionist.check-in.index') }}" class="btn btn-outline-secondary w-100 mt-2">Cancel</a>
                    </x-card>
                </div>
            </div>
        </div>
    </form>
</div>
@endsection

@push('scripts')
<script>
document.addEventListener('DOMContentLoaded', function () {
    const form = document.getElementById('walkInForm');
    const groups = document.getElementById('walkInRoomGroups');
    const template = document.getElementById('walkInGroupTemplate');
    const checkOut = document.getElementById('check_out');
    const roomsUrl = @json(route('receptionist.check-in.walk-in.rooms'));
    const rates = @json($roomTypes->mapWithKeys(fn ($t) => [$t->id => ['name' => $t->name, 'rate' => (float) $t->rate]]));
    const oldRoomIds = @json(array_map('strval', old('room_ids', [])));
    const oldTypes = @json($oldRoomTypes);
    const peso = n => '₱' + Number(n).toLocaleString('en-PH', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
    const today = new Date(); today.setHours(0, 0, 0, 0);

    function nights() {
        if (!checkOut.value) return 0;
        const out = new Date(checkOut.value + 'T00:00:00');
        return Math.max(1, Math.round((out - today) / 86400000));
    }

    // One room type per group: its free room numbers as checkboxes (name="room_ids[]").
    async function loadRooms(group) {
        const typeId = group.querySelector('.walkin-type').value;
        const box = group.querySelector('.walkin-rooms');
        if (!typeId || !checkOut.value) {
            box.textContent = 'Pick a check-out date and a room type.';
            renderBill();
            return;
        }
        const kept = Array.from(group.querySelectorAll('input[name="room_ids[]"]:checked')).map(i => i.value).concat(oldRoomIds);
        box.textContent = 'Loading rooms...';
        try {
            const r = await fetch(roomsUrl + '?room_type_id=' + encodeURIComponent(typeId) + '&check_out=' + encodeURIComponent(checkOut.value), { headers: { 'Accept': 'application/json' } });
            const data = await r.json();
            if (!r.ok) throw new Error(data.message || 'Could not load the rooms.');
            group.dataset.sellable = data.sellable;
            if (!data.rooms.length || data.sellable < 1) {
                box.innerHTML = '<span class="text-danger">No free ' + rates[typeId].name + ' rooms for these dates.</span>';
            } else {
                box.innerHTML = '<div class="mb-1">' + data.sellable + ' can be booked for these dates - tick the rooms to give the guest:</div>'
                    + '<div class="d-flex flex-wrap gap-2">' + data.rooms.map(room =>
                        '<input type="checkbox" class="btn-check" name="room_ids[]" value="' + room.id + '" id="walkInRoom' + room.id + '" data-type="' + typeId + '" data-rate="' + room.rate + '"' + (kept.includes(String(room.id)) ? ' checked' : '') + '>'
                        // a room that charges its own rate (not its type's) says so on its button
                        + '<label class="btn btn-sm btn-outline-success" for="walkInRoom' + room.id + '">' + room.room_number
                        + (room.capacity_label ? ' &middot; ' + room.capacity_label : '')
                        + (Math.abs(room.rate - rates[typeId].rate) > 0.004 ? ' &middot; ' + peso(room.rate) + '/night' : '') + '</label>').join('') + '</div>';
            }
        } catch (err) {
            box.innerHTML = '<span class="text-danger">' + err.message + '</span>';
        }
        enforceLimit(group);
        renderBill();
    }

    // Never more rooms of a type than can still be booked for the dates.
    function enforceLimit(group) {
        const limit = parseInt(group.dataset.sellable || '0', 10);
        const boxes = Array.from(group.querySelectorAll('input[name="room_ids[]"]'));
        const picked = boxes.filter(b => b.checked).length;
        boxes.forEach(b => { b.disabled = !b.checked && picked >= limit; });
    }

    function usedTypes(except) {
        return Array.from(groups.querySelectorAll('.walkin-type')).filter(s => s !== except).map(s => s.value).filter(Boolean);
    }

    function addGroup(typeId) {
        const group = template.content.firstElementChild.cloneNode(true);
        const select = group.querySelector('.walkin-type');
        const taken = usedTypes(null);
        select.querySelectorAll('option').forEach(o => { if (o.value && taken.includes(o.value)) o.disabled = true; });
        if (typeId) select.value = typeId;
        groups.appendChild(group);
        syncRemoveButtons();
        loadRooms(group);
    }

    function syncRemoveButtons() {
        const all = groups.querySelectorAll('.walkin-group');
        all.forEach(g => g.querySelector('.walkin-remove').classList.toggle('d-none', all.length === 1));
        // The same room type can't be picked in two groups.
        all.forEach(g => {
            const sel = g.querySelector('.walkin-type');
            const taken = usedTypes(sel);
            sel.querySelectorAll('option').forEach(o => { if (o.value) o.disabled = taken.includes(o.value); });
        });
    }

    groups.addEventListener('change', function (e) {
        if (e.target.classList.contains('walkin-type')) {
            syncRemoveButtons();
            loadRooms(e.target.closest('.walkin-group'));
        } else if (e.target.name === 'room_ids[]') {
            enforceLimit(e.target.closest('.walkin-group'));
            renderBill();
        }
    });
    groups.addEventListener('click', function (e) {
        const btn = e.target.closest('.walkin-remove');
        if (!btn) return;
        btn.closest('.walkin-group').remove();
        syncRemoveButtons();
        renderBill();
    });
    document.getElementById('walkInAddType').addEventListener('click', () => addGroup(''));
    checkOut.addEventListener('change', () => groups.querySelectorAll('.walkin-group').forEach(loadRooms));

    // Same as permanent address.
    const same = document.getElementById('walkInSameAddress');
    const current = document.getElementById('checkin_current_address');
    function syncAddress() {
        current.disabled = same.checked;
        current.required = !same.checked;
        if (same.checked) current.value = '';
    }
    same.addEventListener('change', syncAddress);
    syncAddress();

    // Live bill: rooms x nights + amenities - discount. The server prices it again (the same way) on submit.
    function renderBill() {
        const n = nights();
        const lines = [];
        let roomTotal = 0;
        const picked = []; // [room type id, nightly rate] per room - a room-rate promo prices each one
        groups.querySelectorAll('.walkin-group').forEach(g => {
            const typeId = g.querySelector('.walkin-type').value;
            g.querySelectorAll('input[name="room_ids[]"]:checked').forEach(b => {
                const label = g.querySelector('label[for="' + b.id + '"]').textContent.split(' · ')[0]; // the room number, without its rate
                // the room's own nightly rate (it may differ from its type's) - what check-in bills and discounts
                const rate = b.dataset.rate !== undefined ? parseFloat(b.dataset.rate) : rates[typeId].rate;
                const sub = rate * n;
                roomTotal += sub;
                picked.push([String(typeId), rate]);
                lines.push([rates[typeId].name + ' ' + label + ' &middot; ' + peso(rate) + ' &times; ' + n + ' night' + (n === 1 ? '' : 's'), sub]);
            });
        });
        let addOns = 0;
        form.querySelectorAll('input[name^="amenities"][name$="[quantity]"]').forEach(q => {
            const qty = parseInt(q.value || '0', 10);
            if (qty > 0) {
                const sub = parseFloat(q.dataset.charge) * qty;
                addOns += sub;
                lines.push([q.dataset.name + ' &times; ' + qty, sub]);
            }
        });
        const offer = (form.querySelector('input[name="offer"]:checked') || {}).value;
        let discount = 0, discountName = '';
        if (offer === 'discount') {
            const opt = form.querySelector('#offerDiscount').selectedOptions[0];
            if (opt && opt.value) {
                const base = roomTotal + addOns;
                discount = opt.dataset.type === 'percentage' ? base * parseFloat(opt.dataset.value) / 100 : Math.min(base, parseFloat(opt.dataset.value));
                discount = Math.round(discount * 100) / 100;
                discountName = opt.dataset.label;
            }
        } else if (offer === 'promotion') {
            // Same as Promotion::discountFor(): off the rate of its room type's rooms (fixed = per room, per night),
            // or off the total before check-in (rooms + these amenities - everything on this form), once.
            const opt = form.querySelector('#offerPromotion').selectedOptions[0];
            if (opt && opt.value && opt.dataset.type) {
                const v = parseFloat(opt.dataset.value);
                if (opt.dataset.applies === 'total') {
                    const base = roomTotal + addOns;
                    discount = opt.dataset.type === 'percentage' ? base * Math.min(100, v) / 100 : Math.min(base, v);
                } else {
                    picked.forEach(([typeId, rate]) => {
                        if (opt.dataset.roomType && opt.dataset.roomType !== typeId) return;
                        const perNight = opt.dataset.type === 'percentage' ? rate * Math.min(100, v) / 100 : Math.min(v, rate);
                        discount += Math.round(perNight * Math.max(1, n) * 100) / 100;
                    });
                }
                discount = Math.min(Math.round(discount * 100) / 100, roomTotal + addOns);
                discountName = opt.dataset.label;
            }
        }
        const total = Math.max(0, Math.round((roomTotal + addOns - discount) * 100) / 100);
        const bill = document.getElementById('walkInBill');
        const pay = document.getElementById('walkInPayment');
        if (!lines.length || !n) {
            bill.innerHTML = '<p class="text-muted mb-0">Pick the check-out date and rooms to see the bill.</p>';
            pay.dataset.due = '';
        } else {
            bill.innerHTML = lines.map(l => '<div class="d-flex justify-content-between gap-2"><span>' + l[0] + '</span><span class="text-nowrap">' + peso(l[1]) + '</span></div>').join('')
                + (discount > 0 ? '<div class="d-flex justify-content-between text-success"><span>Discount (' + discountName + ')</span><span>&minus;' + peso(discount) + '</span></div>' : '')
                + '<hr class="my-1"><div class="d-flex justify-content-between fw-bold"><span>Total to pay now</span><span>' + peso(total) + '</span></div>'
                + (offer === 'promotion' ? '<div class="text-muted mt-1">The promotion\'s amenities (if any) are added free.</div>' : '');
            pay.dataset.due = total.toFixed(2);
        }
        window.syncDeskPayment(pay);
    }
    form.addEventListener('input', function (e) { if (e.target.name && e.target.name.startsWith('amenities')) renderBill(); });
    form.addEventListener('change', function (e) { if (e.target.name === 'offer' || e.target.name === 'discount_id' || e.target.name === 'promotion_id') renderBill(); });

    // Start with the room types picked before (form came back with errors), or one empty group.
    if (oldTypes.length) oldTypes.forEach(addGroup); else addGroup('');

    form.addEventListener('submit', function (e) {
        if (!form.querySelector('input[name="room_ids[]"]:checked')) {
            e.preventDefault();
            e.stopImmediatePropagation();
            alert('Tick at least one room for the guest.');
        }
    }, true);
});
</script>
@endpush
