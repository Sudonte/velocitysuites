<?php

namespace App\Http\Controllers\Receptionist;

use App\Http\Controllers\Controller;
use App\Models\Booking;
use App\Models\Payment;
use App\Models\RoomType;
use App\Models\ActivityLog;
use App\Models\AmenityRequest;
use App\Services\DiscountIdVerificationService;
use App\Services\NotificationService;
use App\Services\ReservationWorkflowService;
use App\Services\RoomAvailabilityService;
use App\Services\TransactionGroupingService;
use App\Support\Activity;
use Carbon\Carbon;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

/**
 * The Booking Module: a registry of bookings still awaiting check-in
 * (booking_status = confirmed), plus (see create()/store()) creating one
 * directly - everything else here stays view-only/status-transition-only,
 * never touching room assignment or check-in, which still live in the
 * Check-in Module. Same single-stage-at-a-time rule as every other module -
 * once a guest checks in, the record disappears from here and shows up in
 * the Check-in Module's "Checked-in Guests" tab (and later the Check-out
 * Module) instead, not here as well.
 *
 * Two extra tabs beyond the original Active/Complete pair - "Rejected /
 * Failed" (booking_status = cancelled, not archived) and "Archived" (same,
 * archived) - exist purely so a receptionist has somewhere to clean up a
 * booking that never worked out (see reconcileStuckGcashBookings() and
 * archive()/destroy() below), never to change a booking's live state.
 */
class BookingController extends Controller
{
    public function __construct(
        private ReservationWorkflowService $workflow,
        private NotificationService $notifications,
        private RoomAvailabilityService $availability,
        private TransactionGroupingService $grouping,
        private DiscountIdVerificationService $discountIds,
    ) {
    }

    /**
     * "For Verification" ('pending', verified_at null) by default - in
     * practice this is now GCash-only: convertToBooking() (Cash) and
     * store()/CheckInController::storeWalkIn() (both always
     * receptionist-created) all auto-verify at creation, so nothing but a
     * GCash booking awaiting Receptionist\PaymentController::verify() (or
     * a pre-existing Cash booking from before that auto-verify shipped)
     * ever lands here. Other tabs: 'verified' (Confirmed Bookings -
     * verified but not yet archived), 'rejected' (cancelled, not archived
     * - needs a receptionist decision), 'archived' (verified OR
     * cancelled, archived - see isArchivable()). ?verified=1 is kept
     * working as an alias for tab=verified for any old bookmarked links.
     */
    public function index(Request $request): View
    {
        $this->reconcileStuckGcashBookings();

        $tab = $request->get('tab', $request->boolean('verified') ? 'verified' : 'pending');
        if (!in_array($tab, ['pending', 'verified', 'rejected', 'archived'], true)) {
            $tab = 'pending';
        }

        $query = Booking::with(['reservation.guest.user', 'reservation.payments', 'guest.user', 'payments', 'roomType', 'room', 'rooms']);

        match ($tab) {
            'pending' => $query->where('booking_status', Booking::STATUS_ACTIVE)->whereNull('verified_at'),
            'verified' => $query->where('booking_status', Booking::STATUS_ACTIVE)->whereNotNull('verified_at')->whereNull('hidden_at'),
            'rejected' => $query->whereIn('booking_status', [Booking::STATUS_CANCELLED, Booking::STATUS_NO_SHOW])->whereNull('hidden_at'),
            'archived' => $query->whereNotNull('hidden_at'),
        };

        if ($request->filled('search')) {
            $search = $request->search;
            $query->where(function ($outer) use ($search) {
                $outer->whereHas('reservation.guest.user', function ($q) use ($search) {
                    $q->where('email', 'like', "%{$search}%");
                })->orWhereHas('guest.user', function ($q) use ($search) {
                    $q->where('email', 'like', "%{$search}%");
                });
            });
        }

        // Unread first (viewed_at null - see show() below), then closest
        // check-in date - whoever is arriving soonest is the
        // receptionist's priority, not whoever was most recently confirmed.
        // simplePaginate (Previous/Next only, no numbered page links) -
        // same fix as Check-in's index(): the numbered page-link boxes
        // render broken/oversized here for reasons that don't trace back
        // to anything in this app's own CSS.
        // The stays of one group sit together: they take the group's place in the list (its earliest check-in, unread if any stay is)
        // and are then ordered among themselves by their own check-in. A booking that is not in a group is its own place.
        $groupUnread = '(SELECT MAX(CASE WHEN g.viewed_at IS NULL THEN 1 ELSE 0 END) FROM bookings g WHERE g.group_id = bookings.group_id)';
        $groupFirstIn = '(SELECT MIN(g.check_in) FROM bookings g WHERE g.group_id = bookings.group_id)';
        $bookings = $query
            ->orderByRaw("CASE WHEN group_id IS NULL THEN (CASE WHEN viewed_at IS NULL THEN 1 ELSE 0 END) ELSE $groupUnread END DESC")
            ->orderByRaw("CASE WHEN group_id IS NULL THEN check_in ELSE $groupFirstIn END")
            ->orderByRaw('COALESCE(group_id, -id)')
            ->orderBy('check_in')
            ->simplePaginate(15)->withQueryString();
        // "Group of N stays": how many bookings each group on this page has (any status, so a cancelled stay still counts).
        $groupSizes = Booking::withTrashed()->whereIn('group_id', $bookings->pluck('group_id')->filter()->unique())
            ->selectRaw('group_id, COUNT(*) AS n')->groupBy('group_id')->pluck('n', 'group_id')->all();

        $pendingCount = Booking::where('booking_status', Booking::STATUS_ACTIVE)->whereNull('verified_at')->count();
        $verifiedCount = Booking::where('booking_status', Booking::STATUS_ACTIVE)->whereNotNull('verified_at')->whereNull('hidden_at')->count();
        $rejectedCount = Booking::whereIn('booking_status', [Booking::STATUS_CANCELLED, Booking::STATUS_NO_SHOW])->whereNull('hidden_at')->count();

        return view('receptionist.bookings.index', compact(
            'bookings', 'tab', 'pendingCount', 'verifiedCount', 'rejectedCount', 'groupSizes'
        ));
    }

