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
            'today' => Carbon::today()->endOfDay(),
            'week' => Carbon::now()->endOfWeek(),
            'month' => Carbon::now()->endOfMonth(),
            'all' => null,
        };

        $expectedCount = Booking::where('booking_status', Booking::STATUS_ACTIVE)->whereNotNull('verified_at')->whereNull('hidden_at')->count();
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
        $bookings = Booking::with(['reservation.guest.user', 'guest.user', 'rooms', 'roomType'])
            ->where('booking_status', Booking::STATUS_ACTIVE)
            ->whereNotNull('verified_at')
            ->whereNull('hidden_at')
            ->when($rangeEnd, fn ($q) => $q->where('check_in', '<=', $rangeEnd))
            ->orderByRaw('viewed_at IS NULL DESC')
            ->orderBy('check_in')
            ->simplePaginate(15)
            ->withQueryString();

        return view('receptionist.check-in.index', compact('bookings', 'tab', 'range', 'expectedCount', 'checkedInCount'));
    }

    /**
     * "Walk-in Check-in" form - the fast path for a guest who shows up with
     * no prior reservation or booking at all: room type, check-out date
     * (check-in is always today - there's nothing to walk in for otherwise),
     * rooms/adults/children, and a name to identify who this is. No account
     * created. Only active room types are offered, matching every other
     * room-type picker in the app.
     */
    public function createWalkIn(): View
    {
        $roomTypes = RoomType::where('status', 'active')->orderBy('name')->get();
        // Paid amenities a walk-in can add up front (billed and paid at check-in), with what's left in stock.
        $walkInAmenities = \App\Models\Amenity::where('status', 'active')->where('charge', '>', 0)->orderBy('amenity_name')->get();
        $remaining = \App\Models\Amenity::remainingStockFor($walkInAmenities->pluck('id'));
        $walkInAmenities->each(fn ($a) => $a->remaining = (int) ($remaining[$a->id] ?? 0));

        return view('receptionist.check-in.walk-in', compact('roomTypes', 'walkInAmenities') + app(\App\Services\StayOffersService::class)->formOptions());
    }

    /**
     * Creates a brand-new Booking - check_in = today, booking_status =
     * 'confirmed' - then redirects straight back to the Check-in index with
     * ?open={booking} so its own JS auto-opens the exact same Guest Details
     * + Assign Room panel (panel()/store() above) an "Expected Check-ins"
     * row would open, no separate walk-in-specific room-assignment code
     * needed. reservation_id and guest_id both stay null; payment isn't
     * addressed here at all, matching Create Reservation/Create Booking -
     * see BookingController::store()'s docblock for why.
     */
    public function storeWalkIn(Request $request): RedirectResponse
    {
        // A walk-in is a guest standing at the desk: this form always checks them in TODAY. The 48-hour
        // advance rule (CheckInWindow) is for bookings/reservations made ahead of time, not walk-ins.
        $validated = $request->validate([
            'guest_first_name' => 'required|string|max:100',
            'guest_middle_name' => 'nullable|string|max:100',
            'guest_last_name' => 'required|string|max:100',
            // Multi-room-type shape (see RoomAvailabilityService::
            // resolveAndValidateRoomLines()) - 'rooms' present is
            // authoritative; the legacy singular pair below is only used
            // when 'rooms' isn't sent, matching Receptionist\
            // BookingController::store()'s identical contract.
            'rooms' => 'nullable|array|min:1',
            'rooms.*.room_type_id' => 'required_with:rooms|exists:room_types,id',
            'rooms.*.quantity' => 'required_with:rooms|integer|min:1|max:50',
            'room_type_id' => 'required_without:rooms|exists:room_types,id',
            'check_out' => 'required|date|after:today',
            'rooms_requested' => 'nullable|integer|min:1|max:50',
            'adults' => 'required|integer|min:1',
            'children' => 'nullable|integer|min:0',
            'amenities' => 'nullable|array',
            'amenities.*.amenity_id' => 'required_with:amenities|integer',
            'amenities.*.quantity' => 'nullable|integer|min:0|max:50',
        ] + \App\Support\GuestAccountLink::rules() + \App\Services\StayOffersService::rules(), \App\Support\CheckInWindow::staffMessages());
        $linkedGuest = \App\Support\GuestAccountLink::resolve($validated['guest_user_id'] ?? null);

        // Amenities picked on the form (rows left at 0 are ignored) - checked against live stock before anything is created.
        $pickedAmenities = collect($validated['amenities'] ?? [])->filter(fn ($a) => (int) ($a['quantity'] ?? 0) > 0)->values()->all();
        $resolvedAmenities = app(\App\Services\ReservationAmenityService::class)->validateSelection($pickedAmenities);

        $checkIn = Carbon::today();
        $checkOut = Carbon::parse($validated['check_out']);

        $error = $this->availability->resolveAndValidateRoomLines(
            $validated['rooms'] ?? [],
            isset($validated['room_type_id']) ? (int) $validated['room_type_id'] : null,
            isset($validated['rooms_requested']) ? (int) $validated['rooms_requested'] : null,
            $checkIn,
            $checkOut,
            $roomLines
        );
        if ($error !== null) {
            return back()->withInput()->with('error', $error);
        }

        $capacityError = \App\Support\GuestCapacity::error($roomLines, (int) $validated['adults'] + (int) ($validated['children'] ?? 0));
        if ($capacityError !== null) {
            return back()->withInput()->with('error', $capacityError);
        }

        $children = (int) ($validated['children'] ?? 0);
        $nights = max(1, $checkIn->diffInDays($checkOut));
        $firstRoomType = $roomLines[0]['room_type'];
        $totalRoomsRequested = collect($roomLines)->sum('quantity');

        $booking = DB::transaction(function () use (
            $validated, $children, $checkIn, $checkOut, $firstRoomType, $totalRoomsRequested, $roomLines, $nights, $linkedGuest, $resolvedAmenities
        ) {
            $booking = Booking::create([
                'reservation_id' => null,
                'guest_id' => $linkedGuest?->id,
                'guest_first_name' => $validated['guest_first_name'],
                'guest_middle_name' => $validated['guest_middle_name'] ?? null,
                'guest_last_name' => $validated['guest_last_name'],
                'room_type_id' => $firstRoomType->id,
                'rooms_requested' => $totalRoomsRequested,
                'check_in' => $checkIn,
                'check_out' => $checkOut,
                'adults' => $validated['adults'],
                'children' => $children,
                'number_of_guests' => $validated['adults'] + $children,
                'confirmed_at' => now(),
                'booking_status' => Booking::STATUS_ACTIVE,
                // Same walk-in/cash reasoning as Create Booking/Create
                // Reservation - never GCash (no guest-submitted receipt exists
                // for a receptionist-typed walk-in).
                'payment_method' => 'cash',
                // Whoever creates it has obviously already seen it - shouldn't
                // show up as "new" (see index()'s ordering / the red-dot
                // indicator in the view) the moment it's created. Also
                // immediately opened via the redirect below, which would mark
                // it anyway - stated here just for clarity/consistency with
                // Create Reservation/Create Booking.
                'viewed_at' => now(),
                // No online (GCash) payment exists to verify here - a
                // receptionist typed this in directly, same reasoning as
                // Create Booking. Never lands in the "For Verification" tab.
                'verified_at' => now(),
                'verified_by' => auth()->id(),
            ]);

            // Itemized multi-room-type breakdown - same shape/purpose as
            // Receptionist\BookingController::store()'s identical addition,
            // so a walk-in with several room types (e.g. Deluxe x2 +
            // Family x1) gets the same per-room-type Assign Room grouping
            // (RoomAvailabilityService::assignableRoomsByLine()) as any
            // other multi-room-type booking.
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

            // Approved straight away - the receptionist added them with the guest at the desk; billed at check-in.
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

            return $booking;
        });

        $roomTypeSummary = collect($roomLines)->map(fn ($l) => "{$l['room_type']->name} x{$l['quantity']}")->implode(', ');
        Activity::log(
            'Created walk-in booking',
            "Booking #{$booking->id} for {$roomTypeSummary} ({$booking->guest_display_name}) - walk-in, ready for room assignment",
            $booking
        );

        // Discount (ID) or promotion chosen on the form - one or the other.
        try {
            app(\App\Services\StayOffersService::class)->applyFromRequest($request, $validated, $booking, collect($roomLines)->map(fn ($l) => $l['room_type']->id)->all(), auth()->user());
        } catch (ValidationException $e) {
            return redirect()->route('receptionist.check-in.index', ['open' => $booking->id])->withErrors($e->errors())->with('error', collect($e->errors())->flatten()->first());
        }

        return redirect()
            ->route('receptionist.check-in.index', ['open' => $booking->id])
            ->with('success', 'Walk-in guest added - now assign their room to finish checking them in.');
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
    public function panel(Booking $booking)
    {
        if ($booking->booking_status !== Booking::STATUS_ACTIVE) {
            abort(422, 'Only confirmed bookings can be checked in.');
        }
        if ($booking->hidden_at !== null) {
            abort(422, 'This booking has been archived and can no longer be checked in.');
        }

        $booking->load(['reservation.guest.user', 'guest.user', 'roomType', 'rooms']);
        // One entry per distinct room type this booking actually needs
        // rooms for (real booking_room_lines for a genuine multi-room-type
        // booking, or a single legacy entry otherwise) - see
        // RoomAvailabilityService::assignableRoomsByLine()'s own doc.
        $assignableRoomsByLine = $this->availability->assignableRoomsByLine($booking);
        $roomLines = ! empty($booking->room_lines) ? $booking->room_lines : [[
            'room_type_id' => (string) $booking->room_type_id,
            'room_type' => $booking->roomType->name ?? 'N/A',
            'quantity' => $booking->rooms_requested,
        ]];
        $assignedRoomIds = $booking->rooms->pluck('id')->all();
        // Grouped by room_type_id, matching $roomLines' shape - a flat
        // index into $assignedRoomIds would misalign across multiple
        // room-type lines (line 2's Nth select would show line 1's Nth
        // assigned room instead of its own).
        $assignedRoomIdsByType = $booking->rooms->groupBy('room_type_id')
            ->map(fn ($rooms) => $rooms->pluck('id')->all());
        $accountGuest = $booking->account_guest;

        // First open marks it read - see index()'s ordering / the
        // red-dot indicator in the view, both keyed off viewed_at. Shared
        // across Bookings/Check-in/Check-out (all three operate on this
        // same Booking row), not just this module.
        if (! $booking->viewed_at) {
            $booking->update(['viewed_at' => now()]);
        }

        return view('receptionist.check-in.partials.panel', array_merge(compact(
            'booking', 'assignableRoomsByLine', 'roomLines', 'assignedRoomIds', 'assignedRoomIdsByType', 'accountGuest'
        ), $this->settlement($booking)));
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
        if ($booking->booking_status !== Booking::STATUS_ACTIVE) {
            return response()->json(['message' => 'Only confirmed bookings can be checked in.'], 422);
        }
        if ($booking->hidden_at !== null) {
            return response()->json(['message' => 'This booking has been archived and can no longer be checked in.'], 422);
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
            'room_ids' => 'required|array|min:1',
            'room_ids.*' => 'required|array',
            'room_ids.*.*' => 'required|integer|distinct',
            // Check-in settles the bill: the full balance is paid now (no partial payment), or - when a discount
            // left the guest overpaid - the change is handed back. A fully paid stay just confirms.
            'payment_method' => 'nullable|in:cash,gcash',
            'amount_received' => 'nullable|numeric|min:0',
            'gcash_reference' => 'nullable|string|max:100',
            'change_returned' => 'nullable|boolean',
        ], [
            'checkin_current_address.required_if' => 'Enter the guest\'s current address, or check "Same as permanent address".',
        ]);

        $children = (int) ($validated['children'] ?? 0);

        $capacityError = \App\Support\GuestCapacity::error(
            \App\Support\GuestCapacity::linesForBooking($booking),
            (int) $validated['adults'] + $children
        );
        if ($capacityError !== null) {
            return response()->json(['message' => $capacityError, 'errors' => ['adults' => [$capacityError]]], 422);
        }

        $currentAddress = ($validated['current_address_same_as_permanent'] ?? false)
            ? $validated['checkin_permanent_address']
            : $validated['checkin_current_address'];

        try {
            $rooms = null;
            $settled = null;
            DB::transaction(function () use ($booking, $validated, $children, $currentAddress, &$rooms, &$settled) {
                // Settle first, on the bill as of check-in (scheduled nights) - before rooms are assigned.
                $settled = $this->settleAtCheckIn($booking, $validated);

                $rooms = $this->availability->assignRoomsForLines($booking, $validated['room_ids']);

                foreach ($rooms as $room) {
                    $room->update(['status' => 'occupied']);
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
                    // Timeline: the moment the receptionist verified/checked the guest in.
                    'checked_in_at' => now(),
                ]);
            });
        } catch (HttpExceptionInterface $e) {
            return response()->json(['message' => $e->getMessage()], $e->getStatusCode());
        } catch (ValidationException $e) {
            return response()->json(['message' => collect($e->errors())->flatten()->first(), 'errors' => $e->errors()], 422);
        }

        $accountGuest = $booking->account_guest?->user;
        if ($accountGuest && ($settled['paid_now'] ?? 0) > 0) {
            $this->notificationService->notifyPaymentReceived($accountGuest, $settled['paid_now'], $booking->roomType->name ?? null, $booking->reservation_id ?? $booking->id);
        }
        if ($accountGuest) {
            $this->notificationService->notifyCheckIn(
                $accountGuest,
                $rooms->pluck('room_name')->implode(', '),
                $booking->reservation_id ?? $booking->id,
                $booking
            );
        }

        Activity::log(
            'Checked in guest',
            "Booking #{$booking->id} - {$booking->guest_display_name} to " . $rooms->pluck('room_number')->implode(', '),
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
     *  - balance owed  -> the full balance must be paid now (cash with enough received, or GCash with a reference);
     *  - fully paid    -> nothing to pay;
     *  - overpaid (a discount approved after the guest paid) -> the change must be confirmed as handed back, and is
     *    recorded as a negative "change returned" payment so every total stays right.
     * Then the Billing is created (or refreshed) on the scheduled nights and marked settled at check-in.
     *
     * @return array{paid_now: float, change: float}
     * @throws ValidationException
     */
    private function settleAtCheckIn(Booking $booking, array $validated): array
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
            if (! $method) {
                throw ValidationException::withMessages(['payment_method' => 'The guest must pay the full balance of ₱' . number_format($balance, 2) . ' to check in.']);
            }
            if ($method === 'cash') {
                $received = (float) ($validated['amount_received'] ?? 0);
                if ($received + 0.004 < $balance) {
                    throw ValidationException::withMessages(['amount_received' => 'Cash received must cover the full balance of ₱' . number_format($balance, 2) . ' - partial payment isn\'t accepted at check-in.']);
                }
                $tender = \App\Support\CashTender::resolve('cash', $balance, $received);
                \App\Models\Payment::create($base + [
                    'payment_method' => 'cash', 'amount_paid' => $balance,
                    'cash_received' => $tender['cash_received'], 'change_given' => $tender['change_given'],
                    'payment_stage' => 'deposit', 'payment_status' => 'completed', 'payment_date' => now(),
                    'reference_number' => 'CHECKIN-' . $locked->id . '-' . now()->format('YmdHis'),
                    'verified_by' => auth()->id(), 'verified_at' => now(),
                ]);
                $change = (float) $tender['change_given'];
            } else {
                $reference = trim((string) ($validated['gcash_reference'] ?? ''));
                if ($reference === '') {
                    throw ValidationException::withMessages(['gcash_reference' => 'Enter the GCash reference number of the guest\'s payment.']);
                }
                \App\Models\Payment::create($base + [
                    'payment_method' => 'gcash', 'amount_paid' => $balance, 'reference_number' => $reference,
                    'payment_stage' => 'deposit', 'payment_status' => 'completed', 'payment_date' => now(),
                    'verified_by' => auth()->id(), 'verified_at' => now(), // confirmed at the desk
                ]);
            }
            $paidNow = $balance;
        } elseif ($balance < -0.004) {
            $change = round(-$balance, 2);
            if (empty($validated['change_returned'])) {
                throw ValidationException::withMessages(['change_returned' => 'Hand back ₱' . number_format($change, 2) . ' to the guest and confirm it before checking in.']);
            }
            // Recorded as a negative payment: the guest's paid total now equals the discounted bill.
            \App\Models\Payment::create($base + [
                'payment_method' => 'cash', 'amount_paid' => -$change,
                'payment_stage' => 'deposit', 'payment_status' => 'completed', 'payment_date' => now(),
                'reference_number' => 'CHANGE-RETURNED-' . $locked->id,
                'verified_by' => auth()->id(), 'verified_at' => now(),
            ]);
            \App\Support\Activity::log('Returned change at check-in', "Booking #{$locked->id} - ₱" . number_format($change, 2) . ' handed back after the discount', $locked);
        }

        $billing = app(\App\Services\StayBillingService::class)->ensure($locked, \App\Support\StayBill::PROJECTED);
        app(\App\Services\StayBillingService::class)->refresh($locked, $billing, \App\Support\StayBill::PROJECTED);
        $billing->update(['checkin_settled_total' => $billing->total_amount, 'checkin_settled_at' => now()]);

        if ($paidNow > 0) {
            \App\Support\Activity::log('Payment at check-in', "Booking #{$locked->id} - ₱" . number_format($paidNow, 2) . ' (' . ($validated['payment_method'] ?? 'cash') . ') - paid in full', $locked);
        }

        return ['paid_now' => $paidNow, 'change' => $change];
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
            'promotionChoices' => $offers->promotionsFor($roomTypeIds, \Carbon\Carbon::parse($booking->check_in)),
            'appliedPromotion' => $booking->promotion_id ? \App\Models\Promotion::find($booking->promotion_id) : null,
        ];
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
        } elseif ($booking->promotion_id) {
            $offers->clearPromotion($booking);
        }
        $this->discountIds->approve($booking->fresh(), (int) $validated['discount_id'], auth()->user());

        return response()->json(['message' => 'Discount approved.']);
    }

    /** Reject the guest's discount ID from the Check In panel. */
    public function rejectDiscount(Request $request, Booking $booking)
    {
        abort_unless($booking->booking_status === Booking::STATUS_ACTIVE, 422, 'Only a confirmed booking that has not checked in can be changed here.');
        $validated = $request->validate(['reason' => 'required|string|max:500']);
        $this->discountIds->reject($booking, $validated['reason'], auth()->user());

        return response()->json(['message' => 'Discount ID rejected.']);
    }

    /** Apply (or, with no promotion_id, remove) a promotion from the Check In panel - only without an approved ID discount. */
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
