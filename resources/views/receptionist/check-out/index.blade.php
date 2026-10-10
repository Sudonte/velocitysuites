@extends('layouts.app')

@section('title', 'Check-Out - Receptionist')

@section('content')
<div class="container-fluid py-4">
    <x-page-header icon="fas fa-sign-out-alt" title="Check-Out" subtitle="Checkout can happen before or after the scheduled date; the bill is settled either way." />

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
            <a class="nav-link {{ $tab === 'expected' ? 'active' : '' }}" href="{{ route('receptionist.check-out.index', ['tab' => 'expected']) }}">
                Expected Check-outs <span class="badge bg-warning text-dark">{{ $expectedCount }}</span>
            </a>
        </li>
        <li class="nav-item">
            <a class="nav-link {{ $tab === 'checked_out' ? 'active' : '' }}" href="{{ route('receptionist.check-out.index', ['tab' => 'checked_out']) }}">
                Checked-out Guests <span class="badge bg-secondary">{{ $checkedOutCount }}</span>
            </a>
        </li>
    </ul>

    <div class="card border-0 shadow-sm">
        <div class="card-header">
            <h5 class="mb-0"><i class="fas fa-list"></i> {{ $tab === 'expected' ? 'Expected Check-outs' : 'Checked-out Guests' }}</h5>
        </div>
        <div class="table-responsive">
            <table class="table table-hover mb-0" id="checkOutTable">
                <thead>
                    <tr>
                        <th>Guest</th>
                        <th>Room</th>
                        <th>Check-Out</th>
                        <th>Bill</th>
                        @if($tab === 'expected')<th>Action</th>@endif
                    </tr>
                </thead>
                <tbody id="checkOutTableBody">
                    @forelse($bookings as $booking)
                        <tr data-booking-id="{{ $booking->id }}">
                            <td>@unless($booking->viewed_at)<span class="unread-dot" title="New"></span>@endunless{{ $booking->guest_display_name }}</td>
                            <td>
                                {{-- Each room's OWN type, not the booking's single legacy
                                     room_type_id - a Standard + Superior multi-room booking
                                     previously labeled every room number with the same one
                                     type instead of each room's actual type. --}}
                                @if($booking->rooms->count() > 1)
                                    {{ $booking->rooms->map(fn ($r) => $r->room_number . ' (' . ($r->roomType->name ?? '') . ')')->implode(', ') }}
                                @else
                                    {{ $booking->room->room_number ?? 'N/A' }} ({{ $booking->room->roomType->name ?? $booking->roomType->name ?? '' }})
                                @endif
                            </td>
                            <td>
                                {{ $booking->check_out->format('M d, Y') }}
                                @if($tab === 'expected' && $booking->check_out->isAfter(today()))
                                    <span class="badge bg-info" title="Departing before the scheduled date">Early</span>
                                @endif
                            </td>
                            <td class="bill-status-cell">
                                @if($tab === 'checked_out')
                                    @if($booking->billing)
                                        <a href="{{ route('receptionist.billing.receipt', $booking->billing) }}" class="btn btn-sm btn-outline-secondary">
                                            <i class="fas fa-receipt"></i> View Receipt
                                        </a>
                                    @else
                                        <span class="text-muted">N/A</span>
                                    @endif
                                @elseif($booking->billing && $booking->billing->billing_status === 'partial')
                                    <span class="badge bg-warning text-dark">Partially Paid</span>
                                @else
                                    <span class="text-muted">Not started</span>
                                @endif
                            </td>
                            @if($tab === 'expected')
                                <td>
                                    @if($booking->rooms->count() > 1)
                                        {{-- Multi-room: each room checks out independently -
                                             see the Rooms Panel modal below. Billing only
                                             starts once every room in this booking has
                                             checked out. --}}
                                        <button type="button" class="btn btn-sm btn-outline-primary btn-view-rooms"
                                            data-booking-id="{{ $booking->id }}">
                                            <i class="fas fa-eye"></i> View Rooms
                                        </button>
                                    @else
                                        <button type="button" class="btn btn-sm btn-primary btn-start-checkout"
                                            data-booking-id="{{ $booking->id }}"
                                            data-guest-name="{{ $booking->guest_display_name }}"
                                            data-room-number="{{ $booking->room->room_number ?? 'N/A' }}">
                                            <i class="fas fa-sign-out-alt"></i> Check Out
                                        </button>
                                    @endif
                                </td>
                            @endif
                        </tr>
                    @empty
                        <tr id="noCheckOutsRow">
                            <td colspan="{{ $tab === 'expected' ? 5 : 4 }}">
                                <x-empty-state icon="fas fa-sign-out-alt" :message="$tab === 'expected' ? 'No pending check-outs.' : 'No checked-out guests yet.'" />
                            </td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
        <div class="card-footer">
            {{ $bookings->links() }}
        </div>
    </div>
</div>

<!-- Step 1: Check-Out Confirmation Modal -->
<div class="modal fade" id="confirmCheckoutModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header modal-header-brand">
                <h5 class="modal-title"><i class="fas fa-sign-out-alt"></i> Check Out Guest</h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <p class="mb-1"><strong>Guest:</strong> <span id="confirmGuestName"></span></p>
                <p class="mb-1"><strong>Room:</strong> <span id="confirmRoomNumber"></span></p>
                <p class="mb-1"><strong>Booking:</strong> <span id="confirmReservationCode"></span></p>
                <p class="text-muted mt-3 mb-0">Are you sure you want to begin the check-out process?</p>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                <button type="button" class="btn btn-primary" id="continueToBillingBtn">
                    <i class="fas fa-arrow-right"></i> Continue to Billing
                </button>
            </div>
        </div>
    </div>
</div>

<!-- Step 2: Billing Panel Modal -->
<div class="modal fade" id="billingPanelModal" tabindex="-1" data-bs-backdrop="static">
    <div class="modal-dialog modal-lg modal-dialog-scrollable">
        <div class="modal-content" id="billingPanelContent">
            <!-- Injected via AJAX -->
        </div>
    </div>
</div>

<!-- Step 3: Payment Panel Modal -->
<div class="modal fade" id="paymentPanelModal" tabindex="-1" data-bs-backdrop="static">
    <div class="modal-dialog modal-dialog-scrollable">
        <div class="modal-content" id="paymentPanelContent">
            <!-- Injected via AJAX -->
        </div>
    </div>
</div>

<!-- Multi-Room Checkout Picker (AJAX-loaded room cards, one Check Out
     button per room - see CheckOutController::roomsPanel()/checkOutRoom()).
     Checking out the last remaining room closes this and continues
     straight into the same Billing Panel modal above. -->
<div class="modal fade" id="roomsPanelModal" tabindex="-1" data-bs-backdrop="static">
    <div class="modal-dialog modal-lg modal-dialog-scrollable">
        <div class="modal-content" id="roomsPanelContent">
            <!-- Injected via AJAX -->
        </div>
    </div>
</div>

@push('scripts')
<script>
document.addEventListener('DOMContentLoaded', function () {
    const csrfToken = document.querySelector('meta[name="csrf-token"]').content;

    const confirmModalEl = document.getElementById('confirmCheckoutModal');
    const billingModalEl = document.getElementById('billingPanelModal');
    const paymentModalEl = document.getElementById('paymentPanelModal');
    const roomsModalEl = document.getElementById('roomsPanelModal');
    const confirmModal = new bootstrap.Modal(confirmModalEl);
    const billingModal = new bootstrap.Modal(billingModalEl);
    const paymentModal = new bootstrap.Modal(paymentModalEl);
    const roomsModal = new bootstrap.Modal(roomsModalEl);

    const billingPanelContent = document.getElementById('billingPanelContent');
    const paymentPanelContent = document.getElementById('paymentPanelContent');
    const roomsPanelContent = document.getElementById('roomsPanelContent');

    let activeBookingId = null;

    const urls = {
        billing: @json(route('receptionist.check-out.billing', ['booking' => '__ID__'])),
        cancelBilling: @json(route('receptionist.check-out.billing.cancel', ['billing' => '__ID__'])),
        payment: @json(route('receptionist.check-out.payment', ['billing' => '__ID__'])),
        chargeStore: @json(route('receptionist.billing.additional-charge.store', ['billing' => '__ID__'])),
        chargeUpdate: @json(route('receptionist.billing.additional-charge.update', ['additionalCharge' => '__ID__'])),
        chargeDestroy: @json(route('receptionist.billing.additional-charge.destroy', ['additionalCharge' => '__ID__'])),
        discountStore: @json(route('receptionist.billing.discount.store', ['billing' => '__ID__'])),
        recordPayment: @json(route('receptionist.billing.payment.store', ['billing' => '__ID__'])),
        rooms: @json(route('receptionist.check-out.rooms', ['booking' => '__ID__'])),
        roomCheckout: @json(route('receptionist.check-out.rooms.checkout', ['booking' => '__BOOKING__', 'room' => '__ROOM__'])),
        roomsCheckout: @json(route('receptionist.check-out.rooms.checkout-many', ['booking' => '__ID__'])),
    };

    function buildUrl(template, id) {
        return template.replace('__ID__', id);
    }

    function buildRoomCheckoutUrl(bookingId, roomId) {
        return urls.roomCheckout.replace('__BOOKING__', bookingId).replace('__ROOM__', roomId);
    }

    async function fetchJson(url, options = {}) {
        const response = await fetch(url, {
            ...options,
            headers: {
                'X-CSRF-TOKEN': csrfToken,
                'Accept': 'application/json',
                ...(options.headers || {}),
            },
        });
        const data = await response.json().catch(() => ({}));
        if (!response.ok) {
            throw new Error(data.message || 'Something went wrong.');
        }
        return data;
    }

    async function fetchHtml(url) {
        const response = await fetch(url, {
            headers: { 'X-CSRF-TOKEN': csrfToken },
        });
        if (!response.ok) {
            const data = await response.json().catch(() => ({}));
            throw new Error(data.message || 'Something went wrong.');
        }
        return response.text();
    }

    // ---- Step 1: Open confirmation modal (single-room bookings only) ----
    document.getElementById('checkOutTableBody').addEventListener('click', function (e) {
        const startBtn = e.target.closest('.btn-start-checkout');
        if (startBtn) {
            activeBookingId = startBtn.dataset.bookingId;
            document.getElementById('confirmGuestName').textContent = startBtn.dataset.guestName;
            document.getElementById('confirmRoomNumber').textContent = startBtn.dataset.roomNumber;
            document.getElementById('confirmReservationCode').textContent = 'BKG-' + String(activeBookingId).padStart(5, '0');
            confirmModal.show();
            return;
        }

        // ---- Multi-room bookings: open the room-by-room checkout picker ----
        const viewRoomsBtn = e.target.closest('.btn-view-rooms');
        if (viewRoomsBtn) {
            activeBookingId = viewRoomsBtn.dataset.bookingId;
            openRoomsPanel();
        }
    });

    async function openRoomsPanel() {
        try {
            const html = await fetchHtml(buildUrl(urls.rooms, activeBookingId));
            roomsPanelContent.innerHTML = html;
            roomsModal.show();
        } catch (err) {
            alert(err.message);
        }
    }

    // ---- Rooms Panel: tick rooms, or check out everything still in house ----
    function selectedRoomIds() {
        return Array.from(roomsPanelContent.querySelectorAll('.room-select:checked')).map((box) => Number(box.value));
    }

    function refreshRoomSelection() {
        const boxes = roomsPanelContent.querySelectorAll('.room-select');
        const picked = selectedRoomIds().length;
        const count = roomsPanelContent.querySelector('#selectedRoomCount');
        const go = roomsPanelContent.querySelector('#btnCheckoutSelected');
        const all = roomsPanelContent.querySelector('#selectAllRooms');
        if (count) count.textContent = picked;
        if (go) go.disabled = picked === 0;
        if (all) all.checked = boxes.length > 0 && picked === boxes.length;
    }

    roomsPanelContent.addEventListener('change', function (e) {
        if (e.target.id === 'selectAllRooms') {
            roomsPanelContent.querySelectorAll('.room-select').forEach((box) => { box.checked = e.target.checked; });
        }
        if (e.target.id === 'selectAllRooms' || e.target.classList.contains('room-select')) refreshRoomSelection();
    });

    async function checkoutRooms(btn, body, title, message) {
        const proceed = await window.confirmAction({ title: title, message: message, button: 'Check Out', variant: 'primary' }, btn);
        if (!proceed) return;

        btn.disabled = true;
        try {
            const data = await fetchJson(buildUrl(urls.roomsCheckout, activeBookingId), {
                method: 'PUT',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify(body),
            });

            if (data.final) {
                roomsModal.hide();
                const html = await fetchHtml(buildUrl(urls.billing, activeBookingId));
                billingPanelContent.innerHTML = html;
                billingModal.show();
            } else {
                roomsPanelContent.innerHTML = data.html;
            }
        } catch (err) {
            const alertBox = roomsPanelContent.querySelector('#roomsErrorAlert');
            if (alertBox) {
                alertBox.textContent = err.message;
                alertBox.classList.remove('d-none');
            } else {
                alert(err.message);
            }
            btn.disabled = false;
        }
    }

    roomsPanelContent.addEventListener('click', function (e) {
        const selectedBtn = e.target.closest('#btnCheckoutSelected');
        if (selectedBtn) {
            const ids = selectedRoomIds();
            if (ids.length === 0) return;
            checkoutRooms(selectedBtn, { room_ids: ids }, 'Check out ' + ids.length + ' room(s)?', 'They become free right away; any room not ticked stays checked in.');
            return;
        }
        const allBtn = e.target.closest('#btnCheckoutAllRooms');
        if (allBtn) {
            checkoutRooms(allBtn, { all: true }, 'Check out all remaining rooms?', 'Every room still in house leaves now and billing opens next.');
        }
    });

    // ---- Rooms Panel interactions: check out one room at a time ----
    roomsPanelContent.addEventListener('click', async function (e) {
        const btn = e.target.closest('.btn-checkout-room');
        if (!btn) return;

        const isLast = btn.dataset.lastRoom === '1';
        const proceed = await window.confirmAction({
            title: 'Check out Room ' + btn.dataset.roomNumber + '?',
            message: isLast ? 'This is the last room - billing opens next.' : 'The room becomes free right away; the booking stays open for its other rooms.',
            button: 'Check Out Room',
            variant: 'primary',
        }, btn);
        if (!proceed) return;

        btn.disabled = true;
        try {
            const data = await fetchJson(buildRoomCheckoutUrl(activeBookingId, btn.dataset.roomId), { method: 'PUT' });

            if (data.final) {
                // Last room just checked out - proceed straight into the
                // same Billing Panel a single-room booking's "Continue to
                // Billing" step opens.
                roomsModal.hide();
                const html = await fetchHtml(buildUrl(urls.billing, activeBookingId));
                billingPanelContent.innerHTML = html;
                billingModal.show();
            } else {
                roomsPanelContent.innerHTML = data.html;
            }
        } catch (err) {
            const alertBox = roomsPanelContent.querySelector('#roomsErrorAlert');
            if (alertBox) {
                alertBox.textContent = err.message;
                alertBox.classList.remove('d-none');
            } else {
                alert(err.message);
            }
            btn.disabled = false;
        }
    });

    // ---- Step 1 -> 2: Continue to Billing ----
    document.getElementById('continueToBillingBtn').addEventListener('click', async function () {
        try {
            const html = await fetchHtml(buildUrl(urls.billing, activeBookingId));
            billingPanelContent.innerHTML = html;
            confirmModal.hide();
            billingModal.show();
        } catch (err) {
            alert(err.message);
        }
    });

    function currentBillingId() {
        const body = billingPanelContent.querySelector('.modal-body');
        return body ? body.dataset.billingId : null;
    }

    function showBillingError(message) {
        const alertBox = billingPanelContent.querySelector('#billingErrorAlert');
        if (alertBox) {
            alertBox.textContent = message;
            alertBox.classList.remove('d-none');
        } else {
            alert(message);
        }
    }

    async function reloadBillingPanel() {
        const html = await fetchHtml(buildUrl(urls.billing, activeBookingId));
        billingPanelContent.innerHTML = html;
    }

    // ---- Billing Panel interactions (event delegation, content is re-injected) ----
    billingPanelContent.addEventListener('click', async function (e) {
        // Show/hide add-charge form
        if (e.target.closest('#showAddChargeFormBtn')) {
            document.getElementById('addChargeForm').classList.toggle('d-none');
            return;
        }

        // Cancel Billing
        if (e.target.closest('#cancelBillingBtn')) {
            if (!(await window.confirmAction({ title: 'Discard this bill?', message: 'Nothing on it will be saved.', button: 'Discard Bill', variant: 'danger' }, e.target))) return;
            try {
                await fetchJson(buildUrl(urls.cancelBilling, currentBillingId()), { method: 'DELETE' });
                billingModal.hide();
            } catch (err) {
                showBillingError(err.message);
            }
            return;
        }

        // Proceed to Payment
        if (e.target.closest('#proceedToPaymentBtn')) {
            try {
                const html = await fetchHtml(buildUrl(urls.payment, currentBillingId()));
                paymentPanelContent.innerHTML = html;
                billingModal.hide();
                paymentModal.show();
            } catch (err) {
                showBillingError(err.message);
            }
            return;
        }

        // Edit charge row
        const editBtn = e.target.closest('.charge-edit-btn');
        if (editBtn) {
            const row = editBtn.closest('tr');
            row.querySelectorAll('.charge-view-field').forEach(el => el.classList.add('d-none'));
            row.querySelectorAll('.charge-edit-field').forEach(el => el.classList.remove('d-none'));
            editBtn.classList.add('d-none');
            row.querySelector('.charge-save-btn').classList.remove('d-none');
            return;
        }

        // Save edited charge row
        const saveBtn = e.target.closest('.charge-save-btn');
        if (saveBtn) {
            const row = saveBtn.closest('tr');
            const chargeId = row.dataset.chargeId;
            const payload = {};
            row.querySelectorAll('.charge-edit-field').forEach(el => {
                payload[el.dataset.field] = el.value;
            });
            try {
                const data = await fetchJson(buildUrl(urls.chargeUpdate, chargeId), {
                    method: 'PUT',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify(payload),
                });
                document.getElementById('chargesTableContainer').innerHTML = data.html;
                document.getElementById('runningTotalDisplay').textContent = '₱' + Number(data.running_total).toLocaleString('en-PH', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
            } catch (err) {
                showBillingError(err.message);
            }
            return;
        }

        // Delete charge row
        const deleteBtn = e.target.closest('.charge-delete-btn');
        if (deleteBtn) {
            if (!(await window.confirmAction({ title: 'Remove this charge?', message: 'It will be taken off the bill.', button: 'Remove Charge', variant: 'danger' }, e.target))) return;
            const row = deleteBtn.closest('tr');
            const chargeId = row.dataset.chargeId;
            try {
                const data = await fetchJson(buildUrl(urls.chargeDestroy, chargeId), { method: 'DELETE' });
                document.getElementById('chargesTableContainer').innerHTML = data.html;
                document.getElementById('runningTotalDisplay').textContent = '₱' + Number(data.running_total).toLocaleString('en-PH', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
            } catch (err) {
                showBillingError(err.message);
            }
            return;
        }
    });

    // Add charge form submit
    billingPanelContent.addEventListener('submit', async function (e) {
        if (e.target.id !== 'addChargeForm') return;
        e.preventDefault();
        const form = e.target;
        const payload = Object.fromEntries(new FormData(form).entries());
        try {
            const data = await fetchJson(buildUrl(urls.chargeStore, currentBillingId()), {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify(payload),
            });
            document.getElementById('chargesTableContainer').innerHTML = data.html;
            document.getElementById('runningTotalDisplay').textContent = '₱' + Number(data.running_total).toLocaleString('en-PH', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
        } catch (err) {
            showBillingError(err.message);
        }
    });

    // Apply discount form submit - reloads the whole panel since the
    // discount line above Running Total lives outside the swapped fragment.
    billingPanelContent.addEventListener('submit', async function (e) {
        if (e.target.id !== 'applyDiscountForm') return;
        e.preventDefault();
        const form = e.target;
        const payload = Object.fromEntries(new FormData(form).entries());
        try {
            await fetchJson(buildUrl(urls.discountStore, currentBillingId()), {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify(payload),
            });
            await reloadBillingPanel();
        } catch (err) {
            showBillingError(err.message);
        }
    });

    // ---- Payment Panel interactions ----
    const peso = (n) => '₱' + Number(n).toLocaleString('en-PH', { minimumFractionDigits: 2, maximumFractionDigits: 2 });

    // The amount must be more than 0 and no more than the remaining balance. Shown inline, and the submit button is
    // disabled while it isn't - the server re-checks the same rule under a row lock, this is only the early warning.
    function validateAmount() {
        const input = paymentPanelContent.querySelector('#amountPaidInput');
        const error = paymentPanelContent.querySelector('#amountPaidError');
        const submit = paymentPanelContent.querySelector('#completePaymentBtn');
        if (!input) return true; // fully-paid stay: nothing to collect, nothing to validate

        const balanceCents = Math.round(parseFloat(input.dataset.balance) * 100);
        const raw = input.value.trim();
        const amount = parseFloat(raw);
        let message = '';
        if (raw === '' || isNaN(amount)) {
            message = 'Enter the amount received.';
        } else if (amount <= 0) {
            message = 'The amount must be greater than ₱0.00.';
        } else if (Math.round(amount * 100) > balanceCents) {
            message = 'The amount can\'t be more than the remaining balance of ' + peso(balanceCents / 100) + '.';
        }

        error.textContent = message;
        error.classList.toggle('d-none', message === '');
        input.classList.toggle('is-invalid', message !== '');
        if (submit) submit.disabled = message !== '';
        return message === '';
    }

    function syncPaymentMethod() {
        const methodSelect = paymentPanelContent.querySelector('#paymentMethodSelect');
        const refGroup = paymentPanelContent.querySelector('#referenceNumberGroup');
        if (methodSelect && refGroup) refGroup.classList.toggle('d-none', methodSelect.value !== 'gcash');
    }

    paymentModalEl.addEventListener('shown.bs.modal', function () { syncPaymentMethod(); validateAmount(); });
    paymentPanelContent.addEventListener('change', function (e) {
        if (e.target.id === 'paymentMethodSelect') syncPaymentMethod();
        if (e.target.id === 'amountPaidInput') validateAmount();
    });
    paymentPanelContent.addEventListener('input', function (e) {
        if (e.target.id === 'amountPaidInput') validateAmount();
    });

    function currentPaymentBillingId() {
        const body = paymentPanelContent.querySelector('.modal-body');
        return body ? body.dataset.billingId : null;
    }

    function showPaymentError(message) {
        const alertBox = paymentPanelContent.querySelector('#paymentErrorAlert');
        if (alertBox) {
            alertBox.textContent = message;
            alertBox.classList.remove('d-none');
        } else {
            alert(message);
        }
    }

    // Back to Billing
    paymentPanelContent.addEventListener('click', async function (e) {
        if (e.target.closest('#backToBillingBtn')) {
            try {
                await reloadBillingPanel();
                paymentModal.hide();
                billingModal.show();
            } catch (err) {
                showPaymentError(err.message);
            }
        }
    });

    // Complete Payment
    paymentPanelContent.addEventListener('submit', async function (e) {
        if (e.target.id !== 'paymentForm') return;
        e.preventDefault();

        const form = e.target;
        if (!validateAmount()) return;
        const payload = Object.fromEntries(new FormData(form).entries());
        const method = payload.payment_method;

        if (method === 'gcash' && !payload.reference_number) {
            showPaymentError('Reference number is required for GCash payments.');
            return;
        }

        // The backend already serializes/guards this via a locked
        // transaction (see Receptionist\CheckOutController::recordPayment()),
        // so a double-click can't actually duplicate a payment or checkout -
        // but without this, the second click would still fire a second
        // request and surface a confusing "not awaiting checkout" error
        // instead of just being prevented outright.
        const submitBtn = form.querySelector('button[type="submit"]');
        if (submitBtn) submitBtn.disabled = true;

        try {
            const data = await fetchJson(buildUrl(urls.recordPayment, currentPaymentBillingId()), {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify(payload),
            });

            paymentModal.hide();

            const row = document.querySelector('tr[data-booking-id="' + activeBookingId + '"]');

            if (data.completed) {
                if (row) row.remove();
                const tbody = document.getElementById('checkOutTableBody');
                if (!tbody.querySelector('tr')) {
                    tbody.innerHTML = '<tr id="noCheckOutsRow"><td colspan="5" class="text-center text-muted py-4">No pending check-outs.</td></tr>';
                }
                alert(data.message + (data.receipt_url ? '\n\nReceipt: ' + data.receipt_url : ''));
            } else {
                if (row) {
                    const cell = row.querySelector('.bill-status-cell');
                    cell.innerHTML = '<span class="badge bg-warning text-dark">Partially Paid</span>';
                }
                alert(data.message + ' Remaining balance: ₱' + Number(data.balance).toLocaleString('en-PH', { minimumFractionDigits: 2, maximumFractionDigits: 2 }));
            }

            activeBookingId = null;
        } catch (err) {
            showPaymentError(err.message);
        } finally {
            if (submitBtn) submitBtn.disabled = false;
            validateAmount();
        }
    });
});
</script>
@endpush
@endsection
