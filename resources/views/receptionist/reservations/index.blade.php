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

    <ul class="nav nav-tabs mb-3">
        <li class="nav-item">
            <a class="nav-link {{ $tab === 'pending_review' ? 'active' : '' }}" href="{{ route('receptionist.reservations.index', ['tab' => 'pending_review']) }}">
                To Be Confirmed Reservations <span class="badge bg-warning text-dark">{{ $pendingCount }}</span>
            </a>
        </li>
        <li class="nav-item">
            <a class="nav-link {{ $tab === 'ready_for_booking' ? 'active' : '' }}" href="{{ route('receptionist.reservations.index', ['tab' => 'ready_for_booking']) }}">
                To Be Converted to Booking <span class="badge bg-info">{{ $readyCount }}</span>
            </a>
        </li>
    </ul>

    <x-card :title="$tab === 'pending_review' ? 'To Be Confirmed Reservations' : 'To Be Converted to Booking'" icon="fas fa-list" bodyClass="table-responsive">
        <table class="table table-hover mb-0 align-middle">
            <thead>
                <tr>
                    <th>Guest</th>
                    <th>Requested Type</th>
                    <th>Dates</th>
                    <th>Guests</th>
                    <th>Payment</th>
                    @if($tab === 'ready_for_booking')
                        <th>Availability</th>
                    @endif
                    <th class="text-nowrap">Actions</th>
                </tr>
            </thead>
            <tbody id="reservationsTableBody">
                @forelse($reservations as $reservation)
                    <tr data-reservation-row="{{ $reservation->id }}">
                        <td>
                            {{ $reservation->guest->user->full_name ?? 'N/A' }}<br>
                            <small class="text-muted">{{ $reservation->guest->user->email ?? '' }}</small>
                        </td>
                        <td><span class="badge badge-brand">{{ $reservation->roomType->name ?? 'N/A' }}</span></td>
                        <td>
                            {{ $reservation->check_in->format('M d') }} &ndash; {{ $reservation->check_out->format('M d, Y') }}<br>
                            <small class="text-muted">{{ $reservation->number_of_nights }} night{{ $reservation->number_of_nights === 1 ? '' : 's' }}</small>
                        </td>
                        <td>{{ $reservation->number_of_guests }}</td>
                        <td>
                            {{ $reservation->payment_preference === 'pay_now' ? 'Pay Now' : 'Pay Later' }}<br>
                            <small class="text-muted">{{ $reservation->payment_method === 'gcash' ? 'GCash' : 'Cash' }}</small>
                        </td>
                        @if($tab === 'ready_for_booking')
                            @php $available = $availableCounts[$reservation->id] ?? 0; @endphp
                            <td>
                                @if($available > 0)
                                    <span class="text-success"><i class="fas fa-check-circle"></i> {{ $available }} room(s) free</span>
                                @else
                                    <span class="text-danger"><i class="fas fa-exclamation-triangle"></i> Fully booked</span>
                                @endif
                            </td>
                        @endif
                        <td class="text-nowrap">
                            <button type="button" class="btn btn-sm btn-primary" data-bs-toggle="modal"
                                    data-bs-target="#detailsModal" data-details-url="{{ route('receptionist.reservations.details', $reservation) }}">
                                <i class="fas fa-eye"></i> View / Manage
                            </button>
                        </td>
                    </tr>
                @empty
                    <tr id="noReservationsRow">
                        <td colspan="7">
                            <x-empty-state icon="fas fa-inbox" :message="$tab === 'pending_review' ? 'No reservations awaiting review.' : 'No reservations ready for booking conversion.'" />
                        </td>
                    </tr>
                @endforelse
            </tbody>
        </table>
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
        const row = document.querySelector('tr[data-reservation-row="' + activeReservationId + '"]');
        if (row) row.remove();
        const tbody = document.getElementById('reservationsTableBody');
        if (tbody && !tbody.querySelector('tr')) {
            const colspan = {{ $tab === 'ready_for_booking' ? 7 : 6 }};
            tbody.innerHTML = '<tr id="noReservationsRow"><td colspan="' + colspan + '" class="text-center text-muted py-4">Nothing here right now.</td></tr>';
        }
        if (message) alert(message);
    }

    detailsModalEl.addEventListener('show.bs.modal', function (event) {
        const button = event.relatedTarget;
        const url = button.getAttribute('data-details-url');
        activeReservationId = button.closest('tr').getAttribute('data-reservation-row');
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
