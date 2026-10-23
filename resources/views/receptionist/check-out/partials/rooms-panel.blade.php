@php
    // Rooms not yet individually checked out (see CheckOutController::
    // checkOutRoom()) - used to label whichever card is the last one
    // remaining, since checking THAT one out is what proceeds to billing.
    $remainingCount = $booking->rooms->whereNull('pivot.checked_out_at')->count();
    // Several stays: the rooms of each stay together; the booking is only billed once the LAST stay has left.
    $firstStayId = $booking->stayList()->first()?->id;
    $stayGroups = $booking->stayList()->map(fn ($st) => [
        'stay' => $st,
        'rooms' => $booking->rooms->filter(fn ($r) => (($r->pivot->stay_id ?? $firstStayId) === $st->id))->values(),
    ]);
    $stillToArrive = $booking->stayList()->filter(fn ($st) => $booking->stateOf($st) === 'upcoming')->count();
    $isLastRoom = $remainingCount === 1 && $stillToArrive === 0;
@endphp
<div class="modal-header modal-header-brand">
    <h5 class="modal-title"><i class="fas fa-door-open"></i> Check Out Rooms</h5>
    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
</div>
<div class="modal-body" data-booking-id="{{ $booking->id }}">
    <div class="alert alert-danger d-none" id="roomsErrorAlert"></div>
    @if(($pendingAmenityCount ?? 0) > 0)
        <div class="alert alert-warning" role="alert">
            <i class="fas fa-triangle-exclamation"></i>
            This booking still has {{ $pendingAmenityCount }} pending amenity request(s). They are not billed until approved - resolve them
            in <a href="{{ route('receptionist.amenities.index', ['status' => 'pending', 'search' => $booking->reservation_id ?? $booking->id]) }}" target="_blank" rel="noopener">Amenity Requests</a> before billing.
        </div>
    @endif

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

    <p class="text-muted small mb-2">
        <i class="fas fa-circle-info"></i>
        This booking has {{ $booking->rooms->count() }} rooms{{ $stayGroups->count() > 1 ? ' in ' . $stayGroups->count() . ' stays' : '' }}. Tick the rooms leaving now, or check out all of them -
        each room keeps its own check-out date, and a room that already left is never re-billed.
        The booking closes (and billing opens) once every room of every stay is out.
    </p>
    @if($stillToArrive > 0)
        <div class="alert alert-info py-2 small"><i class="fas fa-layer-group"></i> {{ $stillToArrive }} more {{ $stillToArrive === 1 ? 'stay has' : 'stays have' }} not arrived yet. Billing opens after the last stay checks out.</div>
    @endif
    @if($remainingCount > 0)
        <div class="d-flex flex-wrap gap-2 align-items-center mb-3">
            <div class="form-check mb-0">
                <input class="form-check-input" type="checkbox" id="selectAllRooms">
                <label class="form-check-label" for="selectAllRooms">Select all</label>
            </div>
            <button type="button" class="btn btn-sm btn-primary" id="btnCheckoutSelected" disabled>
                <i class="fas fa-sign-out-alt"></i> Check Out Selected (<span id="selectedRoomCount">0</span>)
            </button>
            <button type="button" class="btn btn-sm btn-outline-primary" id="btnCheckoutAllRooms" data-remaining="{{ $remainingCount }}">
                <i class="fas fa-door-open"></i> Check Out All Remaining ({{ $remainingCount }})
            </button>
        </div>
    @endif

    <div class="row g-3" id="roomsCardGrid">
        @foreach($stayGroups as $group)
        @if($stayGroups->count() > 1)
            <div class="col-12"><h6 class="mb-0 mt-2"><i class="fas fa-layer-group"></i> Stay {{ $group['stay']->position }} &middot; {{ $group['stay']->check_in->format('M d') }} &rarr; {{ $group['stay']->check_out->format('M d, Y') }}
                @if($group['rooms']->isEmpty()) <span class="badge bg-light text-dark border">Not arrived yet</span> @endif</h6></div>
        @endif
        @foreach($group['rooms'] as $room)
            @php $isCheckedOut = (bool) $room->pivot->checked_out_at; @endphp
            <div class="col-md-6" data-room-card="{{ $room->id }}">
                <div class="card h-100 {{ $isCheckedOut ? 'border-secondary' : 'border-primary' }}">
                    <div class="card-body">
                        <h6 class="card-title mb-1">
                            @unless($isCheckedOut)
                                <input type="checkbox" class="form-check-input me-1 room-select" value="{{ $room->id }}" data-room-number="{{ $room->room_number }}" aria-label="Select Room {{ $room->room_number }}">
                            @endunless
                            <i class="fas fa-door-closed"></i> Room {{ $room->room_number }}
                        </h6>
                        <p class="text-muted small mb-2">{{ $room->roomType->name ?? '' }}</p>

                        @if($isCheckedOut)
                            <span class="badge bg-secondary mb-2">
                                <i class="fas fa-check"></i> Checked Out
                                &middot; {{ \Illuminate\Support\Carbon::parse($room->pivot->checked_out_at)->format('M d, Y g:i A') }}
                            </span>
                        @else
                            @if($isLastRoom)
                                <span class="badge bg-warning text-dark mb-2 d-block">
                                    <i class="fas fa-triangle-exclamation"></i>
                                    Last room - checking out will proceed to billing
                                </span>
                            @endif
                            <button type="button" class="btn btn-sm btn-primary w-100 btn-checkout-room"
                                    data-room-id="{{ $room->id }}" data-room-number="{{ $room->room_number }}"
                                    data-last-room="{{ $isLastRoom ? '1' : '' }}">
                                <i class="fas fa-sign-out-alt"></i> Check Out This Room
                            </button>
                        @endif
                    </div>
                </div>
            </div>
        @endforeach
        @endforeach
    </div>
</div>
