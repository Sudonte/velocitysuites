<?php

namespace App\Http\Controllers\Receptionist;

use App\Http\Controllers\Controller;
use App\Models\Booking;
use App\Models\Room;
use App\Models\RoomType;
use App\Rules\ValidPhoneNumber;
use App\Services\NotificationService;
use App\Services\RoomAvailabilityService;
use App\Support\Activity;
use Carbon\Carbon;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

/**
 * The Check-in Module: two tabs - "Expected Check-ins" (confirmed bookings
 * awaiting arrival, where the receptionist assigns the actual room) and
 * "Checked-in Guests" (already checked in). Room assignment happens only
 * here, at check-in - never at reservation or booking time. Walk-in
 * Check-in (createWalkIn()/storeWalkIn()) is the exception to "never at
 * booking time" in spirit only: it creates the Booking and immediately
 * hands off to this same module's existing panel()/store() flow rather
 * than assigning a room itself.
 */
class CheckInController extends Controller
{
    public function __construct(
        private NotificationService $notificationService,
        private RoomAvailabilityService $availability,
        private \App\Services\DiscountIdVerificationService $discountIds,
    ) {
    }

    public function index(Request $request): View
    {
        $tab = $request->get('tab', 'expected');
        if (!in_array($tab, ['expected', 'checked_in'])) {
            $tab = 'expected';
        }

        // Expected Check-ins only ever offers a booking whose payment/
        // transaction is already verified (Bookings module's own "For
        // Verification" tab - booking_status ACTIVE + verified_at null -
        // is exactly the set this excludes) - a receptionist shouldn't be
        // able to room and check in a guest whose booking hasn't cleared
        // verification yet.
        $range = $request->get('range', 'today');
        if (!in_array($range, ['today', 'week', 'month', 'all'])) {
            $range = 'today';
        }

        // Each option widens the window rather than narrowing to an exact
        // slice - an overdue arrival (check_in already in the past) stays
        // visible under every option, "Today" included, since hiding a
        // guest who should already be here would be worse than a longer list.
        $rangeEnd = match ($range) {
            'today' => $this->hotelToday()->endOfDay(),
            'week' => $this->hotelToday()->endOfWeek(),
            'month' => $this->hotelToday()->endOfMonth(),
            'all' => null,
        };

        // Expected: confirmed bookings, plus checked-in ones that still have a stay to arrive (their next stay's guests).
        $expectedCount = Booking::awaitingCheckIn()->whereNotNull('verified_at')->whereNull('hidden_at')->count();
        $checkedInCount = Booking::where('booking_status', Booking::STATUS_CHECKED_IN)->count();

        if ($tab === 'checked_in') {
            // One row per physical room, not per booking - a multi-room
            // booking previously showed as a single row with its room
            // numbers concatenated ("302, 303"), making it hard to look a
            // guest up by room number. A room can only ever have one
            // CHECKED_IN booking assigned to it at a time
            // (RoomAvailabilityService's occupancy rules), so each row's
            // assignedBookings is exactly one booking - but check-out (and
            // its Billing) always stays keyed to that shared Booking, never
            // the individual room, so multiple rows for the same
            // multi-room booking still point at the one same billing.
            // wherePivotNull('checked_out_at') - a room individually checked
            // out via CheckOutController::checkOutRoom() (a multi-room
            // booking whose siblings are still checked in) has already left;
            // it drops off this list immediately rather than waiting for the
            // whole booking to finish, matching Room::isCurrentlyOccupied().
            $bookings = Room::whereHas('assignedBookings', fn ($q) => $q->where('booking_status', Booking::STATUS_CHECKED_IN)->whereNull('booking_rooms.checked_out_at'))
                ->with(['roomType', 'assignedBookings' => fn ($q) => $q->where('booking_status', Booking::STATUS_CHECKED_IN)->whereNull('booking_rooms.checked_out_at')
                    ->with(['reservation.guest.user', 'guest.user', 'roomType'])])
                ->orderBy('room_number')
                ->simplePaginate(15)
                ->withQueryString();

            return view('receptionist.check-in.index', compact('bookings', 'tab', 'range', 'expectedCount', 'checkedInCount'));
        }

        // simplePaginate (Previous/Next only, no numbered page links, no
        // COUNT query) instead of paginate() - the numbered page-link
        // boxes were rendering broken/oversized here for reasons that
        // didn't trace back to anything in this app's own CSS; Previous/
        // Next alone is enough to reach every booking regardless.
        // Unread first (viewed_at null - see panel() below, shared with
        // Bookings/Check-out since all three operate on this same Booking
        // row), then the existing date order.
        $bookings = Booking::with(['reservation.guest.user', 'guest.user', 'rooms', 'roomType', 'stays'])
            ->awaitingCheckIn()
            ->whereNotNull('verified_at')
            ->whereNull('hidden_at')
            // the range is about the NEXT stay still to arrive (a booking with no stay rows yet falls back to its own dates)
            ->when($rangeEnd, fn ($q) => $q->where(function ($q) use ($rangeEnd) {
                $q->whereExists(fn ($s) => $s->select(\DB::raw(1))->from('stays')->whereColumn('stays.booking_id', 'bookings.id')
                        ->whereNull('stays.checked_in_at')->whereNull('stays.no_show_at')->where('stays.check_in', '<=', $rangeEnd))
                    ->orWhere(fn ($q) => $q->whereNotExists(fn ($s) => $s->select(\DB::raw(1))->from('stays')->whereColumn('stays.booking_id', 'bookings.id'))
                        ->where('check_in', '<=', $rangeEnd));
            }))
            ->orderByRaw('viewed_at IS NULL DESC')
            ->orderBy('check_in')
            ->simplePaginate(15)
            ->withQueryString();

        return view('receptionist.check-in.index', compact('bookings', 'tab', 'range', 'expectedCount', 'checkedInCount'));
    }

    /**
     * Today at the hotel (Asia/Manila) as a plain date. The app runs in UTC, so Carbon::today() is still "yesterday" from
     * midnight to 8 AM hotel time - a walk-in then got the wrong check-in date and one night too many on the bill.
     */
    private function hotelToday(): Carbon
    {
        return Carbon::parse(\App\Support\CheckInWindow::today()->toDateString());
    }

