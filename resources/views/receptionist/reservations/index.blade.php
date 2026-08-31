@extends('layouts.app')

@section('title', 'Reservations - Receptionist')

@section('content')
<div class="container-fluid py-4">
    <x-page-header icon="fas fa-inbox" title="Reservations"
        subtitle="Review guest requests, then convert eligible ones into confirmed bookings." />

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

    <ul class="nav nav-tabs mb-3 flex-nowrap overflow-auto">
        <li class="nav-item">
            <a class="nav-link text-nowrap {{ $tab === 'pending_review' ? 'active' : '' }}" href="{{ route('receptionist.reservations.index', ['tab' => 'pending_review']) }}">
                To Be Confirmed Reservations <span class="badge bg-warning text-dark">{{ $pendingCount }}</span>
            </a>
        </li>
        <li class="nav-item">
            <a class="nav-link text-nowrap {{ $tab === 'ready_for_booking' ? 'active' : '' }}" href="{{ route('receptionist.reservations.index', ['tab' => 'ready_for_booking']) }}">
                To Be Converted to Booking <span class="badge bg-info">{{ $readyCount }}</span>
            </a>
        </li>
        <li class="nav-item">
            <a class="nav-link text-nowrap {{ $tab === 'verified' ? 'active' : '' }}" href="{{ route('receptionist.reservations.index', ['tab' => 'verified']) }}">
                Complete Reservation List <span class="badge bg-success">{{ $verifiedCount }}</span>
            </a>
        </li>
    </ul>

    <x-card :title="match(true) { $tab === 'pending_review' => 'To Be Confirmed Reservations', $tab === 'ready_for_booking' => 'To Be Converted to Booking', default => 'Verified Reservations' }" icon="fas fa-list" bodyClass="monitoring-table-wrap">
        <div class="d-md-none monitoring-card-list" id="reservationsCardList">
            @forelse($reservations as $reservation)
                <div class="monitoring-item-card" data-reservation-row="{{ $reservation->id }}">
                    <div class="d-flex justify-content-between align-items-start gap-2 mb-2">
                        <div class="d-flex align-items-center gap-2">
                            <div class="monitoring-avatar monitoring-avatar-sm">
                                {{ strtoupper(substr($reservation->guest->user->full_name ?? '?', 0, 1)) }}
                            </div>
                            <div>
                                <div class="fw-bold">{{ $reservation->guest->user->full_name ?? 'N/A' }}</div>
                                <small class="text-muted">#{{ $reservation->id }}</small>
                            </div>
                        </div>
                        <span class="badge badge-brand">{{ $reservation->roomType->name ?? 'N/A' }}{{ $reservation->rooms_requested > 1 ? ' ×'.$reservation->rooms_requested : '' }}</span>
                    </div>
                    <div class="monitoring-item-row">
                        <span class="text-muted">Dates</span>
                        <span>{{ $reservation->check_in->format('M d') }}&ndash;{{ $reservation->check_out->format('M d, Y') }} ({{ $reservation->number_of_nights }}n)</span>
                    </div>
                    <div class="monitoring-item-row">
                        <span class="text-muted">Guests</span>
                        <span>{{ $reservation->number_of_guests }}</span>
                    </div>
                    <div class="monitoring-item-row">
                        <span class="text-muted">Payment</span>
                        <span>{{ $reservation->payment_preference === 'pay_now' ? 'Pay Now' : 'Pay Later' }} &middot; {{ $reservation->payment_method === 'gcash' ? 'GCash' : 'Cash' }}</span>
                    </div>
                    @if($tab === 'ready_for_booking')
                        @php $available = $availableCounts[$reservation->id] ?? 0; @endphp
                        <div class="monitoring-item-row">
                            <span class="text-muted">Availability</span>
                            <span>
                                @if($available >= $reservation->rooms_requested)
                                    <span class="text-success"><i class="fas fa-check-circle"></i> {{ $available }} free</span>
                                @else
                                    <span class="text-danger"><i class="fas fa-exclamation-triangle"></i> Only {{ $available }} free</span>
                                @endif
                            </span>
                        </div>
                    @endif
                    <div class="d-flex flex-wrap gap-2 mt-2">
                        @if($tab !== 'verified')
                            <button type="button" class="btn btn-sm btn-primary flex-fill" data-bs-toggle="modal"
                                    data-bs-target="#detailsModal" data-details-url="{{ route('receptionist.reservations.details', $reservation) }}">
                                <i class="fas fa-eye"></i> View / Manage
                            </button>
                            <form action="{{ route('receptionist.reservations.verify', $reservation) }}" method="POST" class="flex-fill">
                                @csrf
                                @method('PUT')
                                <button type="submit" class="btn btn-sm btn-success w-100" onclick="return confirm('Verify this reservation?')">
                                    <i class="fas fa-check"></i> Verify
                                </button>
                            </form>
                        @else
                            <span class="badge bg-success align-self-center">Verified</span>
                        @endif
                    </div>
                </div>
            @empty
                <x-empty-state icon="fas fa-inbox" :message="match(true) { $tab === 'pending_review' => 'No reservations awaiting review.', $tab === 'ready_for_booking' => 'No reservations ready for booking conversion.', default => 'No verified reservations yet.' }" />
            @endforelse
        </div>

        <div class="d-none d-md-block table-responsive">
        <table class="table table-hover mb-0 align-middle monitoring-table">
            <thead>
                <tr>
                    <th>Reservation #</th>
                    <th>Guest</th>
                    <th>Requested Type</th>
                    <th>Dates</th>
                    <th class="d-none d-lg-table-cell">Guests</th>
                    <th>Payment</th>
                    @if($tab === 'ready_for_booking')
                        <th class="d-none d-md-table-cell">Availability</th>
                    @endif
                    <th class="text-nowrap">Actions</th>
                </tr>
            </thead>
            <tbody id="reservationsTableBody">
                @forelse($reservations as $reservation)
                    <tr data-reservation-row="{{ $reservation->id }}">
                        <td class="fw-bold">#{{ $reservation->id }}</td>
                        <td>
                            <div class="d-flex align-items-center gap-2">
                                <div class="monitoring-avatar monitoring-avatar-sm">
                                    {{ strtoupper(substr($reservation->guest->user->full_name ?? '?', 0, 1)) }}
                                </div>
                                <div>
                                    {{ $reservation->guest->user->full_name ?? 'N/A' }}
                                    <small class="d-block text-muted">{{ $reservation->guest->user->email ?? '' }}</small>
                                </div>
                            </div>
                        </td>
                        <td>
                            <span class="badge badge-brand">{{ $reservation->roomType->name ?? 'N/A' }}</span>
                            @if($reservation->rooms_requested > 1)
                                <span class="badge bg-secondary">&times;{{ $reservation->rooms_requested }} rooms</span>
                            @endif
                        </td>
                        <td>
                            {{ $reservation->check_in->format('M d') }} &ndash; {{ $reservation->check_out->format('M d, Y') }}<br>
                            <small class="text-muted">{{ $reservation->number_of_nights }} night{{ $reservation->number_of_nights === 1 ? '' : 's' }}</small>
                        </td>
                        <td class="d-none d-lg-table-cell">{{ $reservation->number_of_guests }}</td>
                        <td>
                            {{ $reservation->payment_preference === 'pay_now' ? 'Pay Now' : 'Pay Later' }}<br>
                            <small class="text-muted">{{ $reservation->payment_method === 'gcash' ? 'GCash' : 'Cash' }}</small>
                        </td>
                        @if($tab === 'ready_for_booking')
                            @php $available = $availableCounts[$reservation->id] ?? 0; @endphp
                            <td class="d-none d-md-table-cell">
                                @if($available >= $reservation->rooms_requested)
                                    <span class="text-success"><i class="fas fa-check-circle"></i> {{ $available }} room(s) free</span>
                                @else
                                    <span class="text-danger"><i class="fas fa-exclamation-triangle"></i> Only {{ $available }} free (needs {{ $reservation->rooms_requested }})</span>
                                @endif
                            </td>
                        @endif
                        <td class="text-nowrap">
                            @if($tab !== 'verified')
                                <button type="button" class="btn btn-sm btn-primary" data-bs-toggle="modal"
                                        data-bs-target="#detailsModal" data-details-url="{{ route('receptionist.reservations.details', $reservation) }}">
                                    <i class="fas fa-eye"></i> View / Manage
                                </button>
                                <form action="{{ route('receptionist.reservations.verify', $reservation) }}" method="POST" class="d-inline">
                                    @csrf
                                    @method('PUT')
                                    <button type="submit" class="btn btn-sm btn-success" onclick="return confirm('Verify this reservation?')">
                                        <i class="fas fa-check"></i> Verify
                                    </button>
                                </form>
                            @else
                                <span class="badge bg-success">Verified</span>
                            @endif
                        </td>
                    </tr>
                @empty
                    <tr id="noReservationsRow">
                        <td colspan="8">
                            <x-empty-state icon="fas fa-inbox" :message="match(true) { $tab === 'pending_review' => 'No reservations awaiting review.', $tab === 'ready_for_booking' => 'No reservations ready for booking conversion.', default => 'No verified reservations yet.' }" />
                        </td>
                    </tr>
                @endforelse
            </tbody>
        </table>
        </div>
        <x-slot:footer>
            {{ $reservations->links() }}
        </x-slot:footer>
    </x-card>
