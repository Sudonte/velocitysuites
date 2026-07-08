@extends('layouts.app')

@section('title', 'Booking Requests - Receptionist')

@section('content')
<div class="container-fluid py-4">
    <x-page-header icon="fas fa-inbox" title="Booking Requests"
        subtitle="Assign a room to each request, then confirm. The guest only chose a room type - the room number is yours to allocate." />

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

    <x-card title="Pending Requests" icon="fas fa-list" bodyClass="table-responsive">
        <table class="table table-hover mb-0 align-middle">
            <thead>
                <tr>
                    <th>Guest</th>
                    <th>Requested Type</th>
                    <th>Dates</th>
                    <th>Guests</th>
                    <th style="min-width: 220px;">Assign Room</th>
                    <th>Actions</th>
                </tr>
            </thead>
            <tbody>
                @forelse($reservations as $reservation)
                    @php $rooms = $assignableRooms[$reservation->id] ?? collect(); @endphp
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
                            @if($rooms->isEmpty())
                                <span class="text-danger"><i class="fas fa-exclamation-triangle"></i> No {{ $reservation->roomType->name ?? '' }} room free for these dates</span>
                            @else
                                <select name="room_id" form="confirm-form-{{ $reservation->id }}" class="form-select form-select-sm" required>
                                    <option value="">-- Select room --</option>
                                    @foreach($rooms as $room)
                                        <option value="{{ $room->id }}">
                                            Room {{ $room->room_number }} — {{ $room->room_name }} ({{ $room->room_capacity }} guests, ₱{{ number_format($room->room_rate, 2) }}{{ $room->has_rate_override ? ' *' : '' }})
                                        </option>
                                    @endforeach
                                </select>
                            @endif
                        </td>
                        <td class="text-nowrap">
                            <form id="confirm-form-{{ $reservation->id }}" action="{{ route('receptionist.reservations.confirm', $reservation) }}" method="POST" class="d-inline">
                                @csrf
                                <button type="submit" class="btn btn-sm btn-success" {{ $rooms->isEmpty() ? 'disabled' : '' }}
                                        onclick="return confirm('Assign the selected room and confirm this booking?')">
                                    <i class="fas fa-check"></i> Confirm
                                </button>
                            </form>
                            <button type="button" class="btn btn-sm btn-outline-danger" data-bs-toggle="modal" data-bs-target="#rejectModal{{ $reservation->id }}">
                                <i class="fas fa-times"></i> Reject
                            </button>
                        </td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="6">
                            <x-empty-state icon="fas fa-inbox" message="No pending booking requests." />
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

<!-- Reject Modals -->
@foreach($reservations as $reservation)
    <div class="modal fade" id="rejectModal{{ $reservation->id }}" tabindex="-1">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header modal-header-brand">
                    <h5 class="modal-title"><i class="fas fa-times-circle"></i> Reject Booking Request</h5>
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
                                      placeholder="This will be sent to the guest."></textarea>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                        <button type="submit" class="btn btn-danger">Reject Request</button>
                    </div>
                </form>
            </div>
        </div>
    </div>
@endforeach
@endsection