    /**
     * "Walk-in Check-in" - one page for a guest at the desk with no booking:
     * guest + registration details, the actual rooms (picked by room number),
     * amenities, a discount (ID checked now) or promotion, and the full
     * payment. Submitting checks the guest in straight away - the stay never
     * sits in Expected Check-ins.
     */
    public function createWalkIn(Request $request): View
    {
        $roomTypes = RoomType::where('status', 'active')->orderBy('name')->get();
        // Paid amenities a walk-in can add up front (billed and paid at check-in), with what's left in stock.
        $walkInAmenities = \App\Models\Amenity::where('status', 'active')->where('charge', '>', 0)->orderBy('amenity_name')->get();
        $remaining = \App\Models\Amenity::remainingStockFor($walkInAmenities->pluck('id'));
        $walkInAmenities->each(fn ($a) => $a->remaining = (int) ($remaining[$a->id] ?? 0));
        // The form came back with errors: re-open the room types that were picked.
        $oldRoomTypes = Room::whereIn('id', (array) $request->old('room_ids', []))->pluck('room_type_id')->unique()->map(fn ($id) => (string) $id)->values()->all();

        return view('receptionist.check-in.walk-in', compact('roomTypes', 'walkInAmenities', 'oldRoomTypes') + app(\App\Services\StayOffersService::class)->formOptions());
    }

    /** Walk-in room picker: the rooms of a type a guest can be put into now, for a stay ending check_out. */
    public function walkInRooms(Request $request)
    {
        $validated = $request->validate([
            'room_type_id' => 'required|integer|exists:room_types,id',
            'check_out' => 'required|date|after:'.\App\Support\CheckInWindow::today()->toDateString(),
        ]);
        $roomType = RoomType::findOrFail($validated['room_type_id']);
        $checkOut = Carbon::parse($validated['check_out'])->startOfDay();
        $rooms = $this->availability->freeRoomsOfType($roomType->id, $this->hotelToday(), $checkOut);
        // Confirmed bookings of this type that haven't been given a room yet still need one - never sell those.
        $sellable = min($rooms->count(), $this->availability->availableCount($roomType, $this->hotelToday(), $checkOut));

        return response()->json([
            'rooms' => $rooms->map(fn ($r) => ['id' => $r->id, 'room_number' => $r->room_number, 'rate' => (float) $r->room_rate,
                'capacity' => $r->guest_capacity, 'capacity_label' => $r->has_capacity_override ? $r->capacity_label : null])->values(),
            'sellable' => $sellable,
        ]);
    }