</div>

<!-- View Details Modal (AJAX-loaded popup) -->
<div class="modal fade" id="detailsModal" tabindex="-1">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <div class="modal-header modal-header-brand">
                <h5 class="modal-title"><i class="fas fa-info-circle"></i> Reservation Details</h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body" id="detailsModalBody">
                <div class="text-center py-5"><i class="fas fa-spinner fa-spin fa-2x"></i></div>
            </div>
        </div>
    </div>
</div>

@push('scripts')
<script>
// Tiny reason-prompt for the payment-level Reject button rendered inside
// the AJAX-loaded details partial (see partials/details.blade.php) - a
// plain form submit (full page reload, same as the existing "Verify
// Booking" button), just with a prompt() gate to collect the required
// reason first and stash it in the form's hidden `reason` input.
window.preparePaymentReject = function (form) {
    const reason = window.prompt('Reason for rejecting this payment (max 500 characters):');
    if (!reason || !reason.trim()) {
        return false;
    }
    form.querySelector('input[name="reason"]').value = reason.trim().slice(0, 500);
    return true;
};

document.addEventListener('DOMContentLoaded', function () {
    const csrfToken = document.querySelector('meta[name="csrf-token"]').content;
    const detailsModalEl = document.getElementById('detailsModal');
    const detailsModal = bootstrap.Modal.getOrCreateInstance(detailsModalEl);
    const body = document.getElementById('detailsModalBody');

    let activeReservationId = null;

    const urls = {
        accept: @json(route('receptionist.reservations.accept', ['reservation' => '__ID__'])),
        reject: @json(route('receptionist.reservations.reject', ['reservation' => '__ID__'])),
        convert: @json(route('receptionist.reservations.convert', ['reservation' => '__ID__'])),
        confirmCash: @json(route('receptionist.reservations.confirm-cash-payment', ['reservation' => '__ID__'])),
    };

    function buildUrl(template, id) {
        return template.replace('__ID__', id);
    }

    async function postJson(url, payload) {
        const response = await fetch(url, {
            method: 'POST',
            headers: {
                'X-CSRF-TOKEN': csrfToken,
                'Accept': 'application/json',
                'Content-Type': 'application/json',
            },
            body: JSON.stringify(payload || {}),
        });
        const data = await response.json().catch(() => ({}));
        if (!response.ok) {
            throw new Error(data.message || 'Something went wrong.');
        }
        return data;
    }

    function showError(message) {
        const alertBox = body.querySelector('#detailsActionError');
        if (alertBox) {
            alertBox.textContent = message;
            alertBox.classList.remove('d-none');
        } else {
            alert(message);
        }
    }

    function removeRowAndClose(message) {
        detailsModal.hide();
        // A reservation shows as both a table row (desktop) and a card
        // (mobile, below md) - both carry the same data-reservation-row
        // marker, so remove whichever of the two is actually present.
        document.querySelectorAll('[data-reservation-row="' + activeReservationId + '"]').forEach(function (el) {
            el.remove();
        });
        const tbody = document.getElementById('reservationsTableBody');
        if (tbody && !tbody.querySelector('tr')) {
            const colspan = {{ $tab === 'ready_for_booking' ? 8 : 7 }};
            tbody.innerHTML = '<tr id="noReservationsRow"><td colspan="' + colspan + '" class="text-center text-muted py-4">Nothing here right now.</td></tr>';
        }
        const cardList = document.getElementById('reservationsCardList');
        if (cardList && !cardList.querySelector('[data-reservation-row]')) {
            cardList.innerHTML = '<div class="text-center text-muted py-4">Nothing here right now.</div>';
        }
        if (message) alert(message);
    }

    detailsModalEl.addEventListener('show.bs.modal', function (event) {
        const button = event.relatedTarget;
        const url = button.getAttribute('data-details-url');
        activeReservationId = button.closest('[data-reservation-row]').getAttribute('data-reservation-row');
        body.innerHTML = '<div class="text-center py-5"><i class="fas fa-spinner fa-spin fa-2x"></i></div>';
        fetch(url, { headers: { 'X-Requested-With': 'XMLHttpRequest' } })
            .then(r => r.text())
            .then(html => { body.innerHTML = html; })
            .catch(() => { body.innerHTML = '<div class="alert alert-danger">Failed to load details.</div>'; });
    });

    body.addEventListener('click', async function (e) {
        // Reveal the inline reject form in place of the main action row
        if (e.target.closest('#detailsShowRejectBtn')) {
            body.querySelector('#detailsMainActions').classList.add('d-none');
            body.querySelector('#detailsRejectForm').classList.remove('d-none');
            return;
        }
        if (e.target.closest('#detailsCancelRejectBtn')) {
            body.querySelector('#detailsRejectForm').classList.add('d-none');
            body.querySelector('#detailsMainActions').classList.remove('d-none');
            return;
        }

        // Reveal the inline "Confirm Cash Payment" amount form, same
        // toggle pattern as the Reject form above.
        if (e.target.closest('#detailsShowCashBtn')) {
            body.querySelector('#detailsMainActions').classList.add('d-none');
            body.querySelector('#detailsCashPaymentForm').classList.remove('d-none');
            return;
        }
        if (e.target.closest('#detailsCancelCashBtn')) {
            body.querySelector('#detailsCashPaymentForm').classList.add('d-none');
            body.querySelector('#detailsMainActions').classList.remove('d-none');
            return;
        }
        if (e.target.closest('#detailsSubmitCashBtn')) {
            const amountInput = body.querySelector('#detailsCashAmount');
            const amount = parseFloat(amountInput.value);
            if (!amount || amount <= 0) {
                showError('Enter a valid amount greater than ₱0.');
                return;
            }
            try {
                const data = await postJson(buildUrl(urls.confirmCash, activeReservationId), { amount_received: amount });
                removeRowAndClose(data.message);
            } catch (err) {
                showError(err.message);
            }
            return;
        }

        if (e.target.closest('#detailsAcceptBtn')) {
            if (!confirm('Accept this reservation request?')) return;
            try {
                const data = await postJson(buildUrl(urls.accept, activeReservationId));
                removeRowAndClose(data.message);
            } catch (err) {
                showError(err.message);
            }
            return;
        }

        if (e.target.closest('#detailsConvertBtn')) {
            if (!confirm('Convert this reservation into a confirmed booking?')) return;
            try {
                const data = await postJson(buildUrl(urls.convert, activeReservationId));
                removeRowAndClose(data.message);
            } catch (err) {
                showError(err.message);
            }
            return;
        }

        if (e.target.closest('#detailsSubmitRejectBtn')) {
            const reason = body.querySelector('#detailsRejectReason').value.trim();
            if (!reason) {
                showError('A reason is required.');
                return;
            }
            try {
                const data = await postJson(buildUrl(urls.reject, activeReservationId), { reason: reason });
                removeRowAndClose(data.message);
            } catch (err) {
                showError(err.message);
            }
            return;
        }
    });
});
</script>
@endpush
@endsection
