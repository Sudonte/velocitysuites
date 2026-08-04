<div class="modal-header modal-header-brand">
    <h5 class="modal-title"><i class="fas fa-sign-in-alt"></i> Check In Guest</h5>
    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
</div>
<div class="modal-body" data-booking-id="{{ $booking->id }}">
    <div class="alert alert-danger d-none" id="checkInErrorAlert"></div>

    <div class="row mb-3">
        <div class="col-md-6">
            <strong>Guest:</strong> {{ $booking->reservation->guest->user->full_name ?? 'N/A' }}<br>
            <strong>Booking:</strong> #{{ $booking->id }}<br>
            <strong>Room Type:</strong> {{ $booking->roomType->name ?? 'N/A' }}
        </div>
        <div class="col-md-6 text-md-end">
            <strong>Check-In:</strong> {{ $booking->check_in->format('M d, Y') }}<br>
            <strong>Check-Out:</strong> {{ $booking->check_out->format('M d, Y') }}<br>
            <strong>Guests:</strong> {{ $booking->number_of_guests }}
        </div>
    </div>

    <h6><i class="fas fa-door-open"></i> Assign Room</h6>
    @if($assignableRooms->isEmpty())
        <div class="alert alert-warning mb-0">
            <i class="fas fa-exclamation-triangle"></i> No {{ $booking->roomType->name ?? '' }} room is currently free for these dates.
        </div>
    @else
        <form id="checkInForm">
            <select name="room_id" class="form-select" required>
                <option value="">-- Select an available room --</option>
                @foreach($assignableRooms as $room)
                    <option value="{{ $room->id }}">Room {{ $room->room_number }}</option>
                @endforeach
            </select>
            <p class="text-muted small mt-2 mb-0">
                <i class="fas fa-info-circle"></i> Only {{ $booking->roomType->name ?? '' }} rooms free for the full stay are listed.
            </p>
        </form>
    @endif
</div>
<div class="modal-footer">
    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
    @if($assignableRooms->isNotEmpty())
        <button type="submit" form="checkInForm" class="btn btn-success">
            <i class="fas fa-check"></i> Complete Check-In
        </button>
    @endif
</div>