    /**
     * Create the walk-in stay AND check the guest in, as one transaction:
     * booking + amenities, the discount (approved now) or promotion, the rooms
     * assigned, then the full payment (CheckInController::settleAtCheckIn())
     * on THOSE rooms' own rates - a room may charge differently from its type,
     * and a promotion's discount comes off that actual rate. Anything that
     * fails - a room taken a moment ago, not enough paid - rolls it all back.
     */
    public function storeWalkIn(Request $request): RedirectResponse
    {
        $linkedGuest = \App\Support\GuestAccountLink::resolve($request->input('guest_user_id'));
        $validated = $request->validate([
            'guest_first_name' => 'required|string|max:100',
            'guest_middle_name' => 'nullable|string|max:100',
            'guest_last_name' => 'required|string|max:100',
            'checkin_permanent_address' => 'required|string|max:255',
            'current_address_same_as_permanent' => 'nullable|boolean',
            'checkin_current_address' => 'exclude_if:current_address_same_as_permanent,1|required|string|max:255',
            'checkin_contact_number' => ['required', 'string', 'max:20', new ValidPhoneNumber($linkedGuest?->country ?? 'Philippines')],
            'check_out' => 'required|date|after:'.\App\Support\CheckInWindow::today()->toDateString(),
            'adults' => 'required|integer|min:1',
            'children' => 'nullable|integer|min:0',
            'room_ids' => 'required|array|min:1',
            'room_ids.*' => 'required|integer|distinct|exists:rooms,id',
            'amenities' => 'nullable|array',
            'amenities.*.amenity_id' => 'required_with:amenities|integer',
            'amenities.*.quantity' => 'nullable|integer|min:0|max:50',
        ] + \App\Support\GuestAccountLink::rules() + \App\Services\StayOffersService::rules() + \App\Support\DeskPayment::rules(), [
            'room_ids.required' => 'Tick at least one room for the guest.',
        ] + \App\Support\DeskPayment::messages() + [
            'checkin_current_address.required' => 'Enter the guest\'s current address, or tick "Same as permanent address".',
        ]);

        $checkIn = $this->hotelToday();
        $checkOut = Carbon::parse($validated['check_out'])->startOfDay();
        $nights = max(1, $checkIn->diffInDays($checkOut));
        $children = (int) ($validated['children'] ?? 0);
        $currentAddress = ! empty($validated['current_address_same_as_permanent'])
            ? $validated['checkin_permanent_address']
            : $validated['checkin_current_address'];

        // The rooms picked, grouped by room type - one booking line per type.
        $pickedRooms = Room::with('roomType')->whereIn('id', $validated['room_ids'])->get();
        if ($pickedRooms->contains(fn ($room) => ! $room->roomType || $room->roomType->status !== 'active')) {
            return back()->withInput()->with('error', 'One of the selected rooms is not a room type that can be booked.');
        }
        $roomLines = $pickedRooms->groupBy('room_type_id')
            ->map(fn ($rooms) => ['room_type' => $rooms->first()->roomType, 'quantity' => $rooms->count(), 'room_ids' => $rooms->pluck('id')->all()])
            ->values()->all();

        // the rooms picked, each with its own capacity
        $capacityError = \App\Support\GuestCapacity::error(\App\Support\GuestCapacity::linesForRooms($pickedRooms), (int) $validated['adults'] + $children);
        if ($capacityError !== null) {
            return back()->withInput()->with('error', $capacityError);
        }

        // Amenities picked on the form (rows left at 0 are ignored) - checked against live stock before anything is created.
        $pickedAmenities = collect($validated['amenities'] ?? [])->filter(fn ($a) => (int) ($a['quantity'] ?? 0) > 0)->values()->all();
        $resolvedAmenities = app(\App\Services\ReservationAmenityService::class)->validateSelection($pickedAmenities);

        try {
            [$booking, $rooms, $settled] = DB::transaction(function () use (
                $request, $validated, $children, $checkIn, $checkOut, $nights, $roomLines, $linkedGuest, $resolvedAmenities, $currentAddress
            ) {
                $this->availability->lockRoomTypesForAvailabilityCheck(collect($roomLines)->map(fn ($l) => $l['room_type']->id));
                foreach ($roomLines as $line) {
                    $left = $this->availability->availableCount($line['room_type'], $checkIn, $checkOut);
                    if ($left < $line['quantity']) {
                        throw ValidationException::withMessages(['room_ids' => "Only {$left} {$line['room_type']->name} room(s) can still be booked for these dates."]);
                    }
                }

                $booking = Booking::create([
                    'reservation_id' => null,
                    'guest_id' => $linkedGuest?->id,
                    'guest_first_name' => $validated['guest_first_name'],
                    'guest_middle_name' => $validated['guest_middle_name'] ?? null,
                    'guest_last_name' => $validated['guest_last_name'],
                    'room_type_id' => $roomLines[0]['room_type']->id,
                    'rooms_requested' => collect($roomLines)->sum('quantity'),
                    'check_in' => $checkIn,
                    'check_out' => $checkOut,
                    'adults' => $validated['adults'],
                    'children' => $children,
                    'number_of_guests' => $validated['adults'] + $children,
                    'confirmed_at' => now(),
                    'booking_status' => Booking::STATUS_ACTIVE, // checked in below, in this same transaction
                    'payment_method' => ($validated['payment_method'] ?? 'cash') === 'gcash' ? 'gcash' : 'cash',
                    'viewed_at' => now(),
                    'verified_at' => now(),
                    'verified_by' => auth()->id(),
                ]);

                foreach ($roomLines as $line) {
                    $booking->roomLines()->create([
                        'room_type_id' => $line['room_type']->id,
                        'room_type_name' => $line['room_type']->name,
                        'quantity' => $line['quantity'],
                        'price_per_night' => $line['room_type']->rate,
                        'number_of_nights' => $nights,
                        'subtotal' => round((float) $line['room_type']->rate * $nights * $line['quantity'], 2),
                    ]);
                }

                // Approved straight away - the receptionist added them with the guest at the desk; billed now.
                foreach ($resolvedAmenities as $entry) {
                    \App\Models\AmenityRequest::create([
                        'guest_id' => $linkedGuest?->id,
                        'reservation_id' => null,
                        'booking_id' => $booking->id,
                        'room_type_id' => $booking->room_type_id,
                        'amenity_id' => $entry['amenity']->id,
                        'amenity_name' => $entry['amenity']->amenity_name,
                        'category' => $entry['amenity']->category,
                        'quantity' => $entry['quantity'],
                        'charge' => $entry['amenity']->charge,
                        'status' => 'approved',
                        'origin' => \App\Models\AmenityRequest::ORIGIN_STAFF,
                        'note' => 'Added on the walk-in form',
                    ]);
                }

                // Discount (the ID is checked at the desk, so it's approved now) or promotion - one or the other.
                app(\App\Services\StayOffersService::class)->applyFromRequest(
                    $request,
                    ['approve_discount' => true] + $validated,
                    $booking,
                    collect($roomLines)->map(fn ($l) => $l['room_type']->id)->all(),
                    auth()->user()
                );

                $rooms = $this->availability->assignRoomsForLines(
                    $booking,
                    collect($roomLines)->mapWithKeys(fn ($l) => [(string) $l['room_type']->id => $l['room_ids']])->all()
                );
                foreach ($rooms as $room) {
                    $room->update(['status' => 'occupied']);
                }

                // The full bill is paid now, priced on the rooms just assigned (cash: amount paid; GCash: amount, reference and receipt).
                $settled = $this->settleAtCheckIn($booking->fresh(), $validated, $request->file('gcash_receipt'));
                // a walk-in is one stay, checked in right away
                $booking->recordStayCheckIn($booking->stayList()->first());

                $booking->update([
                    'checkin_permanent_address' => $validated['checkin_permanent_address'],
                    'checkin_current_address' => $currentAddress,
                    'checkin_contact_number' => $validated['checkin_contact_number'],
                    'booking_status' => Booking::STATUS_CHECKED_IN,
                    'checked_in_at' => now(),
                ]);

                return [$booking, $rooms, $settled];
            });
        } catch (ValidationException $e) {
            return back()->withInput()->withErrors($e->errors());
        } catch (HttpExceptionInterface $e) {
            return back()->withInput()->with('error', $e->getMessage());
        }

        $roomNumbers = $rooms->pluck('room_number')->implode(', ');
        Activity::log('Walk-in check-in', "Booking #{$booking->id} - {$booking->guest_display_name} to {$roomNumbers} - paid ₱" . number_format($settled['paid_now'], 2), $booking);

        if ($accountUser = $booking->account_guest?->user) {
            // One notification for the step: checked in + Receipt 2 + the balance (it also covers the payment taken now).
            $hasReceipt = ! empty($settled['receipt_number']);
            $this->notificationService->notifyCheckIn($accountUser, $rooms->pluck('room_name')->implode(', '), $booking->id, $booking, notifyGuest: ! $hasReceipt);
            if ($hasReceipt) {
                $this->notificationService->notifyCheckinReceipt($accountUser, $settled['receipt_number'], $booking->id, $rooms->pluck('room_name')->implode(', '), $booking);
            } elseif ($settled['paid_now'] > 0) {
                $this->notificationService->notifyPaymentReceived($accountUser, $settled['paid_now'], $rooms->pluck('room_name')->implode(', '), $booking->id);
            }
            $this->notificationService->notifyAmenitiesUnlocked($accountUser, $booking->id);
        }

        $msg = "{$booking->guest_display_name} is checked in to " . ($rooms->count() > 1 ? 'rooms ' : 'room ') . $roomNumbers . '.';
        if (($settled['change'] ?? 0) > 0) {
            $msg .= ' Change to hand back: ₱' . number_format($settled['change'], 2) . '.';
        }

        return redirect()->route('receptionist.check-in.index', ['tab' => 'checked_in'])->with('success', $msg);
    }

