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
            <tbody>
                @forelse($reservations as $reservation)
                    <tr>
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
                            <button type="button" class="btn btn-sm btn-outline-secondary" data-bs-toggle="modal"
                                    data-bs-target="#detailsModal" data-details-url="{{ route('receptionist.reservations.details', $reservation) }}">
                                <i class="fas fa-eye"></i> View
                            </button>

                            @if($tab === 'pending_review')
                                <form action="{{ route('receptionist.reservations.accept', $reservation) }}" method="POST" class="d-inline">
                                    @csrf
                                    <button type="submit" class="btn btn-sm btn-success" onclick="return confirm('Accept this reservation request?')">
                                        <i class="fas fa-check"></i> Accept
                                    </button>
                                </form>
                            @else
                                <form action="{{ route('receptionist.reservations.convert', $reservation) }}" method="POST" class="d-inline">
                                    @csrf
                                    <button type="submit" class="btn btn-sm btn-success" {{ $available <= 0 ? 'disabled' : '' }}
                                            onclick="return confirm('Convert this reservation into a confirmed booking?')">
                                        <i class="fas fa-calendar-check"></i> Convert to Booking
                                    </button>
                                </form>
                            @endif

                            <button type="button" class="btn btn-sm btn-outline-danger" data-bs-toggle="modal" data-bs-target="#rejectModal{{ $reservation->id }}">
                                <i class="fas fa-times"></i> Reject
                            </button>
                        </td>
                    </tr>
                @empty
                    <tr>
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

<!-- Reject Modals -->
@foreach($reservations as $reservation)
    <div class="modal fade" id="rejectModal{{ $reservation->id }}" tabindex="-1">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header modal-header-brand">
                    <h5 class="modal-title"><i class="fas fa-times-circle"></i> Reject Reservation</h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                </div>
                <form action="{{ route('receptionist.reservations.reject', $reservation) }}" method="POST">
                    @csrf
                    <div class="modal-body">
                        <p class="mb-2">
                            Rejecting <strong>{{ $reservation->guest->user->full_name ?? 'N/A' }}</strong>'s request for a
                            <strong>{{ $reservation->roomType->name ?? 'N/A' }}</strong> room
                            ({{ $reservation->check_in->format('M d') }} &ndash; {{ $reservation->check_out->format('M d, Y') }}).
                        </p>
                        <div class="mb-2">
                            <label class="form-label">Reason <span class="text-danger">*</span></label>
                            <textarea name="reason" class="form-control" rows="3" maxlength="500" required
                                      placeholder="This will be sent to the guest.">{{ ($availableCounts[$reservation->id] ?? 1) <= 0 && $tab === 'ready_for_booking' ? 'The ' . ($reservation->roomType->name ?? '') . ' room type is fully booked for your requested dates.' : '' }}</textarea>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                        <button type="submit" class="btn btn-danger">Reject Reservation</button>
                    </div>
                </form>
            </div>
        </div>
    </div>
@endforeach

@push('scripts')
<script>
document.getElementById('detailsModal').addEventListener('show.bs.modal', function (event) {
    const button = event.relatedTarget;
    const url = button.getAttribute('data-details-url');
    const body = document.getElementById('detailsModalBody');
    body.innerHTML = '<div class="text-center py-5"><i class="fas fa-spinner fa-spin fa-2x"></i></div>';
    fetch(url, { headers: { 'X-Requested-With': 'XMLHttpRequest' } })
        .then(r => r.text())
        .then(html => { body.innerHTML = html; })
        .catch(() => { body.innerHTML = '<div class="alert alert-danger">Failed to load details.</div>'; });
});
</script>
@endpush
@endsection
