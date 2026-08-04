<div class="modal-header modal-header-brand">
    <h5 class="modal-title"><i class="fas fa-sign-in-alt"></i> Check In Guest</h5>
    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
</div>
<div class="modal-body" data-booking-id="{{ $booking->id }}" data-rooms-requested="{{ $booking->rooms_requested }}">
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

    <h6>
        <i class="fas fa-door-open"></i> Assign Room{{ $booking->rooms_requested > 1 ? 's' : '' }}
        @if($booking->rooms_requested > 1)
            <span class="badge bg-secondary">{{ $booking->rooms_requested }} needed</span>
        @endif
    </h6>
    @if($assignableRooms->count() < $booking->rooms_requested)
        <div class="alert alert-warning mb-0">
            <i class="fas fa-exclamation-triangle"></i>
            This booking needs {{ $booking->rooms_requested }} {{ $booking->roomType->name ?? '' }} room(s), but only {{ $assignableRooms->count() }} {{ $assignableRooms->count() === 1 ? 'is' : 'are' }} currently free for these dates.
        </div>
    @else
        <form id="checkInForm">
            <div id="roomSelectRows">
                @for ($i = 0; $i < $booking->rooms_requested; $i++)
                    <div class="room-select-row mb-2">
                        <select name="room_ids[]" class="form-select room-select" required>
                            <option value="">-- Select an available room --</option>
                            @foreach($assignableRooms as $room)
                                <option value="{{ $room->id }}">Room {{ $room->room_number }}</option>
                            @endforeach
                        </select>
                    </div>
                @endfor
            </div>
            <p class="text-muted small mt-2 mb-0">
                <i class="fas fa-info-circle"></i> Only {{ $booking->roomType->name ?? '' }} rooms free for the full stay are listed. Each room can only be picked once.
            </p>
        </form>
    @endif
</div>
<div class="modal-footer">
    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
    @if($assignableRooms->count() >= $booking->rooms_requested)
        <button type="submit" form="checkInForm" class="btn btn-success">
            <i class="fas fa-check"></i> Complete Check-In
        </button>
    @endif
</div>
