@extends('layouts.app')

@section('title', 'Check-In - Receptionist')

@section('content')
<div class="container-fluid py-4">
    <x-page-header icon="fas fa-sign-in-alt" title="Check-In" subtitle="Guests currently staying at the hotel - check out from here when their stay ends." />

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

    <div class="card border-0 shadow-sm">
        <div class="card-header">
            <h5 class="mb-0"><i class="fas fa-list"></i> Currently Checked In</h5>
        </div>
        <div class="table-responsive">
            <table class="table table-hover mb-0" id="checkOutTable">
                <thead>
                    <tr>
                        <th>Guest</th>
                        <th>Room</th>
                        <th>Check-In</th>
                        <th>Check-Out</th>
                        <th>Bill</th>
                        <th>Action</th>
                    </tr>
                </thead>
                <tbody id="checkOutTableBody">
                    @forelse($reservations as $reservation)
                        <tr data-reservation-id="{{ $reservation->id }}">
                            <td>{{ $reservation->guest->user->full_name ?? 'N/A' }}</td>
                            <td>{{ $reservation->room->room_number ?? 'N/A' }} ({{ $reservation->room->roomType->name ?? '' }})</td>
                            <td>{{ $reservation->check_in->format('M d, Y') }}</td>
                            <td>
                                {{ $reservation->check_out->format('M d, Y') }}
                                @if($reservation->check_out->isPast())
                                    <span class="badge bg-warning text-dark" title="Past the scheduled check-out date">Overdue</span>
                                @endif
                            </td>
                            <td class="bill-status-cell">
                                @if($reservation->booking && $reservation->booking->billing && $reservation->booking->billing->billing_status === 'partial')
                                    <span class="badge bg-warning text-dark">Partially Paid</span>
                                @else
                                    <span class="text-muted">Not started</span>
                                @endif
                            </td>
                            <td>
                                <button type="button" class="btn btn-sm btn-primary btn-start-checkout"
                                    data-reservation-id="{{ $reservation->id }}"
                                    data-guest-name="{{ $reservation->guest->user->full_name ?? 'N/A' }}"
                                    data-room-number="{{ $reservation->room->room_number ?? 'N/A' }}">
                                    <i class="fas fa-sign-out-alt"></i> Check Out
                                </button>
                            </td>
                        </tr>
                    @empty
                        <tr id="noCheckOutsRow">
                            <td colspan="6"><x-empty-state icon="fas fa-sign-in-alt" message="No guests currently checked in." /></td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
        <div class="card-footer">
            {{ $reservations->links() }}
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
                <p class="mb-1"><strong>Reservation:</strong> <span id="confirmReservationCode"></span></p>
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

