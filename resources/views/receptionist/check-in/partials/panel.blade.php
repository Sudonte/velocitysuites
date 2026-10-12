<div class="modal-header modal-header-brand">
    <h5 class="modal-title"><i class="fas fa-sign-in-alt"></i> Check In Guest</h5>
    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
</div>
<div class="modal-body" data-booking-id="{{ $booking->id }}" data-rooms-requested="{{ $booking->rooms_requested }}">
    <div class="alert alert-danger d-none" id="checkInErrorAlert"></div>

    <div class="row mb-3">
        <div class="col-md-6">
            <strong>Guest:</strong> {{ $booking->guest_display_name }}<br>
            <strong>Booking:</strong> #{{ $booking->id }}<br>
            <strong>Room Type{{ count($roomLines) > 1 ? 's' : '' }}:</strong> {{ collect($roomLines)->pluck('room_type')->join(', ') }}
        </div>
        <div class="col-md-6 text-md-end">
            <strong>Check-In:</strong> {{ $booking->check_in->format('M d, Y') }}<br>
            <strong>Check-Out:</strong> {{ $booking->check_out->format('M d, Y') }}<br>
            <strong>Guests:</strong> {{ $booking->number_of_guests }}
        </div>
    </div>

    @php
        // Every distinct room type this booking needs rooms for must have
        // enough free inventory - a shortfall in ANY one line blocks the
        // whole check-in the same way a shortfall in the single legacy
        // room type used to (never partially assignable).
        $shortfallLine = collect($roomLines)->first(
            fn ($line) => ($assignableRoomsByLine[(string) $line['room_type_id']] ?? collect())->count() < $line['quantity']
        );
    @endphp

    {{-- Early check-in is allowed for now (temporarily relaxed per request -
         previously blocked this whole form behind a "not scheduled yet"
         warning when $booking->check_in was still in the future). Re-add
         that date check here (and in CheckInController::store()) if this
         needs to be restricted again later. --}}
    @if($shortfallLine)
        @php $freeCount = ($assignableRoomsByLine[(string) $shortfallLine['room_type_id']] ?? collect())->count(); @endphp
        <div class="alert alert-warning mb-0">
            <i class="fas fa-exclamation-triangle"></i>
            This booking needs {{ $shortfallLine['quantity'] }} {{ $shortfallLine['room_type'] }} room(s), but only {{ $freeCount }} {{ $freeCount === 1 ? 'is' : 'are' }} currently free for these dates.
        </div>
    @else
        @php $panelCapacity = \App\Support\GuestCapacity::range(\App\Support\GuestCapacity::linesForBooking($booking)); @endphp
        <form id="checkInForm" data-confirm="The selected rooms will be marked occupied and the stay starts now." data-confirm-title="Complete check-in for {{ $booking->guest_display_name }}?" data-confirm-button="Confirm Check-in" data-confirm-variant="success" data-guest-capacity data-capacity-min="{{ $panelCapacity['min'] }}" data-capacity-max="{{ $panelCapacity['max'] }}">
            @include('receptionist.check-in.partials.settlement')

            {{-- Step 2: Guest Details (registration card) - confirmed before room
                 assignment, since who's actually at the counter (and how many of
                 them there are) can differ from what was booked. --}}
            <div id="checkInStepDetails" class="d-none">
                <h6 class="mb-3"><i class="fas fa-id-card"></i> Guest Details</h6>

                <div class="row g-2 mb-2">
                    <div class="col-md-4">
                        <label class="form-label small mb-1">First Name *</label>
                        <input type="text" name="guest_first_name" class="form-control form-control-sm" required
                               value="{{ old('guest_first_name', $booking->guest_first_name) }}">
                    </div>
                    <div class="col-md-4">
                        <label class="form-label small mb-1">Middle Name</label>
                        <input type="text" name="guest_middle_name" class="form-control form-control-sm"
                               value="{{ old('guest_middle_name', $booking->guest_middle_name) }}">
                    </div>
                    <div class="col-md-4">
                        <label class="form-label small mb-1">Last Name *</label>
                        <input type="text" name="guest_last_name" class="form-control form-control-sm" required
                               value="{{ old('guest_last_name', $booking->guest_last_name) }}">
                    </div>
                </div>

                <div class="mb-2">
                    <label class="form-label small mb-1">Permanent Address *</label>
                    <input type="text" name="checkin_permanent_address" class="form-control form-control-sm" required
                           value="{{ old('checkin_permanent_address', $booking->checkin_permanent_address ?? $accountGuest?->address) }}">
                </div>

                <div class="mb-1">
                    <label class="form-label small mb-1">Current Address *</label>
                    <input type="text" name="checkin_current_address" id="checkinCurrentAddress" class="form-control form-control-sm" required
                           value="{{ old('checkin_current_address', $booking->checkin_current_address) }}">
                </div>
                <div class="form-check mb-3">
                    <input type="checkbox" name="current_address_same_as_permanent" value="1" id="checkinSameAsPermanent" class="form-check-input">
                    <label class="form-check-label small" for="checkinSameAsPermanent">Same as permanent address</label>
                </div>

                <div class="row g-2 mb-3">
                    <div class="col-md-6">
                        <label class="form-label small mb-1">Contact Number *</label>
                        {{-- Same rule as the server: the guest's country decides the format (Philippines = 11 digits, 09...). --}}
                        <input type="text" name="checkin_contact_number" class="form-control form-control-sm" required placeholder="09XXXXXXXXX"
                               data-phone="{{ strcasecmp($accountGuest?->country ?? 'Philippines', 'Philippines') === 0 ? 'ph' : 'intl' }}"
                               value="{{ old('checkin_contact_number', $booking->checkin_contact_number ?? $accountGuest?->mobile_number) }}">
                    </div>
                </div>

                <div class="row g-2 mb-3">
                    <div class="col-md-6">
                        <label class="form-label small mb-1">Adults *</label>
                        <input type="number" name="adults" min="1" class="form-control form-control-sm" required
                               value="{{ old('adults', $booking->adults) }}">
                    </div>
                    <div class="col-md-6">
                        <label class="form-label small mb-1">Children</label>
                        <input type="number" name="children" min="0" class="form-control form-control-sm"
                               value="{{ old('children', $booking->children ?? 0) }}">
                    </div>
                </div>
                <small class="guest-capacity-hint d-block text-muted mb-2"></small>
                <p class="text-muted small mb-0">
                    <i class="fas fa-info-circle"></i> Update the counts above if the guest brought more (or fewer) people than originally booked. The total must stay within the booked rooms' capacity.
                </p>
            </div>

            {{-- Step 2: Room Assignment - shown after Guest Details is confirmed.
                 One group of selects per distinct room type the booking needs
                 rooms for (real booking_room_lines for a genuine multi-room-type
                 booking, or a single group for the legacy single-room-type case -
                 see RoomAvailabilityService::assignableRoomsByLine()'s own doc). --}}
            <div id="checkInStepRooms" class="d-none">
                <h6 class="mb-3"><i class="fas fa-door-open"></i> Assign Rooms</h6>
                @if(!empty($assignedRoomIds))
                    <div class="alert alert-info py-2 mb-2">
                        <i class="fas fa-circle-info"></i> Already assigned ahead of arrival - confirm below, or change the selection if needed.
                    </div>
                @endif
                @foreach($roomLines as $line)
                    @php
                        $typeId = (string) $line['room_type_id'];
                        $roomsOfType = $assignableRoomsByLine[$typeId] ?? collect();
                    @endphp
                    <div class="mb-3">
                        <p class="mb-1 fw-bold">
                            {{ $line['room_type'] }}
                            @if($line['quantity'] > 1)
                                <span class="badge bg-secondary">{{ $line['quantity'] }} needed</span>
                            @endif
                        </p>
                        <div id="roomSelectRows-{{ $typeId }}">
                            @php $preselectedForType = $assignedRoomIdsByType[$typeId] ?? []; @endphp
                            @for ($i = 0; $i < $line['quantity']; $i++)
                                @php $preselected = $preselectedForType[$i] ?? null; @endphp
                                <div class="room-select-row mb-2">
                                    {{-- Starts disabled - Step 2 is hidden until "Next" passes
                                         Step 1's validation. A disabled control is reliably
                                         excluded from constraint validation (unlike relying on
                                         display:none, which Chrome still tries to focus/report
                                         on and throws "is not focusable", silently aborting the
                                         Next click handler). Re-enabled in JS right before Step
                                         2 is revealed - see index.blade.php's checkInNextBtn
                                         handler. --}}
                                    <select name="room_ids[{{ $typeId }}][]" class="form-select room-select" data-room-type-id="{{ $typeId }}" required disabled>
                                        <option value="">-- Select an available room --</option>
                                        @foreach($roomsOfType as $room)
                                            <option value="{{ $room->id }}" {{ (int) $preselected === $room->id ? 'selected' : '' }}>Room {{ $room->room_number }}</option>
                                        @endforeach
                                    </select>
                                </div>
                            @endfor
                        </div>
                    </div>
                @endforeach
                <p class="text-muted small mt-2 mb-0">
                    <i class="fas fa-info-circle"></i> Only rooms of the matching type, free for the full stay, are listed in each dropdown. Each room can only be picked once.
                </p>

                <h6 class="mt-3 mb-2"><i class="fas fa-money-bill-wave"></i> Payment</h6>
                @if($balanceDue > 0)
                    {{-- Full payment only - the server refuses anything less. Disabled until this step shows. --}}
                    <div class="border rounded p-2" id="checkInPayment" data-balance="{{ $balanceDue }}">
                        <p class="mb-2">Amount due now: <strong>₱{{ number_format($balanceDue, 2) }}</strong></p>
                        <div class="btn-group btn-group-sm mb-2" role="group">
                            <input type="radio" class="btn-check" name="payment_method" id="ciPayCash" value="cash" checked disabled>
                            <label class="btn btn-outline-primary" for="ciPayCash">Cash</label>
                            <input type="radio" class="btn-check" name="payment_method" id="ciPayGcash" value="gcash" disabled>
                            <label class="btn btn-outline-primary" for="ciPayGcash">GCash</label>
                        </div>
                        <div data-pay="cash">
                            <label class="form-label small mb-1">Cash received *</label>
                            <input type="number" step="0.01" min="{{ $balanceDue }}" name="amount_received" class="form-control form-control-sm" required disabled placeholder="{{ number_format($balanceDue, 2, '.', '') }}">
                            <small class="text-muted" data-change-hint></small>
                        </div>
                        <div data-pay="gcash" class="d-none">
                            <label class="form-label small mb-1">GCash reference number *</label>
                            <input type="text" name="gcash_reference" class="form-control form-control-sm" disabled maxlength="100">
                        </div>
                    </div>
                @elseif($changeDue > 0)
                    <div class="alert alert-primary py-2 mb-0">
                        <div class="form-check mb-0">
                            <input class="form-check-input" type="checkbox" name="change_returned" value="1" id="ciChangeReturned" required disabled>
                            <label class="form-check-label" for="ciChangeReturned">I handed back <strong>₱{{ number_format($changeDue, 2) }}</strong> to the guest (the discount lowered the total below what they paid).</label>
                        </div>
                    </div>
                @else
                    <div class="alert alert-success py-2 mb-0"><i class="fas fa-check-circle"></i> Paid in full - nothing to collect.</div>
                @endif
            </div>
        </form>
    @endif
</div>
<div class="modal-footer">
    <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancel</button>
    @if(!$shortfallLine)
        <button type="button" id="checkInBillContinueBtn" class="btn btn-primary">
            Next: Guest Details <i class="fas fa-arrow-right"></i>
        </button>
        <button type="button" id="checkInNextBtn" class="btn btn-primary d-none">
            Next: Assign Room <i class="fas fa-arrow-right"></i>
        </button>
        <button type="button" id="checkInBackBtn" class="btn btn-outline-secondary d-none">
            <i class="fas fa-arrow-left"></i> Back
        </button>
        <button type="submit" form="checkInForm" id="checkInSubmitBtn" class="btn btn-success d-none">
            <i class="fas fa-check"></i> Complete Check-In
        </button>
    @endif
</div>