    /**
     * "Create Booking" form - a receptionist creates an already-confirmed
     * Booking directly (skips the Reservation stage entirely) on a guest's
     * behalf, no User/Guest account created for them: the guest's name is
     * just typed in directly (guest_first_name/middle/last_name). Only
     * active room types are offered, matching every other room-type picker
     * in the app.
     */
    public function create(): View
    {
        $roomTypes = RoomType::where('status', 'active')->orderBy('name')->get();

        return view('receptionist.bookings.create', compact('roomTypes') + app(\App\Services\StayOffersService::class)->formOptions());
    }

    /**
     * Creates the Booking directly at booking_status = 'confirmed' -
     * reservation_id and guest_id both left null (bookings.guest_id has
     * been nullable since 2026_08_23_150000_make_bookings_independent_of_reservations.php;
     * this is the first flow that actually exercises that). payment_method
     * is left null - no payment is collected here; the receptionist
     * records one later via the existing record-payment action once the
     * guest actually pays, same as verified_at/verified_by staying null
     * ("Pending Verification") until the existing verify() action.
     */
    public function store(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'guest_first_name' => 'required|string|max:100',
            'guest_middle_name' => 'nullable|string|max:100',
            'guest_last_name' => 'required|string|max:100',
            // Multi-room-type shape (see RoomAvailabilityService::
            // resolveAndValidateRoomLines()) - 'rooms' present is
            // authoritative; the legacy singular pair below is only used
            // when 'rooms' isn't sent, matching Api\BookingController::
            // store()'s identical backward-compatible contract.
            'rooms' => 'nullable|array|min:1',
            'rooms.*.room_type_id' => 'required_with:rooms|exists:room_types,id',
            'rooms.*.quantity' => 'required_with:rooms|integer|min:1|max:50',
            'room_type_id' => 'required_without:rooms|exists:room_types,id',
            'check_in' => \App\Support\CheckInWindow::rules(),
            'check_out' => 'required|date|after:check_in',
            'rooms_requested' => 'nullable|integer|min:1|max:50',
            'adults' => 'required|integer|min:1',
            'children' => 'nullable|integer|min:0',
        ] + \App\Support\GuestAccountLink::rules() + \App\Services\StayOffersService::rules() + \App\Support\DeskPayment::rules(),
            \App\Support\CheckInWindow::staffMessages() + \App\Support\DeskPayment::messages());
        $linkedGuest = \App\Support\GuestAccountLink::resolve($validated['guest_user_id'] ?? null);

        $checkIn = Carbon::parse($validated['check_in']);
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
        // Legacy room_type_id/rooms_requested kept in sync from the first
        // line's type and the summed quantity across every line, purely
        // for backward-compatible display - identical convention to
        // DirectBookingService::create()/Api\ReservationController::
        // update()'s patched multi-room path.
        $firstRoomType = $roomLines[0]['room_type'];
        $totalRoomsRequested = collect($roomLines)->sum('quantity');

        try {
            $booking = DB::transaction(function () use (
                $validated, $children, $checkIn, $checkOut, $firstRoomType, $totalRoomsRequested, $roomLines, $nights, $linkedGuest
            ) {
            // The resolveAndValidateRoomLines() call above is only a fast-
            // fail check against a snapshot that's already stale by the
            // time we get here - a concurrent request for the same room
            // type (this same form double-submitted, the guest mobile app's
            // DirectBookingService::create(), or another reservation
            // converting via ReservationWorkflowService) could have
            // consumed the last room in between. Locking every requested
            // room_type row and re-checking here, with the lock held, is
            // the actual guard - this booking consumes real inventory
            // immediately, same as DirectBookingService::create()'s
            // guest-facing equivalent.
            $this->availability->lockRoomTypesForAvailabilityCheck(collect($roomLines)->pluck('room_type.id'));
            foreach ($roomLines as $line) {
                $available = $this->availability->availableCount($line['room_type'], $checkIn, $checkOut);
                if ($available < $line['quantity']) {
                    throw ValidationException::withMessages(['rooms' => [
                        $line['quantity'] > 1
                            ? "Not enough {$line['room_type']->name} rooms available for these dates (needs {$line['quantity']}, only {$available} free)."
                            : "{$line['room_type']->name} is fully booked for these dates.",
                    ]]);
                }
            }

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
                // A receptionist typing this in directly is a walk-in, cash-
                // paid scenario the same as any other Create Booking/Create
                // Reservation flow - never GCash (that needs a guest-submitted
                // receipt, which doesn't exist here).
                'payment_method' => 'cash',
                // Whoever creates it has obviously already seen it - shouldn't
                // show up as "new" (see index()'s ordering / the red-dot
                // indicator in the view) the moment it's created.
                'viewed_at' => now(),
                // No online (GCash) payment exists to verify here - a
                // receptionist typed this in directly, same reasoning as a
                // Cash reservation conversion (see ReservationWorkflowService::
                // convertToBooking()'s identical auto-verify). Never lands in
                // the "For Verification" tab.
                'verified_at' => now(),
                'verified_by' => auth()->id(),
            ]);

            // Itemized multi-room-type breakdown - one line per distinct
            // room type, same shape ReservationWorkflowService::
            // createBookingFromReservation() writes on conversion, so
            // Booking::getTotalAmountDueAttribute()/getRoomLinesAttribute()
            // (and the Android app's per-room-type image cards) work
            // identically for a receptionist-created multi-room-type
            // booking as for a guest-submitted one.
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

            return $booking;
            });
        } catch (ValidationException $e) {
            return back()->withInput()->with('error', collect($e->errors())->flatten()->first());
        }

        // Discount (ID) or promotion chosen on the form - one or the other.
        try {
            app(\App\Services\StayOffersService::class)->applyFromRequest($request, $validated, $booking, collect($roomLines)->map(fn ($l) => $l['room_type']->id)->all(), auth()->user());
        } catch (ValidationException $e) {
            return redirect()->route('receptionist.bookings.show', $booking)->withErrors($e->errors())->with('error', collect($e->errors())->flatten()->first());
        }

        // Payment the guest made now (optional - nothing, part or all of it).
        $paymentNote = $this->recordPaymentFromCreateForm($request, $validated, $booking);
        if ($paymentNote instanceof RedirectResponse) {
            return $paymentNote;
        }

        $roomTypeSummary = collect($roomLines)->map(fn ($l) => "{$l['room_type']->name} x{$l['quantity']}")->implode(', ');
        Activity::log(
            'Created booking',
            "Booking #{$booking->id} for {$roomTypeSummary} ({$booking->guest_display_name}) - created directly by staff",
            $booking
        );

        // Linked to a guest account: tell the guest it's on their account now.
        if ($linkedGuest?->user) {
            $this->notifications->toUser($linkedGuest->user, 'Booking Added', "Velocity Suites added Booking #{$booking->id} ({$roomTypeSummary}, {$booking->check_in->format('M d')} - {$booking->check_out->format('M d, Y')}) to your account.", 'booking', $booking->id);
        }

        return redirect()
            ->route('receptionist.bookings.show', $booking)
            ->with('success', 'Booking created for ' . $booking->guest_display_name . '.' . ($paymentNote !== '' ? $paymentNote : ' Record their payment whenever they pay.') . ' Check them in from the Check-in Module when they arrive.');
    }

    /**
     * Stream a direct-booking guest's uploaded Senior/PWD ID card image for
     * staff verification - mirrors Receptionist\ReservationController::
     * idCard() exactly (private 'local' disk, no ownership check since any
     * receptionist may verify any guest's discount request). Only ever
     * populated for a direct "New Booking" transaction (reservation_id
     * null) - a reservation-derived booking's ID card, if any, is still
     * viewed via reservations.id-card on the originating Reservation.
     */
    public function idCard(Booking $booking)
    {
        if (!$booking->id_card_image_path || !Storage::disk('local')->exists($booking->id_card_image_path)) {
            abort(404);
        }

        return Storage::disk('local')->response($booking->id_card_image_path);
    }

    public function show(Booking $booking): View
    {
        $booking->load(['reservation.guest.user', 'reservation.payments', 'guest.user', 'payments', 'roomType', 'room', 'rooms', 'billing']);

        $this->workflow->reconcileGcashBookingPayment($booking);

        // First open marks it read - see index()'s ordering / the
        // red-dot indicator in the view, both keyed off viewed_at. Shared
        // across Bookings/Check-in/Check-out (all three operate on this
        // same Booking row), not just this module.
        if (! $booking->viewed_at) {
            $booking->update(['viewed_at' => now()]);
        }

        // Best-effort multi-room-type grouping - see TransactionGroupingService's
        // own doc for why this is a heuristic (same guest/dates/creation
        // window), not a real FK, for organic guest data. Null when this
        // booking isn't part of any detected group (the common, single-room-
        // type case) or has its own authoritative room_lines already.
        $siblings = empty($booking->room_lines) ? $this->grouping->siblingsForBooking($booking) : null;
        $transactionBookings = $siblings ?? collect([$booking]);
        $roomLines = $this->grouping->roomLinesForBooking($booking, $siblings);

        // Authoritative Total/Paid/Remaining for the "Payment & Balance"
        // card below - same total_amount_due accessor the guest-facing API
        // already exposes (Api\BookingController::index()/show()), summed
        // across every sibling in the detected group (never just this one
        // row) so the receptionist sees the complete transaction's true
        // total, not one room type's own share of it.
        // payableTotal(): the StayBill total (or the billing's once billed), so an APPROVED discount ID already lowers it.
        $totalDue = round((float) $transactionBookings->sum(fn (Booking $b) => $b->payableTotal()), 2);
        $stayBills = $transactionBookings->map(fn (Booking $b) => \App\Support\StayBill::forBooking($b, \App\Support\StayBill::PROJECTED));
        $billDiscount = round((float) $stayBills->sum('discount'), 2);
        $discountLabel = $stayBills->pluck('discount_name')->filter()->unique()->implode(', ') ?: 'ID discount';
        $amountPaid = (float) $transactionBookings->sum(
            fn (Booking $b) => (float) $b->allPayments()->where('payment_status', 'completed')->sum('amount_paid')
        );
        $remainingBalance = max(0, round($totalDue - $amountPaid, 2));
        // Same status every screen shows - incl. OVERPAID (e.g. paid in full, then a discount was approved).
        $stayPayment = \App\Support\StayPayment::from($totalDue, $amountPaid);

        // Amenities: same query getTotalAmountDueAttribute() itself uses for
        // money (reservation_id for a converted booking, booking_id for a
        // direct one) - single source of truth, summed across every sibling
        // in the group (amenities are only ever attached to one sibling at
        // creation time, so this naturally picks up whichever one carries
        // them without double-counting).
        $amenityRows = collect();
        foreach ($transactionBookings as $b) {
            $amenityRows = $amenityRows->merge(
                AmenityRequest::where(function ($q) use ($b) {
                    if ($b->reservation_id) {
                        $q->where('reservation_id', $b->reservation_id);
                    } else {
                        $q->where('booking_id', $b->id);
                    }
                })->get()
            );
        }
        // Excludes only 'rejected', matching Booking::billableAmenityRequests()
        // exactly - a GCash booking's amenity_requests sit at 'pending' until
        // the receptionist verifies the payment below, but the guest already
        // paid for them at booking time, so they must count here too (not
        // just once verified) or this card would show a Grand Total the
        // receptionist's own verification screen later contradicts.
        $amenitiesTotal = (float) $amenityRows->where('status', '!=', 'rejected')->sum(fn ($r) => $r->charge * $r->quantity);
        $roomTotal = round((float) collect($roomLines)->sum('subtotal'), 2);

        // Transaction/status history - this booking's own entries, plus its
        // founding reservation's (if converted), merged newest-first so the
        // receptionist sees the whole lifecycle in one timeline.
        $historySubjects = [['type' => 'booking', 'id' => $booking->id]];
        if ($booking->reservation_id) {
            $historySubjects[] = ['type' => 'reservation', 'id' => $booking->reservation_id];
        }
        $history = ActivityLog::where(function ($q) use ($historySubjects) {
            foreach ($historySubjects as $s) {
                $q->orWhere(fn ($qq) => $qq->where('subject_type', $s['type'])->where('subject_id', $s['id']));
            }
        })->with('user')->orderByDesc('created_at')->get();

        // The discount ID is decided here, in the Booking module, separately from verifying the transaction.
        // The discount ID is view-only here: it is approved or rejected in the Check In module.
        $discountChoices = $this->discountIds->choices($booking);
        $discountDecidedBy = $booking->discountDecidedBy;

        return view('receptionist.bookings.show', compact(
            'booking', 'totalDue', 'amountPaid', 'remainingBalance', 'stayPayment',
            'siblings', 'roomLines', 'roomTotal', 'amenityRows', 'amenitiesTotal', 'history', 'billDiscount', 'discountLabel',
            'discountChoices', 'discountDecidedBy'
        ));
    }

    /**
     * Record a walk-in payment (cash or GCash-confirmed-in-person) against
     * an active Booking's remaining balance - reachable any time during the
     * stay (confirmed or already checked-in), not just at checkout, since a
     * Billing row (and its own /billing/{billing}/payment route) doesn't
     * exist until CheckOutController::generateBilling() first runs. Staff-
     * recorded, so it lands completed + verified immediately, same
     * semantics as Receptionist\ReservationController::confirmCashPayment().
     */
    /**
     * Create Booking's optional payment: cash (amount paid - anything over the
     * total is change) or GCash (amount, reference and receipt). Partial or
     * full; never more than the booking's total after its discount. Returns a
     * short note for the success message, or a redirect when it was refused
     * (the booking itself is kept - the payment can be recorded on its page).
     */
    private function recordPaymentFromCreateForm(Request $request, array $validated, Booking $booking): string|RedirectResponse
    {
        $method = $validated['payment_method'] ?? 'none';
        if (! in_array($method, ['cash', 'gcash'], true)) {
            return '';
        }

        $booking->unsetRelation('billing');
        $total = max(0, round($booking->payableTotal() - $booking->paidTotal(), 2));
        $amount = round((float) ($method === 'cash' ? $validated['amount_received'] : $validated['gcash_amount']), 2);
        if ($method === 'gcash' && $amount > $total + 0.004) {
            return redirect()->route('receptionist.bookings.show', $booking)
                ->with('error', 'Booking created, but the GCash payment was not recorded: ₱' . number_format($amount, 2) . ' is more than the total of ₱' . number_format($total, 2) . '.');
        }

        $applied = min($amount, $total);
        if ($method === 'cash') {
            $tender = \App\Support\CashTender::resolve('cash', $applied, $amount);
            \App\Support\DeskPayment::record($booking, 'cash', $applied, ['cash_received' => $tender['cash_received'], 'change_given' => $tender['change_given']]);
        } else {
            \App\Support\DeskPayment::record($booking, 'gcash', $applied, ['reference_number' => trim($validated['gcash_reference'])], $request->file('gcash_receipt'));
            $booking->update(['payment_method' => 'gcash']);
        }

        Activity::log('Recorded booking payment', "Booking #{$booking->id} - ₱" . number_format($applied, 2) . " ({$method}) " . ($applied + 0.004 >= $total ? 'paid in full' : 'partial'), $booking);

        return $applied + 0.004 >= $total
            ? ' Paid in full (₱' . number_format($applied, 2) . ')' . ($amount > $applied ? ' - change ₱' . number_format($amount - $applied, 2) : '') . '.'
            : ' Partial payment of ₱' . number_format($applied, 2) . ' recorded - ₱' . number_format($total - $applied, 2) . ' still owed.';
    }

    /**
     * The guest paid more than the stay now costs (a discount approved after
     * they paid in full, ...): record the excess as handed back, so what the
     * stay is recorded as paid equals its actual total.
     */
    public function returnChange(Request $request, Booking $booking): RedirectResponse
    {
        $request->validate(['confirm' => 'accepted'], ['confirm.accepted' => 'Confirm that you handed the money back to the guest.']);

        $returned = DB::transaction(function () use ($booking) {
            $locked = Booking::whereKey($booking->id)->lockForUpdate()->firstOrFail();
            $excess = \App\Support\StayPayment::of($locked)['excess'];
            if ($excess <= 0.004) {
                return 0.0;
            }
            \App\Support\StayPayment::recordChangeReturned($locked, $excess, 'from the booking page');

            return $excess;
        });

        return $returned > 0
            ? back()->with('success', '₱' . number_format($returned, 2) . ' recorded as returned to the guest. The booking is now paid at its actual total.')
            : back()->with('error', 'Nothing to return - the guest has not paid more than the total.');
    }

    public function recordPayment(Request $request, Booking $booking): RedirectResponse
    {
        if (!in_array($booking->booking_status, [Booking::STATUS_ACTIVE, Booking::STATUS_CHECKED_IN], true)) {
            return back()->with('error', 'Only an active (confirmed or checked-in) booking can have a payment recorded.');
        }
        // An ACTIVE_BOOKING can be archived once verified (see
        // isArchivable()) - archiving only ever removes it from the
        // Confirmed Bookings list, it doesn't itself block this route, so a
        // stale/bookmarked link could otherwise still record a real payment
        // against something the receptionist considers done and tucked away.
        if ($booking->hidden_at !== null) {
            return back()->with('error', 'This booking has been archived and can no longer have a payment recorded.');
        }

        // Walk-in (unverified, staff-declared-complete) recording only ever
        // makes sense for Cash - a GCash booking's payments must go through
        // the receipt+number submission and verification flow above
        // (Booking::gcashPaymentNeedsVerification()'s gate), never a
        // receptionist just declaring an amount received with no evidence.
        if ($booking->payment_method !== 'cash') {
            return back()->with('error', 'Only a Cash booking can have a walk-in payment recorded here.');
        }

        // The cap is the balance of what is actually payable NOW - the booking's StayBill total, so an APPROVED discount ID
        // already lowers it (see Booking::payableTotal()). Checked here for the form's sake and again under the row lock below.
        $booking->loadMissing(['reservation.payments', 'payments']);
        $remainingBalance = max(0, round($booking->payableTotal() - $booking->paidTotal(), 2));

        $validated = $request->validate([
            'amount_paid' => ['required', 'numeric', 'min:0.01', 'max:' . max(0.01, $remainingBalance)],
            'amount_received' => ['nullable', 'numeric', 'min:0.01'],
        ], [
            'amount_paid.max' => "The amount cannot exceed the remaining balance (₱" . number_format($remainingBalance, 2) . ").",
        ]);

        $applied = round((float) $validated['amount_paid'], 2);
        $tender = \App\Support\CashTender::resolve('cash', $applied, isset($validated['amount_received']) ? (float) $validated['amount_received'] : null);

        $result = DB::transaction(function () use ($booking, $applied, $tender) {
            // Lock the booking row and re-read the balance from the payments committed so far, so two submissions racing
            // for the same balance can't both pass the cap and overpay it together.
            $locked = Booking::whereKey($booking->id)->lockForUpdate()->firstOrFail();
            $locked->unsetRelation('payments')->unsetRelation('reservation')->unsetRelation('billing');
            if (! in_array($locked->booking_status, [Booking::STATUS_ACTIVE, Booking::STATUS_CHECKED_IN], true)) {
                return ['error' => 'This booking can no longer have a payment recorded.'];
            }
            $remaining = max(0, round($locked->payableTotal() - $locked->paidTotal(), 2));
            if ($applied > $remaining + 0.004) {
                return ['error' => 'The amount cannot exceed the remaining balance (₱' . number_format($remaining, 2) . ').'];
            }

            $paymentData = [
                'payment_method' => 'cash',
                'amount_paid' => $applied,
                'cash_received' => $tender['cash_received'],
                'change_given' => $tender['change_given'],
                'payment_stage' => 'deposit',
                'payment_status' => 'completed',
                'payment_date' => now(),
                'verified_by' => auth()->id(),
                'verified_at' => now(),
            ];
            if ($locked->reservation_id) {
                $paymentData['reservation_id'] = $locked->reservation_id;
            } else {
                $paymentData['booking_id'] = $locked->id;
            }
            Payment::create($paymentData);

            return ['remaining' => round($remaining - $applied, 2)];
        });

        if (isset($result['error'])) {
            return back()->with('error', $result['error']);
        }
        $newRemaining = $result['remaining'];

        $booking->loadMissing(['reservation.guest.user', 'guest.user']);
        Activity::log(
            'Recorded walk-in payment',
            "Booking #{$booking->id} - {$booking->guest_display_name} - ₱"
                . number_format($applied, 2) . " (cash" . ($tender['change_given'] > 0 ? ', ₱' . number_format($tender['cash_received'], 2) . ' received, ₱' . number_format($tender['change_given'], 2) . ' change' : '')
                . ") - remaining balance now ₱" . number_format($newRemaining, 2),
            $booking
        );

        if ($guest = $booking->account_guest?->user) {
            $this->notifications->notifyPaymentReceived($guest, $applied, $booking->roomType->name ?? null, $booking->reservation_id ?? $booking->id);
        }

        $message = 'Payment recorded. Remaining balance: ₱' . number_format($newRemaining, 2) . '.';
        if ($tender['change_given'] > 0) {
            $message .= ' Change due: ₱' . number_format($tender['change_given'], 2) . '.';
        }

        return back()->with('success', $message);
    }

    /**
     * Marks a booking Verified - moves it from the Active Booking List
     * to the Confirmed Bookings. Doesn't touch booking_status at all
     * (Check-in/Check-out still work exactly as before); this is a
     * separate, additive gate.
     *
     * A GCash-paid booking additionally can't be verified here until its
     * payment has itself been verified via Receptionist\PaymentController
     * (see Booking::gcashPaymentNeedsVerification()) - a receptionist must
     * actually review the registered GCash number and receipt first.
     * Cash bookings are unaffected.
     */
    public function verify(Booking $booking): RedirectResponse
    {
        abort_if($booking->verified_at !== null, 422, 'This booking is already verified.');
        abort_unless($booking->booking_status === Booking::STATUS_ACTIVE, 422, 'Only a confirmed booking can be verified.');

        $booking->loadMissing('reservation.payments');
        abort_if(
            $booking->gcashPaymentNeedsVerification(),
            422,
            'This booking\'s GCash payment must be verified before the booking itself can be verified.'
        );

        $booking->update(['verified_at' => now(), 'verified_by' => auth()->id()]);

        $booking->loadMissing(['reservation.guest.user', 'guest.user']);
        Activity::log(
            'Verified booking',
            "Booking #{$booking->id} - {$booking->guest_display_name}",
            $booking
        );

        return back()->with('success', 'Booking verified.');
    }

    /**
     * Reject an already-converted, still-active Booking with required
     * feedback - the Booking-module counterpart of
     * ReservationWorkflowService::reject() (which only covers a
     * pre-conversion Reservation). Lands the booking in this same
     * controller's existing 'rejected' tab (booking_status=cancelled) -
     * no new tab needed. Uses the already-$fillable but previously-unused
     * bookings.rejection_reason column.
     */
    public function reject(Request $request, Booking $booking): RedirectResponse
    {
        abort_unless($booking->booking_status === Booking::STATUS_ACTIVE, 422, 'Only an active, confirmed booking can be rejected.');

        $validated = $request->validate([
            'reason' => 'required|string|max:500',
        ]);

        $previousStatus = $booking->booking_status;

        $idRejected = DB::transaction(function () use ($booking, $validated) {
            $booking->update([
                'booking_status' => Booking::STATUS_CANCELLED, 'rejection_reason' => $validated['reason'],
                'cancelled_at' => now(), 'status_changed_by' => auth()->id(),
            ]);

            $booking->allPayments()
                ->where('payment_status', 'pending')
                ->each(fn ($payment) => $payment->update(['payment_status' => 'failed']));

            // Rejecting the TRANSACTION rejects the guest's discount ID with it - same commit, so there is never a
            // rejected booking still carrying a pending/approved discount.
            return $this->discountIds->cascadeFromTransactionRejection($booking);
        });

        $booking->loadMissing(['reservation.guest.user', 'guest.user']);
        Activity::log(
            'Rejected booking',
            "Booking #{$booking->id} - {$booking->guest_display_name} - previous status: {$previousStatus}, new status: cancelled - {$validated['reason']}",
            $booking
        );

        if ($guest = $booking->account_guest?->user) {
            $this->notifications->notifyBookingRejected($guest, $booking->roomType->name ?? 'your stay', $validated['reason'], $booking->reservation_id ?? $booking->id);
        }

        if ($idRejected) {
            $this->discountIds->notifyRejectedWithTransaction($booking);
        }

        return back()->with('success', 'Booking rejected.');
    }

    /**
     * A booking is archivable once it's reached an end state: completed
     * (still booking_status=confirmed, but verified_at is set - the
     * Confirmed Bookings) or rejected/failed (booking_status=cancelled).
     * An active confirmed-but-not-yet-verified or checked-in/checked-out
     * booking is never archivable from here.
     */
    private function isArchivable(Booking $booking): bool
    {
        $isCompleted = $booking->booking_status === Booking::STATUS_ACTIVE && $booking->verified_at !== null;
        $isCancelled = in_array($booking->booking_status, [Booking::STATUS_CANCELLED, Booking::STATUS_NO_SHOW], true);

        return $isCompleted || $isCancelled;
    }

    /**
     * Archives a completed or rejected/failed booking - removes it from
     * the Confirmed Bookings or "Rejected / Failed" tab into "Archived"
     * without ever hard-deleting anything, same non-destructive pattern as
     * the guest-facing hide() (ReservationWorkflowService::hide()).
     */
    public function archive(Booking $booking): RedirectResponse
    {
        abort_unless($this->isArchivable($booking), 422, 'Only a completed, rejected, or failed booking can be archived.');
        abort_if($booking->hidden_at !== null, 422, 'This booking is already archived.');

        $booking->update(['hidden_at' => now()]);

        $booking->loadMissing(['reservation.guest.user', 'guest.user']);
        Activity::log(
            'Archived booking',
            "Booking #{$booking->id} - {$booking->guest_display_name}",
            $booking
        );

        return back()->with('success', 'Booking archived.');
    }

    /** Bring an archived booking back to its tab - archiving is reversible, nothing is deleted. */
    public function restore(Booking $booking): RedirectResponse
    {
        abort_if($booking->hidden_at === null, 422, 'This booking is not archived.');

        $booking->update(['hidden_at' => null]);
        Activity::log('Restored booking', "Booking #{$booking->id} - {$booking->guest_display_name}", $booking);

        return back()->with('success', 'Booking restored from the archive.');
    }

    /**
     * Mark a confirmed booking whose guest never arrived as a No-Show. Set by
     * hand only (never automatically), on or after the check-in day. The room
     * hold is released (availability only counts confirmed / in-house stays).
     */
    public function markNoShow(Request $request, Booking $booking): RedirectResponse
    {
        abort_unless($booking->booking_status === Booking::STATUS_ACTIVE, 422, 'Only a confirmed booking that has not checked in can be marked as a no-show.');
        abort_if($booking->check_in->startOfDay()->gt(\App\Support\CheckInWindow::date()), 422, 'A booking can only be marked as a no-show on or after its check-in date.');
        $validated = $request->validate(['reason' => 'nullable|string|max:500']);

        $booking->update([
            'booking_status' => Booking::STATUS_NO_SHOW,
            'no_show_at' => now(),
            'status_changed_by' => auth()->id(),
            'rejection_reason' => $validated['reason'] ?? null,
        ]);

        $booking->loadMissing(['reservation.guest.user', 'guest.user', 'roomType']);
        Activity::log('Marked booking as no-show', "Booking #{$booking->id} - {$booking->guest_display_name}" . (! empty($validated['reason']) ? " - {$validated['reason']}" : ''), $booking);
        $this->notifications->notifyBookingNoShow($booking->account_guest?->user, $booking->guest_display_name, $booking->roomType->name ?? 'their stay', $booking->reservation_id ?? $booking->id);

        return back()->with('success', 'Booking marked as a no-show.');
    }

    /** Correct a no-show marked by mistake - back to confirmed, if the room type still has space. */
    public function undoNoShow(Booking $booking): RedirectResponse
    {
        abort_unless($booking->booking_status === Booking::STATUS_NO_SHOW, 422, 'This booking is not marked as a no-show.');

        if ($booking->roomType && $this->availability->availableCount($booking->roomType, $booking->check_in, $booking->check_out, $booking->id) < max(1, (int) $booking->rooms_requested)) {
            return back()->with('error', 'The room type is fully booked for these dates now, so this booking can\'t be restored.');
        }

        $booking->update(['booking_status' => Booking::STATUS_ACTIVE, 'no_show_at' => null, 'rejection_reason' => null, 'status_changed_by' => auth()->id(), 'hidden_at' => null]);
        Activity::log('Undid no-show', "Booking #{$booking->id} - {$booking->guest_display_name} - back to confirmed", $booking);

        return back()->with('success', 'No-show undone - the booking is confirmed again.');
    }

    /**
     * Cancel a confirmed booking (e.g. the guest called to cancel) - the
     * receptionist counterpart of reject() for an already-verified booking.
     * Optional reason; pending payments fail; the reservation it came from is
     * cancelled with it.
     */
    public function cancel(Request $request, Booking $booking): RedirectResponse
    {
        abort_unless($booking->booking_status === Booking::STATUS_ACTIVE, 422, 'Only a confirmed booking that has not checked in can be cancelled.');
        $validated = $request->validate(['reason' => 'nullable|string|max:500']);

        DB::transaction(function () use ($booking, $validated) {
            $booking->update([
                'booking_status' => Booking::STATUS_CANCELLED, 'rejection_reason' => $validated['reason'] ?? null,
                'cancelled_at' => now(), 'status_changed_by' => auth()->id(),
            ]);
            $booking->allPayments()->where('payment_status', 'pending')->each(fn ($payment) => $payment->update(['payment_status' => 'failed']));
            $booking->reservation?->update(['status' => \App\Models\Reservation::STATUS_CANCELLED]);
        });

        $booking->loadMissing(['reservation.guest.user', 'guest.user', 'roomType']);
        Activity::log('Cancelled booking', "Booking #{$booking->id} - {$booking->guest_display_name}" . (! empty($validated['reason']) ? " - {$validated['reason']}" : ''), $booking);
        if ($guest = $booking->account_guest?->user) {
            $this->notifications->notifyBookingCancelled($guest, $booking->roomType->name ?? 'your stay', $booking->reservation_id ?? $booking->id);
        }

        return back()->with('success', 'Booking cancelled.');
    }

    /**
     * Sweeps every still-confirmed, not-yet-verified booking with a GCash
     * deposit for a stuck gating payment (never completed - no number/
     * receipt on file - or already rejected with nothing superseding it)
     * and hands each one to ReservationWorkflowService::reconcileGcashBookingPayment(),
     * which auto-rejects/cancels as needed. Runs on every Bookings-module
     * page load so a booking never has to wait on a receptionist noticing
     * it's stuck - see that method's docblock for the full rationale.
     */
    private function reconcileStuckGcashBookings(): void
    {
        Booking::where('booking_status', Booking::STATUS_ACTIVE)
            ->whereNull('verified_at')
            ->where(function ($q) {
                // Covers both a reservation-derived booking's GCash deposit
                // (reservation.payments) and a direct "New Booking"
                // transaction's own GCash payment (payments, via
                // payments.booking_id directly) - whichever applies.
                $q->whereHas('reservation.payments', fn ($p) => $p->where('payment_method', 'gcash'))
                    ->orWhereHas('payments', fn ($p) => $p->where('payment_method', 'gcash'));
            })
            ->with(['reservation.payments', 'reservation.guest.user', 'guest.user', 'payments', 'roomType'])
            ->get()
            ->each(fn (Booking $booking) => $this->workflow->reconcileGcashBookingPayment($booking));
    }
}



