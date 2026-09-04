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
            <strong>Room Type:</strong> {{ $booking->roomType->name ?? 'N/A' }}
        </div>
        <div class="col-md-6 text-md-end">
            <strong>Check-In:</strong> {{ $booking->check_in->format('M d, Y') }}<br>
            <strong>Check-Out:</strong> {{ $booking->check_out->format('M d, Y') }}<br>
            <strong>Guests:</strong> {{ $booking->number_of_guests }}
        </div>
    </div>

    @php $tooEarly = \Illuminate\Support\Facades\Date::now()->startOfDay()->lt($booking->check_in->copy()->startOfDay()); @endphp
    @if($tooEarly)
        <div class="alert alert-warning mb-0">
            <i class="fas fa-exclamation-triangle"></i>
            This guest isn't scheduled to check in until {{ $booking->check_in->format('M d, Y') }}. Early check-in isn't allowed.
        </div>
    @elseif($assignableRooms->count() < $booking->rooms_requested)
        <div class="alert alert-warning mb-0">
            <i class="fas fa-exclamation-triangle"></i>
            This booking needs {{ $booking->rooms_requested }} {{ $booking->roomType->name ?? '' }} room(s), but only {{ $assignableRooms->count() }} {{ $assignableRooms->count() === 1 ? 'is' : 'are' }} currently free for these dates.
        </div>
    @else
        <form id="checkInForm">
            {{-- Step 1: Guest Details (registration card) - confirmed before room
                 assignment, since who's actually at the counter (and how many of
                 them there are) can differ from what was booked. --}}
            <div id="checkInStepDetails">
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
                        <input type="text" name="checkin_contact_number" class="form-control form-control-sm" required
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
                <p class="text-muted small mb-0">
                    <i class="fas fa-info-circle"></i> Update the counts above if the guest brought more (or fewer) people than originally booked - this is what determines any extra-guest fee at checkout.
                </p>
            </div>

            {{-- Step 2: Room Assignment - shown after Guest Details is confirmed. --}}
            <div id="checkInStepRooms" class="d-none">
                <h6 class="mb-3">
                    <i class="fas fa-door-open"></i> Assign Room{{ $booking->rooms_requested > 1 ? 's' : '' }}
                    @if($booking->rooms_requested > 1)
                        <span class="badge bg-secondary">{{ $booking->rooms_requested }} needed</span>
                    @endif
                </h6>
                @if(!empty($assignedRoomIds))
                    <div class="alert alert-info py-2 mb-2">
                        <i class="fas fa-circle-info"></i> Already assigned ahead of arrival - confirm below, or change the selection if needed.
                    </div>
                @endif
                <div id="roomSelectRows">
                    @for ($i = 0; $i < $booking->rooms_requested; $i++)
                        @php $preselected = $assignedRoomIds[$i] ?? null; @endphp
                        <div class="room-select-row mb-2">
                            {{-- Starts disabled - Step 2 is hidden until "Next" passes
                                 Step 1's validation. A disabled control is reliably
                                 excluded from constraint validation (unlike relying on
                                 display:none, which Chrome still tries to focus/report
                                 on and throws "is not focusable", silently aborting the
                                 Next click handler). Re-enabled in JS right before Step
                                 2 is revealed - see index.blade.php's checkInNextBtn
                                 handler. --}}
                            <select name="room_ids[]" class="form-select room-select" required disabled>
                                <option value="">-- Select an available room --</option>
                                @foreach($assignableRooms as $room)
                                    <option value="{{ $room->id }}" {{ (int) $preselected === $room->id ? 'selected' : '' }}>Room {{ $room->room_number }}</option>
                                @endforeach
                            </select>
                        </div>
                    @endfor
                </div>
                <p class="text-muted small mt-2 mb-0">
                    <i class="fas fa-info-circle"></i> Only {{ $booking->roomType->name ?? '' }} rooms free for the full stay are listed. Each room can only be picked once.
                </p>
            </div>
        </form>
    @endif
</div>
<div class="modal-footer">
    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
    @if(!$tooEarly && $assignableRooms->count() >= $booking->rooms_requested)
        <button type="button" id="checkInNextBtn" class="btn btn-primary">
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
