@extends('layouts.app')

@section('title', 'Check-In - Receptionist')

@section('content')
<div class="container-fluid py-4">
    <x-page-header icon="fas fa-sign-in-alt" title="Check-In"
        subtitle="Assign a room and check guests in - room assignment happens here, not at booking.">
        <x-slot:actions>
            <a href="{{ route('receptionist.check-in.walk-in.create') }}" class="btn btn-primary">
                <i class="fas fa-person-walking-arrow-right"></i> Walk-in Check-in
            </a>
        </x-slot:actions>
    </x-page-header>

    @if (session('success'))
        <div class="alert alert-success alert-dismissible fade show" role="alert">
            <i class="fas fa-check-circle"></i> {{ session('success') }}
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
    @endif
    @if (session('error'))
        <div class="alert alert-danger alert-dismissible fade show" role="alert">
            <i class="fas fa-exclamation-circle"></i> {{ session('error') }}
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
    @endif

    <ul class="nav nav-tabs mb-3">
        <li class="nav-item">
            <a class="nav-link {{ $tab === 'expected' ? 'active' : '' }}" href="{{ route('receptionist.check-in.index', ['tab' => 'expected', 'range' => $range]) }}">
                Expected Check-ins <span class="badge bg-warning text-dark">{{ $expectedCount }}</span>
            </a>
        </li>
        <li class="nav-item">
            <a class="nav-link {{ $tab === 'checked_in' ? 'active' : '' }}" href="{{ route('receptionist.check-in.index', ['tab' => 'checked_in']) }}">
                Checked-in Guests <span class="badge bg-success">{{ $checkedInCount }}</span>
            </a>
        </li>
    </ul>

    <x-card :title="$tab === 'expected' ? 'Expected Check-ins' : 'Checked-in Guests'" icon="fas fa-list" bodyClass="table-responsive">
        @if($tab === 'expected')
            <x-slot:actions>
                <form method="GET" action="{{ route('receptionist.check-in.index') }}" class="d-flex align-items-center gap-2">
                    <input type="hidden" name="tab" value="expected">
                    <label for="rangeFilter" class="mb-0 small text-muted text-nowrap">Arriving:</label>
                    <select id="rangeFilter" name="range" class="form-select form-select-sm" onchange="this.form.submit()">
                        <option value="today" {{ $range === 'today' ? 'selected' : '' }}>Today</option>
                        <option value="week" {{ $range === 'week' ? 'selected' : '' }}>This Week</option>
                        <option value="month" {{ $range === 'month' ? 'selected' : '' }}>This Month</option>
                        <option value="all" {{ $range === 'all' ? 'selected' : '' }}>All</option>
                    </select>
                </form>
            </x-slot:actions>
        @endif
        <table class="table table-hover mb-0 align-middle">
            <thead>
                <tr>
                    @if($tab === 'checked_in')
                        {{-- Room first here - checked-in guests are tracked
                             per physical room (see CheckInController::index()),
                             so the room each row is about should be the very
                             first thing a receptionist scans, not the guest
                             name (which can legitimately repeat across rows
                             for a multi-room booking). --}}
                        <th>Room</th>
                        <th>Guest</th>
                    @else
                        <th>Guest</th>
                        <th>Room Type</th>
                        <th>Room</th>
                    @endif
                    <th>Check-In</th>
                    <th>Check-Out</th>
                    <th>Guests</th>
                    @if($tab !== 'checked_in')<th>Payment</th>@endif
                    <th class="text-nowrap">Actions</th>
                </tr>
            </thead>
            <tbody>
                @if($tab === 'checked_in')
                    {{-- One row per physical room (see CheckInController::index()) -
                         $bookings here is actually a paginated Room collection.
                         Multiple rows for the same multi-room booking still all
                         open the one same booking-level Add Amenity/checkout
                         flow, keyed by booking id, not room id. --}}
                    @forelse($bookings as $room)
                        {{-- Every guest checked in to this room gets a row - never only the first, so a room that
                             somehow holds two stays is visible instead of one of them silently disappearing. --}}
                        @foreach($room->assignedBookings as $booking)
                        <tr>
                            <td style="min-width: 200px;">
                                {{-- The physical room's OWN type, not the booking's legacy
                                     top-level room_type_id - a multi-room-type booking (e.g.
                                     Standard + Superior) has one shared room_type_id for the
                                     whole booking, so every row used to show that same single
                                     type even for a room that's actually a different type. --}}
                                {{ $room->room_number }} ({{ $room->roomType->name ?? '' }})
                                <x-status-badge :status="$room->effective_status" domain="room" />
                            </td>
                            <td>@unless($booking->viewed_at)<span class="unread-dot" title="New"></span>@endunless{{ $booking->guest_display_name }}</td>
                            <td>{{ $booking->check_in->format('M d, Y') }}</td>
                            <td>{{ $booking->check_out->format('M d, Y') }}</td>
                            <td>{{ $booking->number_of_guests }}</td>
                            <td class="text-nowrap">
                                <button type="button" class="btn btn-sm btn-outline-primary btn-open-amenity" data-bs-toggle="modal"
                                        data-bs-target="#amenityModal" data-booking-id="{{ $booking->id }}">
                                    <i class="fas fa-spa"></i> Add Amenity
                                </button>
                            </td>
                        </tr>
                        @endforeach
                    @empty
                        <tr>
                            <td colspan="6">
                                <x-empty-state icon="fas fa-sign-in-alt" message="No checked-in guests." />
                            </td>
                        </tr>
                    @endforelse
                @else
                    @forelse($bookings as $booking)
                        <tr>
                            <td>@unless($booking->viewed_at)<span class="unread-dot" title="New"></span>@endunless{{ $booking->guest_display_name }}</td>
                            <td>
                                {{-- Full multi-room-type breakdown (room_lines) when this
                                     booking has one, not just the single legacy room_type_id -
                                     a Standard + Superior booking must show both, not just
                                     whichever type happens to be the booking's primary one. --}}
                                {{ !empty($booking->room_lines) ? collect($booking->room_lines)->pluck('room_type')->join(', ') : ($booking->roomType->name ?? 'N/A') }}
                            </td>
                            <td style="min-width: 200px;">
                                <span class="text-muted small"><i class="fas fa-door-open"></i> Not yet assigned</span>
                            </td>
                            @php $nextStay = $booking->stayList()->first(fn ($st) => $booking->stateOf($st) === 'upcoming') ?? $booking->stayList()->first(); @endphp
                            <td>
                                {{ $nextStay->check_in->format('M d, Y') }}
                                @if($nextStay->check_in->isAfter(today()))
                                    <span class="badge bg-info" title="Scheduled for a future date">Early</span>
                                @endif
                                @if($booking->stayCount() > 1)
                                    <div class="small"><span class="badge bg-info text-dark"><i class="fas fa-layer-group"></i> Stay {{ $nextStay->position }} of {{ $booking->stayCount() }}</span></div>
                                @endif
                            </td>
                            <td>{{ $nextStay->check_out->format('M d, Y') }}</td>
                            <td>{{ $booking->number_of_guests }}</td>
                            @php $pay = \App\Support\StayPayment::of($booking); @endphp
                            <td><span class="badge {{ $pay['badge'] }}">{{ $pay['label'] }}</span>@if($pay['state'] === 'partial')<div class="small text-muted">₱{{ number_format($pay['paid'], 2) }} of ₱{{ number_format($pay['total'], 2) }}</div>@endif</td>
                            <td class="text-nowrap">
                                <div class="d-flex gap-2">
                                    <button type="button" class="btn btn-sm btn-success btn-open-check-in" data-booking-id="{{ $booking->id }}" data-stay-id="{{ $nextStay->id }}">
                                        <i class="fas fa-sign-in-alt"></i> Check In
                                    </button>
                                    {{-- Replaces the old automatic no-show: staff archive a
                                         booking whose guest never arrived. --}}
                                    @if($booking->check_in->lt(today()))
                                        <form action="{{ route('receptionist.bookings.archive', $booking) }}" method="POST"
                                              data-confirm="Booking #{{ $booking->id }} for {{ $booking->guest_display_name }} (check-in {{ $booking->check_in->format('M d, Y') }}) will be moved to Archived Bookings and removed from Expected Check-ins. Its records and payments are kept."
                                              data-confirm-title="Archive booking - guest did not arrive?" data-confirm-button="Archive Booking" data-confirm-variant="danger">
                                            @csrf
                                            @method('PUT')
                                            <button type="submit" class="btn btn-outline-secondary btn-sm btn-icon" title="Guest did not arrive" aria-label="Archive (did not arrive)">
                                                <i class="fas fa-box-archive"></i></button>
                                        </form>
                                    @endif
                                </div>
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="8">
                                <x-empty-state icon="fas fa-sign-in-alt" message="No expected check-ins." />
                            </td>
                        </tr>
                    @endforelse
                @endif
            </tbody>
        </table>
        <x-slot:footer>
            {{ $bookings->links() }}
        </x-slot:footer>
    </x-card>
</div>

<!-- Check In Modal (AJAX-loaded: Guest Details, room assignment, and
     check-in are one action - see CheckInController::panel()/store()) -->
<div class="modal fade" id="checkInModal" tabindex="-1" data-bs-backdrop="static">
    <div class="modal-dialog modal-dialog-scrollable modal-lg">
        <div class="modal-content" id="checkInModalContent">
            <!-- Injected via AJAX -->
        </div>
    </div>
</div>

<!-- Add Amenity Modal (AJAX-loaded card grid - see
     ReceptionistController::amenitiesCreate()/amenitiesStore()) -->
<div class="modal fade" id="amenityModal" tabindex="-1">
    <div class="modal-dialog modal-dialog-scrollable modal-lg">
        <div class="modal-content" id="amenityModalContent">
            <!-- Injected via AJAX -->
        </div>
    </div>
</div>

@push('scripts')
<script>
document.addEventListener('DOMContentLoaded', function () {
    const csrfToken = document.querySelector('meta[name="csrf-token"]').content;
    const modalEl = document.getElementById('checkInModal');
    const modal = bootstrap.Modal.getOrCreateInstance(modalEl);
    const content = document.getElementById('checkInModalContent');

    let activeBookingId = null;

    const urls = {
        panel: @json(route('receptionist.check-in.panel', ['booking' => '__ID__'])),
        store: @json(route('receptionist.check-in.store', ['booking' => '__ID__'])),
        amenityCreate: @json(route('receptionist.amenities.create', ['booking' => '__ID__'])),
        amenityStore: @json(route('receptionist.amenities.store', ['booking' => '__ID__'])),
    };

    function buildUrl(template, id) {
        return template.replace('__ID__', id);
    }

    function showError(message) {
        const alertBox = content.querySelector('#checkInErrorAlert');
        if (alertBox) {
            alertBox.textContent = message;
            alertBox.classList.remove('d-none');
        } else {
            alert(message);
        }
    }

    let activeStayId = null;

    async function openCheckInPanel(bookingId, stayId) {
        activeBookingId = bookingId;
        activeStayId = stayId || null;
        content.innerHTML = '<div class="modal-body text-center py-5"><i class="fas fa-spinner fa-spin fa-2x"></i></div>';
        modal.show();

        try {
            const response = await fetch(buildUrl(urls.panel, activeBookingId) + (activeStayId ? '?stay=' + encodeURIComponent(activeStayId) : ''), {
                headers: { 'X-Requested-With': 'XMLHttpRequest' },
            });
            if (!response.ok) {
                const data = await response.json().catch(() => ({}));
                throw new Error(data.message || 'Failed to load the check-in panel.');
            }
            content.innerHTML = await response.text();
            window.initGuestCapacity(document.getElementById('checkInForm'));
        } catch (err) {
            content.innerHTML = '<div class="modal-body"><div class="alert alert-danger mb-0">' + err.message + '</div></div>';
        }
    }

    document.querySelectorAll('.btn-open-check-in').forEach(function (btn) {
        btn.addEventListener('click', function () {
            openCheckInPanel(btn.dataset.bookingId, btn.dataset.stayId);
        });
    });

    // A booking with several stays: pick which stay to check in (reloads the panel for that stay).
    content.addEventListener('click', function (e) {
        const pick = e.target.closest('[data-checkin-stay]');
        if (pick && !pick.disabled) openCheckInPanel(activeBookingId, pick.dataset.checkinStay);
    });

    // Walk-in Check-in redirects here with ?open={booking} so the Guest
    // Details + Assign Room panel opens immediately - no need to hunt the
    // new booking down in the list (it may not even be on this page once
    // paginated). Not keyed off finding the row's own button, since it may
    // not exist in the current DOM at all.
    const openId = new URLSearchParams(window.location.search).get('open');
    if (openId) {
        openCheckInPanel(openId);
        const url = new URL(window.location.href);
        url.searchParams.delete('open');
        window.history.replaceState({}, '', url);
    }

    // Prevent picking the same room in two different rows: whenever any
    // select changes, disable that room's <option> in every other row.
    content.addEventListener('change', function (e) {
        if (!e.target.classList.contains('room-select')) return;

        const allSelects = content.querySelectorAll('.room-select');
        const chosen = Array.from(allSelects).map(s => s.value).filter(Boolean);

        allSelects.forEach(select => {
            Array.from(select.options).forEach(option => {
                if (!option.value) return;
                const chosenElsewhere = chosen.includes(option.value) && select.value !== option.value;
                option.disabled = chosenElsewhere;
            });
        });
    });

    // "Same as permanent address": copies the value across and stops
    // asking the receptionist to type it twice, since it no longer needs
    // typing - the field is just re-enabled if the box is unchecked again.
    content.addEventListener('change', function (e) {
        if (e.target.id !== 'checkinSameAsPermanent') return;

        const form = e.target.closest('form');
        const permanent = form.querySelector('[name="checkin_permanent_address"]');
        const current = form.querySelector('[name="checkin_current_address"]');

        if (e.target.checked) {
            current.value = permanent.value;
            current.readOnly = true;
            current.required = false;
        } else {
            current.readOnly = false;
            current.required = true;
        }
    });

    // Step 1 (Guest Details) -> Step 2 (Assign Room): client-side only,
    // both steps submit together as one request - see store()'s docblock
    // for why. The room selects start `disabled` in the Blade template
    // (see panel.blade.php) precisely so reportValidity() here - which
    // only checks Step 1 - never trips over them: a required field hidden
    // via display:none is NOT reliably excluded from constraint
    // validation in Chrome (it still tries to focus/report on it and
    // throws "is not focusable", silently aborting this whole handler
    // before the step toggle below ever runs) - `disabled` is the one
    // exclusion the spec actually guarantees.
    // Step 1 "Discount & Bill" -> Step 2 Guest Details. The bill step is shown first on every check-in.
    content.addEventListener('click', function (e) {
        if (!e.target.closest('#checkInBillContinueBtn')) return;
        document.getElementById('checkInStepBill').classList.add('d-none');
        document.getElementById('checkInStepDetails').classList.remove('d-none');
        document.getElementById('checkInBillContinueBtn').classList.add('d-none');
        document.getElementById('checkInNextBtn').classList.remove('d-none');
    });

    // Discount / promotion actions on the bill step: post, then reload the panel (still on step 1).
    content.addEventListener('click', async function (e) {
        const btn = e.target.closest('[data-offer-action]');
        if (!btn) return;
        const box = document.getElementById('checkInOfferBox');
        const errBox = box.querySelector('[data-offer-error]');
        const action = btn.dataset.offerAction;
        const rejectBox = box.querySelector('[data-reject-box]');
        if (action === 'reject-open' || action === 'reject-cancel') {
            rejectBox.classList.toggle('d-none', action === 'reject-cancel');
            if (action === 'reject-open') rejectBox.querySelector('textarea').focus();
            return;
        }
        const fd = new FormData();
        let url = box.dataset.approveUrl;
        errBox.classList.add('d-none');
        if (action === 'approve') {
            const sel = box.querySelector('[data-offer-field="discount_id"]');
            const file = box.querySelector('[data-offer-field="id_document"]');
            const name = sel.selectedOptions[0] ? sel.selectedOptions[0].dataset.label : 'this discount';
            // Approving changes what the guest pays - make sure it's intended.
            const ok = await window.confirmAction({
                title: 'Approve the ' + name + ' discount?',
                message: 'Confirm you checked the guest\'s ID. The discount comes off this stay\'s bill right away.',
                button: 'Approve Discount', variant: 'success',
            }, btn);
            if (!ok) return;
            fd.append('discount_id', sel.value);
            if (file && file.files[0]) fd.append('id_document', file.files[0]);
        } else if (action === 'reject') {
            const reason = rejectBox.querySelector('textarea').value.trim();
            if (!reason) {
                errBox.textContent = 'Enter the reason for rejecting the ID.';
                errBox.classList.remove('d-none');
                return;
            }
            const ok = await window.confirmAction({
                title: 'Reject the guest\'s ID?',
                message: 'No discount will apply to this stay. The guest is told the reason.',
                button: 'Reject ID', variant: 'danger',
            }, btn);
            if (!ok) return;
            url = box.dataset.rejectUrl;
            fd.append('reason', reason);
        } else {
            url = box.dataset.promotionUrl;
            const sel = box.querySelector('[data-offer-field="promotion_id"]');
            fd.append('promotion_id', btn.dataset.promotionId !== undefined ? btn.dataset.promotionId : (sel ? sel.value : ''));
        }
        btn.disabled = true;
        try {
            const r = await fetch(url, { method: 'POST', headers: { 'X-CSRF-TOKEN': csrfToken, 'Accept': 'application/json' }, body: fd });
            const data = await r.json().catch(() => ({}));
            if (!r.ok) throw new Error(data.message || 'Something went wrong.');
            openCheckInPanel(activeBookingId, activeStayId);
        } catch (err) {
            errBox.textContent = err.message;
            errBox.classList.remove('d-none');
            btn.disabled = false;
        }
    });

    content.addEventListener('click', function (e) {
        if (e.target.closest('#checkInNextBtn')) {
            const form = document.getElementById('checkInForm');
            if (!form.reportValidity()) return;

            content.querySelectorAll('.room-select').forEach(s => s.disabled = false);
            content.querySelectorAll('#checkInPayment input, #ciChangeReturned').forEach(i => i.disabled = false);
            window.syncDeskPayment(document.getElementById('checkInPayment'));
            document.getElementById('checkInStepDetails').classList.add('d-none');
            document.getElementById('checkInStepRooms').classList.remove('d-none');
            document.getElementById('checkInNextBtn').classList.add('d-none');
            document.getElementById('checkInBackBtn').classList.remove('d-none');
            document.getElementById('checkInSubmitBtn').classList.remove('d-none');
        } else if (e.target.closest('#checkInBackBtn')) {
            content.querySelectorAll('.room-select').forEach(s => s.disabled = true);
            content.querySelectorAll('#checkInPayment input, #ciChangeReturned').forEach(i => i.disabled = true);
            document.getElementById('checkInStepRooms').classList.add('d-none');
            document.getElementById('checkInStepDetails').classList.remove('d-none');
            document.getElementById('checkInBackBtn').classList.add('d-none');
            document.getElementById('checkInSubmitBtn').classList.add('d-none');
            document.getElementById('checkInNextBtn').classList.remove('d-none');
        }
    });

    content.addEventListener('submit', async function (e) {
        if (e.target.id !== 'checkInForm') return;
        e.preventDefault();

        const form = e.target;
        // Grouped by room_type_id (see panel.blade.php's data-room-type-id
        // on each select) - one group per distinct room type the booking
        // needs rooms for, matching CheckInController::store()'s
        // room_ids[<room_type_id>][] validation shape.
        const roomIds = {};
        form.querySelectorAll('select.room-select').forEach(function (select) {
            const typeId = select.dataset.roomTypeId;
            if (!roomIds[typeId]) roomIds[typeId] = [];
            roomIds[typeId].push(select.value);
        });
        // Multipart, so a GCash receipt photo can go with it.
        const fd = new FormData();
        if (activeStayId) fd.append('stay_id', activeStayId);
        ['guest_first_name', 'guest_middle_name', 'guest_last_name', 'checkin_permanent_address', 'checkin_current_address',
         'checkin_contact_number', 'adults', 'children', 'amount_received', 'gcash_amount', 'gcash_reference'].forEach(function (name) {
            const el = form.querySelector('[name="' + name + '"]');
            if (el && !el.disabled && el.value !== '') fd.append(name, el.value);
        });
        fd.append('current_address_same_as_permanent', form.querySelector('[name="current_address_same_as_permanent"]').checked ? '1' : '0');
        const method = form.querySelector('[name="payment_method"]:checked');
        if (method && !method.disabled) fd.append('payment_method', method.value);
        const receipt = form.querySelector('[name="gcash_receipt"]');
        if (receipt && receipt.files[0]) fd.append('gcash_receipt', receipt.files[0]);
        const change = form.querySelector('[name="change_returned"]');
        fd.append('change_returned', change && change.checked ? '1' : '0');
        Object.keys(roomIds).forEach(function (typeId) {
            roomIds[typeId].forEach(function (id) { fd.append('room_ids[' + typeId + '][]', id); });
        });

        try {
            const response = await fetch(buildUrl(urls.store, activeBookingId), {
                method: 'POST',
                headers: {
                    'X-CSRF-TOKEN': csrfToken,
                    'Accept': 'application/json',
                },
                body: fd,
            });
            const data = await response.json().catch(() => ({}));
            if (!response.ok) throw new Error(data.message || 'Something went wrong.');

            window.location.reload();
        } catch (err) {
            showError(err.message);
        }
    });

    // Add Amenity modal - AJAX-loaded card grid (see
    // ReceptionistController::amenitiesCreate()). Clicking a card adds it
    // to the "Selected Amenities" list below (or removes it, if already
    // there) - the quantity itself is only ever set/edited in that list,
    // never on the card, so a receptionist can request several different
    // amenities, each with its own quantity, in one submission.
    const amenityModalEl = document.getElementById('amenityModal');
    const amenityModal = bootstrap.Modal.getOrCreateInstance(amenityModalEl);
    const amenityContent = document.getElementById('amenityModalContent');
    let activeAmenityBookingId = null;

    function amenitySelectedList() {
        return amenityContent.querySelector('#amenitySelectedList');
    }

    function amenityCardFor(id) {
        return amenityContent.querySelector('.amenity-pick-card[data-amenity-id="' + id + '"]');
    }

    function amenityRowFor(id) {
        return amenityContent.querySelector('.amenity-selected-row[data-amenity-id="' + id + '"]');
    }

    function updateAmenityState() {
        const submitBtn = amenityContent.querySelector('#amenitySubmitBtn');
        const emptyMsg = amenityContent.querySelector('#amenityEmptyMessage');
        const list = amenitySelectedList();
        if (!submitBtn || !list) return;

        const hasRows = list.children.length > 0;
        submitBtn.disabled = !hasRows;
        if (emptyMsg) emptyMsg.classList.toggle('d-none', hasRows);
    }

    function addAmenityRow(card) {
        const id = card.dataset.amenityId;
        const name = card.dataset.amenityName;
        const charge = parseFloat(card.dataset.amenityCharge) || 0;
        const unlimited = card.dataset.amenityUnlimited === '1';
        const stock = parseInt(card.dataset.amenityStock, 10) || 0;

        const row = document.createElement('div');
        row.className = 'amenity-selected-row';
        row.dataset.amenityId = id;
        row.innerHTML = '<span class="flex-grow-1">' + name + '</span>'
            + '<span class="text-muted small">₱' + charge.toFixed(2) + ' ea.</span>'
            + '<input type="number" class="form-control form-control-sm amenity-selected-qty" style="width: 5rem;" min="1"' + (unlimited ? '' : ' max="' + stock + '"') + ' step="1" value="1">'
            + '<button type="button" class="btn btn-sm btn-outline-danger amenity-remove-btn" title="Remove"><i class="fas fa-times"></i></button>';
        amenitySelectedList().appendChild(row);

        card.classList.add('amenity-pick-card-selected');
        card.querySelector('.amenity-pick-check')?.classList.remove('d-none');
        card.querySelector('.amenity-pick-hint')?.classList.add('d-none');
    }

    function removeAmenityRow(id) {
        amenityRowFor(id)?.remove();
        const card = amenityCardFor(id);
        card?.classList.remove('amenity-pick-card-selected');
        card?.querySelector('.amenity-pick-check')?.classList.add('d-none');
        card?.querySelector('.amenity-pick-hint')?.classList.remove('d-none');
    }

    amenityModalEl.addEventListener('show.bs.modal', function (event) {
        const button = event.relatedTarget;
        activeAmenityBookingId = button.getAttribute('data-booking-id');
        amenityContent.innerHTML = '<div class="modal-body text-center py-5"><i class="fas fa-spinner fa-spin fa-2x"></i></div>';
        fetch(buildUrl(urls.amenityCreate, activeAmenityBookingId), { headers: { 'X-Requested-With': 'XMLHttpRequest' } })
            .then(r => r.text())
            .then(html => { amenityContent.innerHTML = html; })
            .catch(() => { amenityContent.innerHTML = '<div class="modal-body"><div class="alert alert-danger mb-0">Failed to load amenities.</div></div>'; });
    });

    amenityContent.addEventListener('click', async function (e) {
        const card = e.target.closest('.amenity-pick-card');
        if (card && !card.classList.contains('amenity-pick-card-disabled')) {
            const id = card.dataset.amenityId;
            if (amenityRowFor(id)) {
                removeAmenityRow(id);
            } else {
                addAmenityRow(card);
            }
            updateAmenityState();
            return;
        }

        const removeBtn = e.target.closest('.amenity-remove-btn');
        if (removeBtn) {
            const row = removeBtn.closest('.amenity-selected-row');
            if (row) removeAmenityRow(row.dataset.amenityId);
            updateAmenityState();
            return;
        }

        if (!e.target.closest('#amenitySubmitBtn')) return;

        const items = Array.from(amenityContent.querySelectorAll('.amenity-selected-row')).map(function (row) {
            const qty = parseInt(row.querySelector('.amenity-selected-qty').value, 10) || 0;
            return { amenity_id: row.dataset.amenityId, quantity: qty };
        }).filter(item => item.quantity > 0);

        if (items.length === 0) return;

        const errorAlert = amenityContent.querySelector('#amenityErrorAlert');

        try {
            const response = await fetch(buildUrl(urls.amenityStore, activeAmenityBookingId), {
                method: 'POST',
                headers: {
                    'X-CSRF-TOKEN': csrfToken,
                    'Accept': 'application/json',
                    'Content-Type': 'application/json',
                },
                body: JSON.stringify({ items: items, stay_id: (amenityContent.querySelector('#amenityStaySelect') || {}).value || null }),
            });
            const data = await response.json().catch(() => ({}));
            if (!response.ok) throw new Error(data.message || 'Something went wrong.');

            amenityModal.hide();
            window.location.reload();
        } catch (err) {
            if (errorAlert) {
                errorAlert.textContent = err.message;
                errorAlert.classList.remove('d-none');
            } else {
                alert(err.message);
            }
        }
    });
});
</script>
@endpush
@endsection