    /**
     * AJAX popup content: a Guest Details registration step (full name,
     * permanent/current address, contact number, actual adult/child count -
     * pre-filled from whatever's on file but editable, since whoever is
     * actually at the counter, and how many of them there are, can differ
     * from what was booked) followed by the room-assignment picker,
     * pre-filtered to rooms that actually match the booked room type and
     * are free for the reservation period. Both steps live in one form -
     * Guest Details, Assign Room, and Check In are a single action from
     * here, submitted together by store() below.
     */
    public function panel(Request $request, Booking $booking)
    {
        $checkInStay = $this->stayForCheckIn($booking, $request->integer('stay') ?: null);
        if ($checkInStay === null) {
            abort(422, 'Only confirmed bookings can be checked in.');
        }
        if ($booking->hidden_at !== null) {
            abort(422, 'This booking has been archived and can no longer be checked in.');
        }

        // Several date ranges ticked together (?stays=1,2): one room-assignment section per range, checked in as one step.
        $selectedIds = collect(explode(',', (string) $request->input('stays')))->map(fn ($v) => (int) trim($v))->filter()->unique()->values();
        $checkInStays = collect([$checkInStay]);
        if ($selectedIds->count() > 1) {
            $picked = $selectedIds->map(function (int $id) use ($booking) {
                $st = $this->stayForCheckIn($booking, $id);

                return $st && (int) $st->id === $id ? $st : null;
            });
            if ($picked->contains(null)) {
                abort(422, 'One of the selected date ranges cannot be checked in.');
            }
            $checkInStays = $picked->sortBy('position')->values();
            $checkInStay = $checkInStays->first();
        }

        $booking->load(['reservation.guest.user', 'guest.user', 'roomType', 'rooms']);
        // One entry per distinct room type THIS STAY needs rooms for (its own room lines, or a single legacy entry) - see
        // RoomAvailabilityService::assignableRoomsByLine()'s own doc.
        $assignableRoomsByLine = $this->availability->assignableRoomsByLine($booking, $checkInStay);
        $stayLines = $this->availability->linesOfStay($booking, $checkInStay);
        $roomLines = $stayLines->isNotEmpty()
            ? $stayLines->map(fn ($l) => ['room_type_id' => (string) $l->room_type_id, 'room_type' => $l->room_type_name, 'quantity' => (int) $l->quantity])->values()->all()
            : [[
                'room_type_id' => (string) $booking->room_type_id,
                'room_type' => $booking->roomType->name ?? 'N/A',
                'quantity' => $booking->rooms_requested,
            ]];
        // Every stay of the booking (to pick which one is checked in), and whether this is the first check-in: the bill of the WHOLE
        // transaction is settled then (discount, payment, Receipt 2); later stays only get their rooms.
        $bookingStays = $booking->stayList();
        $firstCheckIn = $booking->booking_status === Booking::STATUS_ACTIVE;
        $panelCapacity = \App\Support\GuestCapacity::range($this->capacityLinesFor($booking, $checkInStay));
        // Each ticked range: its own room lines and the rooms free for its dates. The guests must fit every range's rooms.
        $stayPanels = null;
        if ($checkInStays->count() > 1) {
            $stayPanels = $checkInStays->map(function ($st) use ($booking) {
                $lines = $this->availability->linesOfStay($booking, $st);

                return [
                    'stay' => $st,
                    'roomLines' => $lines->isNotEmpty()
                        ? $lines->map(fn ($l) => ['room_type_id' => (string) $l->room_type_id, 'room_type' => $l->room_type_name, 'quantity' => (int) $l->quantity])->values()->all()
                        : [['room_type_id' => (string) $booking->room_type_id, 'room_type' => $booking->roomType->name ?? 'N/A', 'quantity' => $booking->rooms_requested]],
                    'assignable' => $this->availability->assignableRoomsByLine($booking, $st),
                ];
            })->all();
            $ranges = $checkInStays->map(fn ($st) => \App\Support\GuestCapacity::range($this->capacityLinesFor($booking, $st)));
            $panelCapacity = ['min' => (int) $ranges->max('min'), 'max' => (int) $ranges->min('max')] + $panelCapacity;
        }
        $assignedRoomIds = [];
        $assignedRoomIdsByType = [];
        $accountGuest = $booking->account_guest;

        // First open marks it read - see index()'s ordering / the
        // red-dot indicator in the view, both keyed off viewed_at. Shared
        // across Bookings/Check-in/Check-out (all three operate on this
        // same Booking row), not just this module.
        if (! $booking->viewed_at) {
            $booking->update(['viewed_at' => now()]);
        }

        return view('receptionist.check-in.partials.panel', array_merge(compact(
            'booking', 'assignableRoomsByLine', 'roomLines', 'assignedRoomIds', 'assignedRoomIdsByType', 'accountGuest',
            'checkInStay', 'bookingStays', 'firstCheckIn', 'panelCapacity', 'checkInStays', 'stayPanels'
        ), $this->settlement($booking)));
    }

    /** The room types x quantities of ONE stay (what its guests must fit in) - the whole booking's for a one-stay booking. */
    private function capacityLinesFor(Booking $booking, \App\Models\Stay $stay): array
    {
        $stays = \App\Support\StaysInput::fromTransaction($booking);
        $index = $booking->stayList()->search(fn ($st) => $st->id === $stay->id);

        return $stays[$index !== false ? $index : 0]['lines'] ?? \App\Support\GuestCapacity::linesForBooking($booking);
    }

    /**
     * The stay a check-in is for. A confirmed booking checks in its first stay (or the one named); a booking already in house checks in
     * its next waiting stay. Null when there is nothing left to check in.
     */
    private function stayForCheckIn(Booking $booking, ?int $stayId): ?\App\Models\Stay
    {
        if (! in_array($booking->booking_status, [Booking::STATUS_ACTIVE, Booking::STATUS_CHECKED_IN], true)) {
            return null;
        }

        return $booking->stayToCheckIn($stayId);
    }

