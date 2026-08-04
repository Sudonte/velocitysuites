@extends('layouts.app')

@section('title', 'Check-In - Receptionist')

@section('content')
<div class="container-fluid py-4">
    <x-page-header icon="fas fa-sign-in-alt" title="Check-In"
        subtitle="Assign a room and check guests in - room assignment happens here, not at booking." />

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
            <tbody id="checkInTableBody">
                @forelse($bookings as $booking)
                    <tr data-booking-row="{{ $booking->id }}">
                        <td>{{ $booking->reservation->guest->user->full_name ?? 'N/A' }}</td>
                        <td>
                            @if($booking->rooms->isNotEmpty())
                                {{ $booking->rooms->pluck('room_number')->implode(', ') }} ({{ $booking->roomType->name ?? '' }})
                            @else
                                <span class="text-muted">Not yet assigned</span>
                                @if($booking->rooms_requested > 1)
                                    <span class="badge bg-secondary">needs {{ $booking->rooms_requested }}</span>
                                @endif
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
                                <button type="button" class="btn btn-sm btn-success btn-open-checkin" data-booking-id="{{ $booking->id }}">
                                    <i class="fas fa-sign-in-alt"></i> Check In
                                </button>
                            @else
                                <a href="{{ route('receptionist.amenities.create', $booking->reservation) }}" class="btn btn-sm btn-outline-primary">
                                    <i class="fas fa-spa"></i> Add Amenity
                                </a>
                            @endif
                        </td>
                    </tr>
                @empty
                    <tr id="noBookingsRow">
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

<!-- Check-In Modal (AJAX-loaded: full booking details + room assignment, one step) -->
<div class="modal fade" id="checkInModal" tabindex="-1" data-bs-backdrop="static">
    <div class="modal-dialog modal-dialog-scrollable">
        <div class="modal-content" id="checkInModalContent">
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

    document.getElementById('checkInTableBody').addEventListener('click', async function (e) {
        const btn = e.target.closest('.btn-open-checkin');
        if (!btn) return;

        activeBookingId = btn.dataset.bookingId;
        content.innerHTML = '<div class="modal-body text-center py-5"><i class="fas fa-spinner fa-spin fa-2x"></i></div>';
        modal.show();

        try {
            const response = await fetch(buildUrl(urls.panel, activeBookingId), {
                headers: { 'X-Requested-With': 'XMLHttpRequest' },
            });
            if (!response.ok) {
                const data = await response.json().catch(() => ({}));
                throw new Error(data.message || 'Failed to load check-in panel.');
            }
            content.innerHTML = await response.text();
        } catch (err) {
            content.innerHTML = '<div class="modal-body"><div class="alert alert-danger mb-0">' + err.message + '</div></div>';
        }
    });

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

    content.addEventListener('submit', async function (e) {
        if (e.target.id !== 'checkInForm') return;
        e.preventDefault();

        const roomIds = Array.from(e.target.querySelectorAll('select[name="room_ids[]"]')).map(s => s.value);
        try {
            const response = await fetch(buildUrl(urls.store, activeBookingId), {
                method: 'POST',
                headers: {
                    'X-CSRF-TOKEN': csrfToken,
                    'Accept': 'application/json',
                    'Content-Type': 'application/json',
                },
                body: JSON.stringify({ room_ids: roomIds }),
            });
            const data = await response.json().catch(() => ({}));
            if (!response.ok) throw new Error(data.message || 'Something went wrong.');

            modal.hide();
            const row = document.querySelector('tr[data-booking-row="' + activeBookingId + '"]');
            if (row) row.remove();
            const tbody = document.getElementById('checkInTableBody');
            if (tbody && !tbody.querySelector('tr')) {
                tbody.innerHTML = '<tr id="noBookingsRow"><td colspan="6" class="text-center text-muted py-4">No expected check-ins.</td></tr>';
            }
            alert(data.message);
            activeBookingId = null;
        } catch (err) {
            showError(err.message);
        }
    });
});
</script>
@endpush
@endsection
