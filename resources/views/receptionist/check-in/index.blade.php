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
            <a class="nav-link {{ $tab === 'expected' ? 'active' : '' }}" href="{{ route('receptionist.check-in.index', ['tab' => 'expected']) }}">
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
        <table class="table table-hover mb-0 align-middle">
            <thead>
                <tr>
                    <th>Guest</th>
                    <th>Room</th>
                    <th>Check-In</th>
                    <th>Check-Out</th>
                    <th>Guests</th>
                    <th class="text-nowrap">Actions</th>
                </tr>
            </thead>
            <tbody>
                @forelse($bookings as $booking)
                    <tr>
                        <td>@unless($booking->viewed_at)<span class="unread-dot" title="New"></span>@endunless{{ $booking->guest_display_name }}</td>
                        <td style="min-width: 200px;">
                            @if($booking->rooms->isNotEmpty())
                                {{ $booking->rooms->pluck('room_number')->implode(', ') }}
                                ({{ $booking->roomType->name ?? '' }})
                                @if($tab === 'checked_in')
                                    <x-status-badge :status="$booking->rooms->first()->status" domain="room" />
                                @endif
                            @elseif($tab === 'expected')
                                <span class="text-muted small"><i class="fas fa-door-open"></i> Not yet assigned</span>
                            @else
                                <span class="text-muted">N/A</span>
                            @endif
                        </td>
                        <td>
                            {{ $booking->check_in->format('M d, Y') }}
                            @if($tab === 'expected' && $booking->check_in->isAfter(today()))
                                <span class="badge bg-info" title="Scheduled for a future date">Early</span>
                            @endif
                        </td>
                        <td>{{ $booking->check_out->format('M d, Y') }}</td>
                        <td>{{ $booking->number_of_guests }}</td>
                        <td class="text-nowrap">
                            @if($tab === 'expected')
                                <button type="button" class="btn btn-sm btn-success btn-open-check-in" data-booking-id="{{ $booking->id }}">
                                    <i class="fas fa-sign-in-alt"></i> Check In
                                </button>
                            @else
                                <button type="button" class="btn btn-sm btn-outline-primary btn-open-amenity" data-bs-toggle="modal"
                                        data-bs-target="#amenityModal" data-booking-id="{{ $booking->id }}">
                                    <i class="fas fa-spa"></i> Add Amenity
                                </button>
                            @endif
                        </td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="6">
                            <x-empty-state icon="fas fa-sign-in-alt" :message="$tab === 'expected' ? 'No expected check-ins.' : 'No checked-in guests.'" />
                        </td>
                    </tr>
                @endforelse
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
    <div class="modal-dialog modal-dialog-scrollable">
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

    async function openCheckInPanel(bookingId) {
        activeBookingId = bookingId;
        content.innerHTML = '<div class="modal-body text-center py-5"><i class="fas fa-spinner fa-spin fa-2x"></i></div>';
        modal.show();

        try {
            const response = await fetch(buildUrl(urls.panel, activeBookingId), {
                headers: { 'X-Requested-With': 'XMLHttpRequest' },
            });
            if (!response.ok) {
                const data = await response.json().catch(() => ({}));
                throw new Error(data.message || 'Failed to load the check-in panel.');
            }
            content.innerHTML = await response.text();
        } catch (err) {
            content.innerHTML = '<div class="modal-body"><div class="alert alert-danger mb-0">' + err.message + '</div></div>';
        }
    }

    document.querySelectorAll('.btn-open-check-in').forEach(function (btn) {
        btn.addEventListener('click', function () {
            openCheckInPanel(btn.dataset.bookingId);
        });
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
    content.addEventListener('click', function (e) {
        if (e.target.closest('#checkInNextBtn')) {
            const form = document.getElementById('checkInForm');
            if (!form.reportValidity()) return;

            content.querySelectorAll('.room-select').forEach(s => s.disabled = false);
            document.getElementById('checkInStepDetails').classList.add('d-none');
            document.getElementById('checkInStepRooms').classList.remove('d-none');
            document.getElementById('checkInNextBtn').classList.add('d-none');
            document.getElementById('checkInBackBtn').classList.remove('d-none');
            document.getElementById('checkInSubmitBtn').classList.remove('d-none');
        } else if (e.target.closest('#checkInBackBtn')) {
            content.querySelectorAll('.room-select').forEach(s => s.disabled = true);
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
        const roomIds = Array.from(form.querySelectorAll('select[name="room_ids[]"]')).map(s => s.value);
        const payload = {
            guest_first_name: form.querySelector('[name="guest_first_name"]').value,
            guest_middle_name: form.querySelector('[name="guest_middle_name"]').value,
            guest_last_name: form.querySelector('[name="guest_last_name"]').value,
            checkin_permanent_address: form.querySelector('[name="checkin_permanent_address"]').value,
            checkin_current_address: form.querySelector('[name="checkin_current_address"]').value,
            current_address_same_as_permanent: form.querySelector('[name="current_address_same_as_permanent"]').checked,
            checkin_contact_number: form.querySelector('[name="checkin_contact_number"]').value,
            adults: form.querySelector('[name="adults"]').value,
            children: form.querySelector('[name="children"]').value,
            room_ids: roomIds,
        };

        try {
            const response = await fetch(buildUrl(urls.store, activeBookingId), {
                method: 'POST',
                headers: {
                    'X-CSRF-TOKEN': csrfToken,
                    'Accept': 'application/json',
                    'Content-Type': 'application/json',
                },
                body: JSON.stringify(payload),
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
        const stock = parseInt(card.dataset.amenityStock, 10) || 0;

        const row = document.createElement('div');
        row.className = 'amenity-selected-row';
        row.dataset.amenityId = id;
        row.innerHTML = '<span class="flex-grow-1">' + name + '</span>'
            + '<span class="text-muted small">₱' + charge.toFixed(2) + ' ea.</span>'
            + '<input type="number" class="form-control form-control-sm amenity-selected-qty" style="width: 5rem;" min="1" max="' + stock + '" step="1" value="1">'
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
                body: JSON.stringify({ items: items }),
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