    /**
     * Record the Guest Details registration step, assign the room(s), and
     * check the guest in - all as one step. A booking may request more
     * than one room (rooms_requested) - the receptionist must pick exactly
     * that many distinct rooms in the popup. Delegates the "pick N rooms,
     * make sure they're really free" step to
     * RoomAvailabilityService::assignRooms() - re-validates every room
     * against a fresh query, not just the list the popup was opened with,
     * so a stale/concurrent selection can't double-book a room.
     *
     * adults/children are overwritten with whatever the receptionist
     * confirms here (not just whatever the booking originally requested),
     * but must stay within the booked rooms' capacity range
     * (App\Support\GuestCapacity).
     */
    public function store(Request $request, Booking $booking)
    {
        $checkInStay = $this->stayForCheckIn($booking, $request->integer('stay_id') ?: null);
        if ($checkInStay === null) {
            return response()->json(['message' => 'Only confirmed bookings can be checked in.'], 422);
        }
        if ($booking->hidden_at !== null) {
            return response()->json(['message' => 'This booking has been archived and can no longer be checked in.'], 422);
        }
        // The first stay to arrive settles the whole transaction's bill; the stays after it only get their rooms.
        $firstCheckIn = $booking->booking_status === Booking::STATUS_ACTIVE;

        // Several date ranges ticked together: each one is checked in with its own rooms (stay_ids[] + rooms[<stay>][<room type>][]),
        // all or nothing, and the first of them settles the bill.
        $checkInStays = collect([$checkInStay]);
        $severalStays = false;
        if (count((array) $request->input('stay_ids', [])) > 1) {
            $checkInStays = collect((array) $request->input('stay_ids'))->map(fn ($id) => (int) $id)->unique()->map(function (int $id) use ($booking) {
                $stay = $this->stayForCheckIn($booking, $id);

                return $stay && (int) $stay->id === $id && $booking->stateOf($stay) === 'upcoming' ? $stay : null;
            });
            if ($checkInStays->contains(null)) {
                return response()->json(['message' => 'One of the selected date ranges cannot be checked in (it is already in the house, or finished).'], 422);
            }
            $checkInStays = $checkInStays->sortBy('position')->values();
            $severalStays = $checkInStays->count() > 1;
            $checkInStay = $checkInStays->first();
        }

        // A requested discount must be decided (approved or rejected) first: the bill is settled from it.
        $holder = $booking->reservation ?? $booking;
        if ($firstCheckIn && ($booking->discount_requested || $holder->discount_requested) && \App\Support\StayBill::discountIdStatus($booking) === 'pending') {
            $message = "The guest's discount ID is still pending. Approve or reject it before checking in.";

            return response()->json(['message' => $message, 'errors' => ['discount_id' => [$message]]], 422);
        }

        // Early check-in is allowed for now (temporarily relaxed per
        // request - previously blocked a booking from being checked in
        // before its scheduled check_in date). Re-add a calendar-day
        // comparison against $booking->check_in here if this needs to be
        // restricted again later.

        $validated = $request->validate([
            'guest_first_name' => 'required|string|max:100',
            'guest_middle_name' => 'nullable|string|max:100',
            'guest_last_name' => 'required|string|max:100',
            'checkin_permanent_address' => 'required|string|max:255',
            'current_address_same_as_permanent' => 'nullable|boolean',
            'checkin_current_address' => 'required_if:current_address_same_as_permanent,false|nullable|string|max:255',
            'checkin_contact_number' => [
                'required', 'string', 'max:20',
                new ValidPhoneNumber($booking->account_guest?->country ?? 'Philippines'),
            ],
            'adults' => 'required|integer|min:1',
            'children' => 'nullable|integer|min:0',
            // Grouped by room_type_id - room_ids[<room_type_id>][] - one
            // group per distinct room type this booking needs rooms for
            // (see RoomAvailabilityService::assignRoomsForLines(), which
            // validates each group's own quantity/availability
            // independently; a single-room-type booking simply has one
            // group). Replaces the old flat room_ids[] shape, which assumed
            // every booking only ever needed one room type.
            // One date range: room_ids[<room type>][]. Several ticked together: rooms[<stay>][<room type>][] (a room may be
            // used again by another range - the ranges are on different nights).
            ...($severalStays
                ? ['rooms' => 'required|array|min:2', 'rooms.*' => 'required|array', 'rooms.*.*' => 'required|array', 'rooms.*.*.*' => 'required|integer']
                : ['room_ids' => 'required|array|min:1', 'room_ids.*' => 'required|array', 'room_ids.*.*' => 'required|integer|distinct']),
            // Check-in settles the bill: the full balance is paid now (no partial payment), or - when a discount
            // left the guest overpaid - the change is handed back. A fully paid stay just confirms.
        ] + \App\Support\DeskPayment::rules() + [
            'change_returned' => 'nullable|boolean',
        ], [
            'checkin_current_address.required_if' => 'Enter the guest\'s current address, or check "Same as permanent address".',
        ] + \App\Support\DeskPayment::messages());

        $children = (int) ($validated['children'] ?? 0);

        // Each date range checked in: the rooms picked for it.
        $assignments = $severalStays
            ? $checkInStays->map(fn ($st) => ['stay' => $st, 'room_ids' => $validated['rooms'][$st->id] ?? []])->all()
            : [['stay' => $checkInStay, 'room_ids' => $validated['room_ids']]];
        foreach ($assignments as $a) {
            if ($a['room_ids'] === []) {
                $message = 'Pick the rooms for every date range you are checking in (Stay ' . $a['stay']->position . ' has none).';

                return response()->json(['message' => $message, 'errors' => ['rooms' => [$message]]], 422);
            }
        }

        // Each stay's own rooms must hold the guests who are checking in to it - the rooms picked, each with its own
        // capacity (a room may hold more or fewer than its type); the booked lines when none can be read.
        foreach ($assignments as $a) {
            $pickedRooms = Room::with('roomType')->whereIn('id', collect($a['room_ids'])->flatten()->map(fn ($id) => (int) $id)->all())->get();
            $capacityError = \App\Support\GuestCapacity::error(
                $pickedRooms->isNotEmpty() ? \App\Support\GuestCapacity::linesForRooms($pickedRooms) : $this->capacityLinesFor($booking, $a['stay']),
                (int) $validated['adults'] + $children
            );
            if ($capacityError !== null) {
                return response()->json(['message' => $capacityError, 'errors' => ['adults' => [$capacityError]]], 422);
            }
        }

        $currentAddress = ($validated['current_address_same_as_permanent'] ?? false)
            ? $validated['checkin_permanent_address']
            : $validated['checkin_current_address'];

        try {
            $rooms = null;
            $settled = null;
            DB::transaction(function () use ($request, $booking, $validated, $children, $currentAddress, $assignments, $firstCheckIn, &$rooms, &$settled) {
                // Rooms first, then settle on the bill as of check-in (every stay's booked nights) priced on the rooms actually
                // given - a room may charge its own rate, and a promotion's discount comes off that rate. Only the FIRST stay to
                // arrive settles: the whole transaction is paid for then, and the stays after it just get their rooms.
                $rooms = collect();
                foreach ($assignments as $a) {
                    $given = $this->availability->assignRoomsForLines($booking, $a['room_ids'], $a['stay']);
                    foreach ($given as $room) {
                        $room->update(['status' => 'occupied']);
                    }
                    $rooms = $rooms->concat($given);
                }

                $settled = $firstCheckIn ? $this->settleAtCheckIn($booking, $validated, $request->file('gcash_receipt'), paymentOptional: true) : ['paid_now' => 0.0, 'change' => 0.0];
                foreach ($assignments as $a) {
                    $booking->recordStayCheckIn($a['stay']);
                }

                $booking->update([
                    'guest_first_name' => $validated['guest_first_name'],
                    'guest_middle_name' => $validated['guest_middle_name'] ?? null,
                    'guest_last_name' => $validated['guest_last_name'],
                    'checkin_permanent_address' => $validated['checkin_permanent_address'],
                    'checkin_current_address' => $currentAddress,
                    'checkin_contact_number' => $validated['checkin_contact_number'],
                    'adults' => $validated['adults'],
                    'children' => $children,
                    'number_of_guests' => $validated['adults'] + $children,
                    'booking_status' => Booking::STATUS_CHECKED_IN,
                    // Timeline: the moment the receptionist verified/checked the guest in (the first stay's).
                    'checked_in_at' => $booking->checked_in_at ?? now(),
                ]);
            });
        } catch (HttpExceptionInterface $e) {
            return response()->json(['message' => $e->getMessage()], $e->getStatusCode());
        } catch (ValidationException $e) {
            return response()->json(['message' => collect($e->errors())->flatten()->first(), 'errors' => $e->errors()], 422);
        }

        $accountGuest = $booking->account_guest?->user;
        if ($accountGuest) {
            // One notification for the step: checked in + Receipt 2 + the balance (it also covers the payment taken now).
            $hasReceipt = ! empty($settled['receipt_number']);
            $this->notificationService->notifyCheckIn(
                $accountGuest,
                $rooms->pluck('room_name')->implode(', '),
                $booking->reservation_id ?? $booking->id,
                $booking,
                notifyGuest: ! $hasReceipt
            );
            if ($hasReceipt) {
                $this->notificationService->notifyCheckinReceipt($accountGuest, $settled['receipt_number'], $booking->reservation_id ?? $booking->id, $rooms->pluck('room_name')->implode(', '), $booking);
            } elseif (($settled['paid_now'] ?? 0) > 0) {
                $this->notificationService->notifyPaymentReceived($accountGuest, $settled['paid_now'], $booking->roomType->name ?? null, $booking->reservation_id ?? $booking->id);
            }
            if ($firstCheckIn) {
                $this->notificationService->notifyAmenitiesUnlocked($accountGuest, $booking->reservation_id ?? $booking->id);
            }
        }

        Activity::log(
            'Checked in guest',
            "Booking #{$booking->id}" . ($booking->hasSeveralStays() ? ' (stay ' . $checkInStays->pluck('position')->implode(' + ') . ')' : '') . " - {$booking->guest_display_name} to " . $rooms->pluck('room_number')->unique()->implode(', '),
            $booking
        );

        $roomLabel = $rooms->count() > 1 ? 'Rooms ' . $rooms->pluck('room_number')->implode(', ') : 'Room ' . $rooms->first()->room_number;

        $msg = 'Guest checked in to ' . $roomLabel . '!';
        if (($settled['change'] ?? 0) > 0) {
            $msg .= ' Change: ₱' . number_format($settled['change'], 2) . '.';
        }

        return response()->json(['message' => $msg]);
    }

