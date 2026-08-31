<div class="modal-header modal-header-brand">
    <h5 class="modal-title"><i class="fas fa-door-open"></i> Assign Room{{ $booking->rooms_requested > 1 ? 's' : '' }}</h5>
    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
</div>
<div class="modal-body" data-booking-id="{{ $booking->id }}" data-rooms-requested="{{ $booking->rooms_requested }}">
    <div class="alert alert-danger d-none" id="assignRoomsErrorAlert"></div>

    <div class="d-flex align-items-start gap-3 mb-3">
        @if($booking->roomType)
            <img src="{{ $booking->roomType->image_url }}" alt="{{ $booking->roomType->name }}"
                 class="rounded" style="width: 72px; height: 72px; object-fit: cover; flex-shrink: 0;">
        @endif
        <div class="row flex-grow-1">
            <div class="col-md-6">
                <strong>Guest:</strong> {{ $booking->account_guest_full_name ?? 'N/A' }}<br>
                <strong>Booking:</strong> #{{ $booking->id }}<br>
                <strong>Room Type:</strong> {{ $booking->roomType->name ?? 'N/A' }}
                @if($booking->rooms_requested > 1)
                    <span class="badge bg-secondary">&times;{{ $booking->rooms_requested }} rooms</span>
                @endif
            </div>
            <div class="col-md-6 text-md-end">
                <strong>Check-In:</strong> {{ $booking->check_in->format('M d, Y') }}<br>
                <strong>Check-Out:</strong> {{ $booking->check_out->format('M d, Y') }}<br>
                <strong>Guests:</strong> {{ $booking->number_of_guests }}
            </div>
        </div>
    </div>

    <p class="text-muted small mb-3">
        <i class="fas fa-info-circle"></i> The guest's room type and room count above were selected in the app and can't be changed here - only which specific room(s) fill that request.
    </p>

    @if($assignableRooms->count() < $booking->rooms_requested)
        <div class="alert alert-warning mb-0">
            <i class="fas fa-exclamation-triangle"></i>
            This booking needs {{ $booking->rooms_requested }} {{ $booking->roomType->name ?? '' }} room(s), but only {{ $assignableRooms->count() }} {{ $assignableRooms->count() === 1 ? 'is' : 'are' }} currently free for these dates.
        </div>
    @else
        @if(!empty($assignedRoomIds))
            <div class="alert alert-info py-2 mb-2">
                <i class="fas fa-circle-info"></i> This booking already has room(s) assigned - change any selection below to reassign.
            </div>
        @endif
        <form id="assignRoomsForm">
            <div id="assignRoomSelectRows">
                @for ($i = 0; $i < $booking->rooms_requested; $i++)
                    @php $preselected = $assignedRoomIds[$i] ?? null; @endphp
                    <div class="room-select-row mb-2">
                        <select name="room_ids[]" class="form-select room-select" required>
                            <option value="">-- Select an available room --</option>
                            @foreach($assignableRooms as $room)
                                <option value="{{ $room->id }}" {{ (int) $preselected === $room->id ? 'selected' : '' }}>
                                    Room {{ $room->room_number }} &middot; Sleeps {{ $room->room_capacity }} &middot; &#8369;{{ number_format($room->room_rate, 2) }}/night
                                </option>
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
        <button type="submit" form="assignRoomsForm" class="btn btn-success">
            <i class="fas fa-check"></i> {{ !empty($assignedRoomIds) ? 'Update Assignment' : ('Assign Room' . ($booking->rooms_requested > 1 ? 's' : '')) }}
        </button>
    @endif
</div>