@push('scripts')
<script>
document.addEventListener('DOMContentLoaded', function () {
    const csrfToken = document.querySelector('meta[name="csrf-token"]').content;

    const confirmModalEl = document.getElementById('confirmCheckoutModal');
    const billingModalEl = document.getElementById('billingPanelModal');
    const paymentModalEl = document.getElementById('paymentPanelModal');
    const confirmModal = new bootstrap.Modal(confirmModalEl);
    const billingModal = new bootstrap.Modal(billingModalEl);
    const paymentModal = new bootstrap.Modal(paymentModalEl);

    const billingPanelContent = document.getElementById('billingPanelContent');
    const paymentPanelContent = document.getElementById('paymentPanelContent');

    let activeReservationId = null;

    const urls = {
        billing: @json(route('receptionist.check-out.billing', ['reservation' => '__ID__'])),
        cancelBilling: @json(route('receptionist.check-out.billing.cancel', ['billing' => '__ID__'])),
        payment: @json(route('receptionist.check-out.payment', ['billing' => '__ID__'])),
        chargeStore: @json(route('receptionist.billing.additional-charge.store', ['billing' => '__ID__'])),
        chargeUpdate: @json(route('receptionist.billing.additional-charge.update', ['additionalCharge' => '__ID__'])),
        chargeDestroy: @json(route('receptionist.billing.additional-charge.destroy', ['additionalCharge' => '__ID__'])),
        recordPayment: @json(route('receptionist.billing.payment.store', ['billing' => '__ID__'])),
    };

    function buildUrl(template, id) {
        return template.replace('__ID__', id);
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

    // ---- Step 1: Open confirmation modal ----
    document.getElementById('checkOutTableBody').addEventListener('click', function (e) {
        const btn = e.target.closest('.btn-start-checkout');
        if (!btn) return;

        activeReservationId = btn.dataset.reservationId;
        document.getElementById('confirmGuestName').textContent = btn.dataset.guestName;
        document.getElementById('confirmRoomNumber').textContent = btn.dataset.roomNumber;
        document.getElementById('confirmReservationCode').textContent = 'RSV-' + String(activeReservationId).padStart(5, '0');
        confirmModal.show();
    });

    // ---- Step 1 -> 2: Continue to Billing ----
    document.getElementById('continueToBillingBtn').addEventListener('click', async function () {
        try {
            const html = await fetchHtml(buildUrl(urls.billing, activeReservationId));
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
        const html = await fetchHtml(buildUrl(urls.billing, activeReservationId));
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
            if (!confirm('Discard this bill? Nothing will be saved.')) return;
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
            if (!confirm('Remove this charge?')) return;
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

    // ---- Payment Panel interactions ----
    function updateChangeDue() {
        const methodSelect = paymentPanelContent.querySelector('#paymentMethodSelect');
        const amountInput = paymentPanelContent.querySelector('#amountPaidInput');
        const refGroup = paymentPanelContent.querySelector('#referenceNumberGroup');
        const changeGroup = paymentPanelContent.querySelector('#changeDueGroup');
        const changeDisplay = paymentPanelContent.querySelector('#changeDueDisplay');
        if (!methodSelect) return;

        const balance = parseFloat(amountInput.dataset.balance);
        const amount = parseFloat(amountInput.value) || 0;

        refGroup.classList.toggle('d-none', methodSelect.value !== 'gcash');

        if (methodSelect.value === 'cash' && amount > balance) {
            changeGroup.classList.remove('d-none');
            changeDisplay.value = '₱' + (amount - balance).toLocaleString('en-PH', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
        } else {
            changeGroup.classList.add('d-none');
        }
    }

    paymentPanelContent.addEventListener('change', function (e) {
        if (e.target.id === 'paymentMethodSelect' || e.target.id === 'amountPaidInput') {
            updateChangeDue();
        }
    });
    paymentPanelContent.addEventListener('input', function (e) {
        if (e.target.id === 'amountPaidInput') updateChangeDue();
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
        const payload = Object.fromEntries(new FormData(form).entries());
        const method = payload.payment_method;

        if (method === 'gcash' && !payload.reference_number) {
            showPaymentError('Reference number is required for GCash payments.');
            return;
        }

        try {
            const data = await fetchJson(buildUrl(urls.recordPayment, currentPaymentBillingId()), {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify(payload),
            });

            paymentModal.hide();

            const row = document.querySelector('tr[data-reservation-id="' + activeReservationId + '"]');

            if (data.completed) {
                if (row) row.remove();
                const tbody = document.getElementById('checkOutTableBody');
                if (!tbody.querySelector('tr')) {
                    tbody.innerHTML = '<tr id="noCheckOutsRow"><td colspan="6" class="text-center text-muted py-4">No guests currently checked in.</td></tr>';
                }
                alert(data.message + (data.receipt_url ? '\n\nReceipt: ' + data.receipt_url : ''));
            } else {
                if (row) {
                    const cell = row.querySelector('.bill-status-cell');
                    cell.innerHTML = '<span class="badge bg-warning text-dark">Partially Paid</span>';
                }
                alert(data.message + ' Remaining balance: ₱' + Number(data.balance).toLocaleString('en-PH', { minimumFractionDigits: 2, maximumFractionDigits: 2 }));
            }

            activeReservationId = null;
        } catch (err) {
            showPaymentError(err.message);
        }
    });
});
</script>
@endpush
@endsection
