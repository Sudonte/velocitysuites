{{-- Reservation/Booking Details modal shell + behavior, shared by the
     Reservations and Bookings index pages. The modal body is fetched via
     AJAX (reservations.detail) and every in-modal action posts back and
     replaces that same body, so the whole workflow stays in one popup
     without a page reload. --}}
<div class="modal fade" id="reservationDetailModal" tabindex="-1" data-bs-backdrop="static">
    <div class="modal-dialog modal-xl modal-dialog-scrollable modal-dialog-centered">
        <div class="modal-content" id="reservationDetailModalContent">
            <!-- Injected via AJAX -->
        </div>
    </div>
</div>

@push('scripts')
<script>
document.addEventListener('DOMContentLoaded', function () {
    const csrfToken = document.querySelector('meta[name="csrf-token"]').content;
    const modalEl = document.getElementById('reservationDetailModal');
    const modalContent = document.getElementById('reservationDetailModalContent');
    const modal = new bootstrap.Modal(modalEl);

    let activeReservationId = null;
    let listNeedsRefresh = false;

    const detailUrlTemplate = @json(route('receptionist.reservations.detail', ['reservation' => '__ID__']));
    const confirmUrlTemplate = @json(route('receptionist.reservations.confirm', ['reservation' => '__ID__']));
    const rejectUrlTemplate = @json(route('receptionist.reservations.reject', ['reservation' => '__ID__']));
    const convertUrlTemplate = @json(route('receptionist.reservations.convert.store', ['reservation' => '__ID__']));
    const assignRoomUrlTemplate = @json(route('receptionist.reservations.assign-room', ['reservation' => '__ID__']));
    const checkInUrlTemplate = @json(route('receptionist.check-in.store', ['reservation' => '__ID__']));
    const amenityStoreUrlTemplate = @json(route('receptionist.amenities.store', ['reservation' => '__ID__']));

    function buildUrl(template, id) {
        return template.replace('__ID__', id);
    }

    async function fetchHtml(url) {
        const response = await fetch(url, { headers: { 'X-CSRF-TOKEN': csrfToken } });
        if (!response.ok) {
            const data = await response.json().catch(() => ({}));
            throw new Error(data.message || 'Something went wrong.');
        }
        return response.text();
    }

    async function postJson(url, payload) {
        const response = await fetch(url, {
            method: 'POST',
            headers: {
                'X-CSRF-TOKEN': csrfToken,
                'Content-Type': 'application/json',
                'Accept': 'application/json',
            },
            body: JSON.stringify(payload || {}),
        });
        const data = await response.json().catch(() => ({}));
        if (!response.ok) {
            throw new Error(data.message || 'Something went wrong.');
        }
        return data;
    }

    function showDetailError(message) {
        const wrap = modalContent.querySelector('#detailErrorAlert-wrap');
        const box = modalContent.querySelector('#detailErrorAlert span');
        if (wrap && box) {
            box.textContent = message;
            wrap.classList.remove('d-none');
            wrap.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
        } else {
            alert(message);
        }
    }

    async function openDetail(reservationId) {
        activeReservationId = reservationId;
        modalContent.innerHTML = '<div class="modal-body text-center py-5"><div class="spinner-border text-danger" role="status"></div></div>';
        modal.show();
        try {
            const html = await fetchHtml(buildUrl(detailUrlTemplate, reservationId));
            modalContent.innerHTML = html;
        } catch (err) {
            modalContent.innerHTML = '<div class="modal-body text-center py-5 text-danger">' + err.message + '</div>';
        }
    }

    async function refreshDetail(html, message) {
        modalContent.innerHTML = html;
        listNeedsRefresh = true;
        if (message) {
            // Surface success inline instead of an alert() so the modal
            // stays the single source of truth for what just happened.
            const body = modalContent.querySelector('.modal-body');
            if (body) {
                const banner = document.createElement('div');
                banner.className = 'alert alert-success d-flex align-items-center gap-2 mb-4';
                banner.innerHTML = '<i class="fas fa-check-circle"></i> <span>' + message + '</span>';
                body.prepend(banner);
            }
        }
    }

    // Open the modal from any list row
    document.addEventListener('click', function (e) {
        const btn = e.target.closest('.btn-open-detail');
        if (!btn) return;
        openDetail(btn.dataset.reservationId);
    });

    // The list behind the modal (status, action button, which module a
    // row belongs to) can go stale after a mutating action - reload once
    // the receptionist closes the modal so it reflects the new state,
    // rather than reloading immediately and losing their place mid-review.
    modalEl.addEventListener('hidden.bs.modal', function () {
        modalContent.innerHTML = '';
        activeReservationId = null;
        if (listNeedsRefresh) {
            window.location.reload();
        }
    });

    modalContent.addEventListener('click', function (e) {
        // Confirm Reservation
        if (e.target.closest('#confirmReservationBtn')) {
            if (!confirm('Confirm this reservation?')) return;
            postJson(buildUrl(confirmUrlTemplate, activeReservationId))
                .then(data => refreshDetail(data.html, data.message))
                .catch(err => showDetailError(err.message));
            return;
        }

        // Show / cancel the reject reason form
        if (e.target.closest('#showRejectFormBtn')) {
            modalContent.querySelector('#rejectReservationForm').classList.remove('d-none');
            return;
        }
        if (e.target.closest('#cancelRejectFormBtn')) {
            modalContent.querySelector('#rejectReservationForm').classList.add('d-none');
            return;
        }

        // Show / hide the add-amenity form
        if (e.target.closest('#showAddAmenityFormBtn')) {
            modalContent.querySelector('#addAmenityForm').classList.toggle('d-none');
            return;
        }

        // Check In Guest - the booking leaves this list entirely once
        // checked in, so close the modal (triggering the list reload
        // below) instead of refreshing the modal body in place.
        if (e.target.closest('#checkInGuestBtn')) {
            if (!confirm('Check in this guest now?')) return;
            postJson(buildUrl(checkInUrlTemplate, activeReservationId))
                .then(data => {
                    listNeedsRefresh = true;
                    alert(data.message);
                    modal.hide();
                })
                .catch(err => showDetailError(err.message));
            return;
        }
    });

    // Reject Reservation
    modalContent.addEventListener('submit', function (e) {
        if (e.target.id !== 'rejectReservationForm') return;
        e.preventDefault();
        const payload = Object.fromEntries(new FormData(e.target).entries());
        postJson(buildUrl(rejectUrlTemplate, activeReservationId), payload)
            .then(data => refreshDetail(data.html, data.message))
            .catch(err => showDetailError(err.message));
    });

    // Convert to Booking
    modalContent.addEventListener('submit', function (e) {
        if (e.target.id !== 'convertToBookingForm') return;
        e.preventDefault();
        const payload = Object.fromEntries(new FormData(e.target).entries());
        if (payload.payment_method === 'gcash' && !payload.reference_number) {
            showDetailError('Reference number is required for GCash payments.');
            return;
        }
        postJson(buildUrl(convertUrlTemplate, activeReservationId), payload)
            .then(data => refreshDetail(data.html, data.message))
            .catch(err => showDetailError(err.message));
    });

    // Assign Room
    modalContent.addEventListener('submit', function (e) {
        if (e.target.id !== 'assignRoomForm') return;
        e.preventDefault();
        const payload = Object.fromEntries(new FormData(e.target).entries());
        postJson(buildUrl(assignRoomUrlTemplate, activeReservationId), payload)
            .then(data => refreshDetail(data.html, data.message))
            .catch(err => showDetailError(err.message));
    });

    // Add Amenity
    modalContent.addEventListener('submit', function (e) {
        if (e.target.id !== 'addAmenityForm') return;
        e.preventDefault();
        const payload = Object.fromEntries(new FormData(e.target).entries());
        postJson(buildUrl(amenityStoreUrlTemplate, activeReservationId), payload)
            .then(data => refreshDetail(data.html, data.message))
            .catch(err => showDetailError(err.message));
    });
});
</script>
@endpush