    /**
     * Settle the stay at check-in (inside the check-in transaction):
     *  - balance owed  -> on a booking's Check-In form payment is OPTIONAL: ₱0 is fine (the balance is then due at check-out), or
     *                     part/all of it now. A walk-in (no booking beforehand) still pays the full balance now;
     *  - fully paid    -> nothing to pay;
     *  - overpaid (a discount approved after the guest paid) -> the change must be confirmed as handed back, and is
     *    recorded as a negative "change returned" payment so every total stays right.
     * Then the Billing is created (or refreshed) on the scheduled nights and marked settled at check-in.
     *
     * @return array{paid_now: float, change: float}
     * @throws ValidationException
     */
    private function settleAtCheckIn(Booking $booking, array $validated, ?\Illuminate\Http\UploadedFile $gcashReceipt = null, bool $paymentOptional = false): array
    {
        $locked = Booking::whereKey($booking->id)->lockForUpdate()->firstOrFail();
        $locked->unsetRelation('billing');
        $payable = round((float) \App\Support\StayBill::forBooking($locked, \App\Support\StayBill::PROJECTED)['total'], 2);
        $paid = $locked->paidTotal();
        $balance = round($payable - $paid, 2);
        $paidNow = 0.0;
        $change = 0.0;

        $base = $locked->reservation_id ? ['reservation_id' => $locked->reservation_id] : ['booking_id' => $locked->id];
        if ($balance > 0.004) {
            $method = $validated['payment_method'] ?? null;
            if (! $paymentOptional) {
                if (! $method || $method === 'none') {
                    throw ValidationException::withMessages(['payment_method' => 'The guest must pay the full balance of ₱' . number_format($balance, 2) . ' to check in.']);
                }
                if ($method === 'cash' && (float) ($validated['amount_received'] ?? 0) + 0.004 < $balance) {
                    throw ValidationException::withMessages(['amount_received' => 'The amount paid must cover the full balance of ₱' . number_format($balance, 2) . ' - partial payment isn\'t accepted at check-in.']);
                }
                if ($method === 'gcash' && abs((float) ($validated['gcash_amount'] ?? 0) - $balance) > 0.004) {
                    throw ValidationException::withMessages(['gcash_amount' => 'The GCash amount must be the full balance of ₱' . number_format($balance, 2) . ' - partial payment isn\'t accepted at check-in.']);
                }
            }
            // Payment at check-in is OPTIONAL. Nothing paid now is fine: the whole balance is then due at check-out, and
            // Receipt 2 says so. A guest may also pay part (or all) of it now; the most that can be taken is the balance.
            if ($method === 'cash') {
                $received = (float) ($validated['amount_received'] ?? 0);
                $applied = round(min($received, $balance), 2);
                $tender = \App\Support\CashTender::resolve('cash', $applied, $received);
                \App\Support\DeskPayment::record($locked, 'cash', $applied, [
                    'cash_received' => $tender['cash_received'], 'change_given' => $tender['change_given'],
                    'reference_number' => 'CHECKIN-' . $locked->id . '-' . now()->format('YmdHis'),
                ]);
                $change = (float) $tender['change_given'];
                $paidNow = $applied;
            } elseif ($method === 'gcash') {
                $reference = trim((string) ($validated['gcash_reference'] ?? ''));
                $amount = round((float) ($validated['gcash_amount'] ?? 0), 2);
                $errors = [];
                if ($amount > $balance + 0.004) {
                    $errors['gcash_amount'] = 'The GCash amount can\'t be more than the balance of ₱' . number_format($balance, 2) . '.';
                }
                if ($reference === '') {
                    $errors['gcash_reference'] = 'Enter the GCash reference number of the guest\'s payment.';
                }
                if (! $gcashReceipt) {
                    $errors['gcash_receipt'] = 'Upload a photo or screenshot of the GCash receipt.';
                }
                if ($errors) {
                    throw ValidationException::withMessages($errors);
                }
                \App\Support\DeskPayment::record($locked, 'gcash', $amount, ['reference_number' => $reference], $gcashReceipt);
                $paidNow = $amount;
            }
        } elseif ($balance < -0.004) {
            $change = round(-$balance, 2);
            if (empty($validated['change_returned'])) {
                throw ValidationException::withMessages(['change_returned' => 'Hand back ₱' . number_format($change, 2) . ' to the guest and confirm it before checking in.']);
            }
            // Recorded as a negative payment: the guest's paid total now equals the discounted bill.
            \App\Support\StayPayment::recordChangeReturned($locked, $change, 'at check-in, after the discount');
        }

        $billing = app(\App\Services\StayBillingService::class)->ensure($locked, \App\Support\StayBill::PROJECTED);
        app(\App\Services\StayBillingService::class)->refresh($locked, $billing, \App\Support\StayBill::PROJECTED);
        $billing->update(['checkin_settled_total' => $billing->total_amount, 'checkin_settled_at' => now()]);

        // Receipt 2: what was settled here, frozen. Every figure comes from the server's own bill - never the client's.
        $totalPaid = round((float) $locked->fresh()->paidTotal(), 2);
        $holder = $locked->reservation ?? $locked;
        $discountStatus = $locked->discount_verification_status ?: 'not_requested';
        $receiptNumber = $billing->issueCheckinReceipt([
            // nothing collected here while a balance remains = "pay at check-out" (Receipt 2 shows the balance due then)
            'settlement_type' => $paidNow <= 0.004
                ? ($payable - $paid > 0.004 ? 'pay_at_checkout' : 'full_confirmation')
                : ($paid + $paidNow + 0.004 < $payable ? 'partial_at_checkin' : ($paid > 0.004 ? 'partial_to_full' : 'full_payment')),
            'amount_settled' => round($paidNow, 2),
            'payment_method' => $paidNow > 0.004 ? ($validated['payment_method'] ?? null) : null,
            'change_returned' => round($change, 2),
            'total_amount' => round((float) $billing->total_amount, 2),
            'total_paid_before' => round($paid, 2),
            'total_paid' => $totalPaid,
            'balance' => round(max(0, (float) $billing->total_amount - $totalPaid), 2),
            'discount' => [
                'requested' => (bool) ($locked->discount_requested || $holder->discount_requested),
                'status' => $discountStatus,
                'name' => $billing->discount_label,
                'amount' => round((float) $billing->discount, 2),
                'rejection_reason' => $discountStatus === 'rejected' ? $locked->discount_rejection_reason : null,
            ],
            // The promo the desk verified (one at most), frozen with the rest: its pesos off the room rate, and who verified it when.
            'promo' => [
                'id' => $billing->promotion_id,
                'name' => $billing->promo_label,
                'amount' => round((float) $billing->promo_discount, 2),
                'verified_by' => $locked->promotion_verified_by ? \App\Models\User::whereKey($locked->promotion_verified_by)->value('first_name') : null,
                'verified_at' => $locked->promotion_verified_at?->toIso8601String(),
            ],
            // The bill's make-up at this moment, frozen: check-out never re-prices (or re-discounts) any of it.
            'room_charge' => round((float) $billing->room_charge, 2),
            'amenity_charge' => round((float) $billing->amenity_charge, 2),
            'amenity_ids' => $locked->billedAmenityRequests()->pluck('id')->map(fn ($id) => (int) $id)->all(),
            'settled_at' => now()->toIso8601String(),
            'verified_by' => auth()->user()?->full_name,
        ]);

        app(\App\Services\ReceiptSnapshotService::class)->issueCheckinPayment($locked->fresh(), $billing->fresh(), auth()->id());

        if ($paidNow > 0) {
            \App\Support\Activity::log('Payment at check-in', "Booking #{$locked->id} - ₱" . number_format($paidNow, 2) . ' (' . ($validated['payment_method'] ?? 'cash') . ')' . ($paid + $paidNow + 0.004 >= $payable ? ' - paid in full' : ' - the rest is due at check-out'), $locked);
        }

        return ['paid_now' => $paidNow, 'change' => $change, 'receipt_number' => $receiptNumber];
    }

