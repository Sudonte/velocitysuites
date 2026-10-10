@php
    // Rooms not yet individually checked out (see CheckOutController::
    // checkOutRoom()) - used to label whichever card is the last one
    // remaining, since checking THAT one out is what proceeds to billing.
    $remainingCount = $booking->rooms->whereNull('pivot.checked_out_at')->count();
@endphp
<div class="modal-header modal-header-brand">
    <h5 class="modal-title"><i class="fas fa-door-open"></i> Check Out Rooms</h5>
    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
</div>
<div class="modal-body" data-booking-id="{{ $booking->id }}">
    <div class="alert alert-danger d-none" id="roomsErrorAlert"></div>

    <div class="row mb-3">
        <div class="col-md-6">
            <strong>Guest:</strong> {{ $booking->guest_display_name }}<br>
            <strong>Booking:</strong> #{{ $booking->id }}
        </div>
        <div class="col-md-6 text-md-end">
            <strong>Check-In:</strong> {{ $booking->check_in->format('M d, Y') }}<br>
            <strong>Check-Out:</strong> {{ $booking->check_out->format('M d, Y') }}
        </div>
    </div>

    <p class="text-muted small mb-3">
        <i class="fas fa-circle-info"></i>
        This booking has {{ $booking->rooms->count() }} rooms. Check out each room individually -
        an extended stay on one room never re-bills a room that already checked out.
    </p>

    <div class="row g-3" id="roomsCardGrid">
        @foreach($booking->rooms as $room)
            @php $isCheckedOut = (bool) $room->pivot->checked_out_at; @endphp
            <div class="col-md-6" data-room-card="{{ $room->id }}">
                <div class="card h-100 {{ $isCheckedOut ? 'border-secondary' : 'border-primary' }}">
                    <div class="card-body">
                        <h6 class="card-title mb-1">
                            <i class="fas fa-door-closed"></i> Room {{ $room->room_number }}
                        </h6>
                        <p class="text-muted small mb-2">{{ $room->roomType->name ?? '' }}</p>

                        @if($isCheckedOut)
                            <span class="badge bg-secondary mb-2">
                                <i class="fas fa-check"></i> Checked Out
                                &middot; {{ \Illuminate\Support\Carbon::parse($room->pivot->checked_out_at)->format('M d, Y g:i A') }}
                            </span>
                        @else
                            @if($remainingCount === 1)
                                <span class="badge bg-warning text-dark mb-2 d-block">
                                    <i class="fas fa-triangle-exclamation"></i>
                                    Last room - checking out will proceed to billing
                                </span>
                            @endif
                            <button type="button" class="btn btn-sm btn-primary w-100 btn-checkout-room"
                                    data-room-id="{{ $room->id }}" data-room-number="{{ $room->room_number }}"
                                    data-last-room="{{ $remainingCount === 1 ? '1' : '' }}">
                                <i class="fas fa-sign-out-alt"></i> Check Out This Room
                            </button>
                        @endif
                    </div>
                </div>
            </div>
        @endforeach
    </div>
</div>
