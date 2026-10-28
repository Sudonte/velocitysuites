<div class="modal-header modal-header-brand">
    <h5 class="modal-title"><i class="fas fa-sign-in-alt"></i> Check In Guest</h5>
    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
</div>
<div class="modal-body" data-booking-id="{{ $booking->id }}" data-stay-id="{{ $checkInStay->id }}" data-rooms-requested="{{ collect($roomLines)->sum('quantity') }}">
    <div class="alert alert-danger d-none" id="checkInErrorAlert"></div>

    <div class="row mb-3">
        <div class="col-md-6">
            <strong>Guest:</strong> {{ $booking->guest_display_name }}<br>
            <strong>Booking:</strong> #{{ $booking->id }}<br>
            <strong>Room Type{{ count($roomLines) > 1 ? 's' : '' }}:</strong> {{ collect($roomLines)->pluck('room_type')->join(', ') }}
        </div>
        <div class="col-md-6 text-md-end">
            <strong>Check-In:</strong> {{ $checkInStay->check_in->format('M d, Y') }}<br>
            <strong>Check-Out:</strong> {{ $checkInStay->check_out->format('M d, Y') }}<br>
            <strong>Guests:</strong> {{ $booking->number_of_guests }}
        </div>
    </div>

    {{-- One booking, several date ranges (stays): tick one, several or all of them to check in together. The first to arrive settles the whole bill. --}}
    @if($bookingStays->count() > 1)
        <div class="border rounded p-2 mb-3" id="checkInStayPicker">
            <div class="small fw-semibold mb-1"><i class="fas fa-layer-group"></i> This booking has {{ $bookingStays->count() }} date ranges - tick the ones checking in now</div>
            <div class="d-flex flex-column gap-1">
                @foreach($bookingStays as $s)
                    @php $state = $booking->stateOf($s); @endphp
                    <label class="form-check mb-0 small">
                        <input type="checkbox" class="form-check-input" data-checkin-stay-check value="{{ $s->id }}"
                               {{ $checkInStays->contains('id', $s->id) ? 'checked' : '' }} @disabled($state !== 'upcoming')>
                        <span class="form-check-label">Stay {{ $s->position }} &middot; {{ $s->check_in->format('M d') }} &rarr; {{ $s->check_out->format('M d, Y') }} ({{ \App\Support\StayBill::nightsLabel(max(1, $s->check_in->diffInDays($s->check_out))) }})
                            @if($state === 'in_house') <span class="badge bg-success">In house</span>
                            @elseif($state === 'checked_out') <span class="badge bg-secondary">Done</span>@endif</span>
                    </label>
                @endforeach
            </div>
            <div class="d-flex gap-2 mt-2">
                <button type="button" class="btn btn-sm btn-outline-secondary" id="checkInStayAll">Select all</button>
                <button type="button" class="btn btn-sm btn-primary" id="checkInStayApply">Use selection</button>
            </div>
        </div>
    @endif

    {{-- Payment so far, at a glance - same answer as the booking page and Expected Check-ins (App\Support\StayPayment). --}}
    <div class="alert {{ $stayPayment['state'] === 'paid' ? 'alert-success' : ($stayPayment['state'] === 'overpaid' ? 'alert-primary' : 'alert-light border') }} py-2 small mb-3">
        <span class="badge {{ $stayPayment['badge'] }}">{{ $stayPayment['state'] === 'overpaid' ? 'Overpaid' : $stayPayment['label'] }}</span>
        @if($stayPayment['state'] === 'paid')
            Paid in full when booking (₱{{ number_format($stayPayment['paid'], 2) }}) - nothing to collect.
        @elseif($stayPayment['state'] === 'overpaid')
            Paid ₱{{ number_format($stayPayment['paid'], 2) }} but the total is now ₱{{ number_format($stayPayment['total'], 2) }} - <strong>return ₱{{ number_format($stayPayment['excess'], 2) }}</strong> to the guest. Only the actual total is kept as paid.
        @elseif($stayPayment['state'] === 'partial')
            Paid ₱{{ number_format($stayPayment['paid'], 2) }} of ₱{{ number_format($stayPayment['total'], 2) }} - ₱{{ number_format($stayPayment['balance'], 2) }} can be paid now (optional) or at check-out.
        @else
            Nothing paid yet - the ₱{{ number_format($stayPayment['total'], 2) }} can be paid now (optional) or in full at check-out.
        @endif
    </div>

    @php
        // Every distinct room type this booking needs rooms for must have
        // enough free inventory - a shortfall in ANY one line blocks the
        // whole check-in the same way a shortfall in the single legacy
        // room type used to (never partially assignable).
        $shortfallLine = null;
        foreach ($stayPanels ?? [['stay' => $checkInStay, 'roomLines' => $roomLines, 'assignable' => $assignableRoomsByLine]] as $panelStay) {
            foreach ($panelStay['roomLines'] as $line) {
                $free = ($panelStay['assignable'][(string) $line['room_type_id']] ?? collect())->count();
                if ($free < $line['quantity']) {
                    $shortfallLine = $line + ['free' => $free, 'stay_position' => $panelStay['stay']->position];
                    break 2;
                }
            }
        }
    @endphp

    {{-- Early check-in is allowed for now (temporarily relaxed per request -
         previously blocked this whole form behind a "not scheduled yet"
         warning when $booking->check_in was still in the future). Re-add
         that date check here (and in CheckInController::store()) if this
         needs to be restricted again later. --}}
    @if($shortfallLine)
        @php $freeCount = $shortfallLine['free']; @endphp
        <div class="alert alert-warning mb-0">
            <i class="fas fa-exclamation-triangle"></i>
            {{ $bookingStays->count() > 1 ? 'Stay ' . $shortfallLine['stay_position'] : 'This booking' }} needs {{ $shortfallLine['quantity'] }} {{ $shortfallLine['room_type'] }} room(s), but only {{ $freeCount }} {{ $freeCount === 1 ? 'is' : 'are' }} currently free for these dates.
        </div>
    @else
        <form id="checkInForm" data-confirm="The selected rooms will be marked occupied and the stay starts now." data-confirm-title="Complete check-in for {{ $booking->guest_display_name }}?" data-confirm-button="Confirm Check-in" data-confirm-variant="success" data-guest-capacity data-capacity-min="{{ $panelCapacity['min'] }}" data-capacity-max="{{ $panelCapacity['max'] }}">
            @if($firstCheckIn)
                @include('receptionist.check-in.partials.settlement')
            @else
                <div id="checkInStepBill">
                    <div class="alert alert-success py-2 mb-2"><i class="fas fa-check-circle"></i> The bill was settled when the first stay checked in. This stay only needs its rooms - anything added since is paid at check-out.</div>
                </div>
            @endif

            {{-- Step 2: Guest Details (registration card) - confirmed before room
                 assignment, since who's actually at the counter (and how many of
                 them there are) can differ from what was booked. --}}
            <div id="checkInStepDetails" class="d-none">
                <h6 class="mb-3"><i class="fas fa-id-card"></i> 3. Guest &amp; Booking Info</h6>

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
                        <label class="form-label small mb-1">Minors</label>
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
                <h6 class="mb-3"><i class="fas fa-door-open"></i> 4. Room Assignment</h6>
                @if(!empty($assignedRoomIds))
                    <div class="alert alert-info py-2 mb-2">
                        <i class="fas fa-circle-info"></i> Already assigned ahead of arrival - confirm below, or change the selection if needed.
                    </div>
                @endif
                @if($stayPanels)
                    @foreach($stayPanels as $sp)
                        <div class="border rounded p-2 mb-3" data-stay-rooms="{{ $sp['stay']->id }}">
                            <div class="fw-semibold mb-2"><i class="fas fa-calendar-alt"></i> Stay {{ $sp['stay']->position }} &middot; {{ $sp['stay']->check_in->format('M d') }} &rarr; {{ $sp['stay']->check_out->format('M d, Y') }}</div>
                            @include('receptionist.check-in.partials.room-assignment-group', ['roomLines' => $sp['roomLines'], 'assignable' => $sp['assignable'], 'preselectedByType' => [], 'namePrefix' => 'rooms[' . $sp['stay']->id . ']', 'stayId' => $sp['stay']->id])
                        </div>
                    @endforeach
                @else
                    @include('receptionist.check-in.partials.room-assignment-group', ['roomLines' => $roomLines, 'assignable' => $assignableRoomsByLine, 'preselectedByType' => $assignedRoomIdsByType, 'namePrefix' => 'room_ids', 'stayId' => null])
                @endif
                <p class="text-muted small mt-2 mb-0">
                    <i class="fas fa-info-circle"></i> Only rooms of the matching type, free for the full stay, are listed in each dropdown. Each room can only be picked once per date range.
                </p>

                <h6 class="mt-3 mb-2"><i class="fas fa-money-bill-wave"></i> 5. Payment Input</h6>
                @if(! $firstCheckIn)
                    <div class="alert alert-success py-2 mb-0"><i class="fas fa-check-circle"></i> Nothing to collect now - the bill was settled at the first check-in.</div>
                @else
                    {{-- All three states are on the page: once every room is picked the panel asks the server for the bill priced on
                         THOSE rooms (CheckInController::quote - a room may charge its own rate, and a promotion's discount follows it)
                         and shows the one that applies. Payment is optional - the server takes nothing, part, or all of the balance (never more). --}}
                    <div id="checkInPayArea" data-quote-url="{{ route('receptionist.check-in.quote', $booking) }}">
                        <div class="alert alert-info py-1 px-2 small mb-2 d-none" data-quote-note></div>
                        <div data-pay-state="due" class="{{ $balanceDue > 0 ? '' : 'd-none' }}">
                            <p class="small text-muted mb-2"><i class="fas fa-info-circle"></i> Payment now is <strong>optional</strong>. With no payment the check-in still goes through; Receipt 2 then shows the whole balance as due at check-out, where it must be paid in full.</p>
                            @include('receptionist.partials.desk-payment-fields', ['due' => $balanceDue, 'mode' => 'optional', 'dueLabel' => 'Balance', 'disabled' => true, 'containerId' => 'checkInPayment'])
                        </div>
                        <div data-pay-state="change" class="alert alert-primary py-2 mb-0 {{ $balanceDue <= 0 && $changeDue > 0 ? '' : 'd-none' }}">
                            <div class="form-check mb-0">
                                <input class="form-check-input" type="checkbox" name="change_returned" value="1" id="ciChangeReturned" disabled>
                                <label class="form-check-label" for="ciChangeReturned">I handed back <strong data-change-label>₱{{ number_format($changeDue, 2) }}</strong> to the guest (the total is below what they paid).</label>
                            </div>
                        </div>
                        <div data-pay-state="paid" class="alert alert-success py-2 mb-0 {{ $balanceDue <= 0 && $changeDue <= 0 ? '' : 'd-none' }}"><i class="fas fa-check-circle"></i> Paid in full - nothing to collect.</div>
                    </div>
                @endif
            </div>
        </form>
    @endif
</div>
<div class="modal-footer">
    <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancel</button>
    @if(!$shortfallLine)
        @php $idPending = $firstCheckIn && ($discountRequested ?? false) && ($discountStatus ?? '') === 'pending'; @endphp
        <button type="button" id="checkInBillContinueBtn" class="btn btn-primary" @disabled($idPending) @if($idPending) title="Approve or reject the discount ID first" @endif>
            Next: Guest Details <i class="fas fa-arrow-right"></i>
        </button>
        <button type="button" id="checkInNextBtn" class="btn btn-primary d-none">
            Next: Assign Room <i class="fas fa-arrow-right"></i>
        </button>
        <button type="button" id="checkInBackBtn" class="btn btn-outline-secondary d-none">
            <i class="fas fa-arrow-left"></i> Back
        </button>
        <button type="submit" form="checkInForm" id="checkInSubmitBtn" class="btn btn-success d-none" @disabled($idPending) @if($idPending) title="Approve or reject the discount ID first" @endif>
            <i class="fas fa-check"></i> Complete Check-In
        </button>
    @endif
</div>