    /**
     * What the Check In panel's "Discount & Bill" step shows: the stay's bill
     * as of check-in (scheduled nights), the discount state, promotions that
     * can be applied instead, and what is still owed or must be handed back.
     */
    private function settlement(Booking $booking): array
    {
        $booking->unsetRelation('billing');
        $stay = \App\Support\StayBill::forBooking($booking, \App\Support\StayBill::PROJECTED);
        $payable = round((float) $stay['total'], 2);
        $paid = $booking->paidTotal();
        $offers = app(\App\Services\StayOffersService::class);
        $roomTypeIds = ! empty($booking->room_lines) ? collect($booking->room_lines)->pluck('room_type_id')->map(fn ($id) => (int) $id)->all() : [(int) $booking->room_type_id];
        $holder = $booking->reservation ?? $booking;

        return [
            'stayPayment' => \App\Support\StayPayment::from($payable, $paid),
            'stay' => $stay,
            'payable' => $payable,
            'paidSoFar' => $paid,
            'balanceDue' => max(0, round($payable - $paid, 2)),
            'changeDue' => max(0, round($paid - $payable, 2)),
            'discountStatus' => $booking->discount_verification_status ?: 'not_requested',
            'discountRequested' => (bool) ($booking->discount_requested || $holder->discount_requested),
            'hasIdPhoto' => (bool) ($booking->id_card_image_path || $holder->id_card_image_path),
            'idCardUrl' => $booking->id_card_image_path ? route('receptionist.bookings.id-card', $booking)
                : ($booking->reservation?->id_card_image_path ? route('receptionist.reservations.id-card', $booking->reservation) : null),
            'discountChoices' => $this->discountIds->choices($booking),
            'claimedDiscount' => $this->discountIds->claimed($booking),
            'promotionChoices' => $offers->promotionsFor($roomTypeIds, \Carbon\Carbon::parse($booking->check_in), $booking),
            'appliedPromotion' => $booking->promotion_id ? \App\Models\Promotion::find($booking->promotion_id) : null,
        ];
    }

    /**
     * The Check In panel's price check once every room is picked: the bill as check-in will settle it, priced on THOSE
     * rooms (a room may charge its own rate; a promotion's room-rate discount follows it). Nothing is saved.
     */
    public function quote(Request $request, Booking $booking)
    {
        $checkInStay = $this->stayForCheckIn($booking, $request->integer('stay_id') ?: null);
        abort_if($checkInStay === null, 422, 'Only confirmed bookings can be checked in.');
        $validated = $request->validate([
            'room_ids' => 'required|array|min:1',
            'room_ids.*' => 'required|array',
            'room_ids.*.*' => 'required|integer',
        ]);

        // Only rooms of the type each group is for count - the same grouping check-in itself validates.
        $picked = collect($validated['room_ids'])->flatMap(fn ($ids, $typeId) => Room::with('roomType')
            ->where('room_type_id', (int) $typeId)->whereIn('id', array_map('intval', $ids))->get());
        $picked->each(fn (Room $r) => $r->setRelation('pivot', new \Illuminate\Database\Eloquent\Relations\Pivot(['stay_id' => $checkInStay->id, 'checked_out_at' => null])));

        // This stay's rooms become the picked ones; rooms already given to the transaction's other stays stay as they are.
        $booking->load('rooms.roomType');
        $firstStayId = $booking->stayList()->first()?->id;
        $booking->setRelation('rooms', $booking->rooms->reject(fn ($r) => ($r->pivot->stay_id ?? $firstStayId) === $checkInStay->id)->concat($picked)->values());
        $booking->unsetRelation('billing');

        $stay = \App\Support\StayBill::forBooking($booking, \App\Support\StayBill::PROJECTED);
        $payable = round((float) $stay['total'], 2);
        $paid = $booking->paidTotal();

        return response()->json([
            'room_charge' => (float) $stay['room_charge'],
            'discount' => (float) $stay['discount'],
            'discount_name' => $stay['discount_name'],
            'promo_discount' => (float) $stay['promo_discount'],
            'promo_name' => $stay['promo_name'],
            'total' => $payable,
            'paid' => $paid,
            'balance_due' => max(0, round($payable - $paid, 2)),
            'change_due' => max(0, round($paid - $payable, 2)),
        ]);
    }

    /** Approve the guest's discount ID from the Check In panel (optionally uploading the photo now). */
    public function approveDiscount(Request $request, Booking $booking)
    {
        abort_unless($booking->booking_status === Booking::STATUS_ACTIVE, 422, 'Only a confirmed booking that has not checked in can be changed here.');
        $validated = $request->validate([
            'discount_id' => 'required|integer|exists:discounts,id',
            'id_document' => 'nullable|image|mimes:jpeg,png,jpg,gif|max:4096',
        ]);
        $offers = app(\App\Services\StayOffersService::class);
        $holder = $booking->reservation ?? $booking;
        if ($request->hasFile('id_document') || ! ($booking->id_card_image_path || $holder->id_card_image_path)) {
            if (! $request->hasFile('id_document')) {
                return response()->json(['message' => 'Upload a photo of the guest\'s ID first.', 'errors' => ['id_document' => ['Upload a photo of the guest\'s ID first.']]], 422);
            }
            $offers->attachDiscount($booking, (int) $validated['discount_id'], $request->file('id_document'));
        }
        $this->discountIds->approve($booking->fresh(), (int) $validated['discount_id'], auth()->user());

        return response()->json(['message' => 'Discount approved.' . \App\Support\StayPayment::returnNote($booking->fresh())]);
    }

    /** Reject the guest's discount ID from the Check In panel. */
    public function rejectDiscount(Request $request, Booking $booking)
    {
        abort_unless($booking->booking_status === Booking::STATUS_ACTIVE, 422, 'Only a confirmed booking that has not checked in can be changed here.');
        $validated = $request->validate(['reason' => 'required|string|max:500']);
        $this->discountIds->reject($booking, $validated['reason'], auth()->user());

        return response()->json(['message' => 'Discount ID rejected.']);
    }

    /** Verify (or, with no promotion_id, remove) the promo from the Check In panel: one per transaction, stacking with the ID discount. */
    public function applyPromotion(Request $request, Booking $booking)
    {
        abort_unless($booking->booking_status === Booking::STATUS_ACTIVE, 422, 'Only a confirmed booking that has not checked in can be changed here.');
        $validated = $request->validate(['promotion_id' => 'nullable|integer|exists:promotions,id']);
        $offers = app(\App\Services\StayOffersService::class);
        if (empty($validated['promotion_id'])) {
            $offers->clearPromotion($booking);

            return response()->json(['message' => 'Promotion removed.']);
        }
        $roomTypeIds = ! empty($booking->room_lines) ? collect($booking->room_lines)->pluck('room_type_id')->map(fn ($id) => (int) $id)->all() : [(int) $booking->room_type_id];
        try {
            $offers->applyPromotion($booking, \App\Models\Promotion::findOrFail((int) $validated['promotion_id']), $roomTypeIds);
        } catch (ValidationException $e) {
            return response()->json(['message' => collect($e->errors())->flatten()->first(), 'errors' => $e->errors()], 422);
        }
        \App\Support\Activity::log('Applied promotion at check-in', "Booking #{$booking->id} - promotion #{$validated['promotion_id']}", $booking);

        return response()->json(['message' => 'Promotion applied.']);
    }
}
