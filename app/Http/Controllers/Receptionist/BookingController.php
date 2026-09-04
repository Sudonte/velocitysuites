<?php

namespace App\Http\Controllers\Receptionist;

use App\Http\Controllers\Controller;
use App\Models\Booking;
use App\Models\Payment;
use App\Models\RoomType;
use App\Services\NotificationService;
use App\Services\ReservationWorkflowService;
use App\Services\RoomAvailabilityService;
use App\Support\Activity;
use Carbon\Carbon;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
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
            'rejected' => $query->where('booking_status', Booking::STATUS_CANCELLED)->whereNull('hidden_at'),
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
        $bookings = $query->orderByRaw('viewed_at IS NULL DESC')->orderBy('check_in')->simplePaginate(15)->withQueryString();

        $pendingCount = Booking::where('booking_status', Booking::STATUS_ACTIVE)->whereNull('verified_at')->count();
        $verifiedCount = Booking::where('booking_status', Booking::STATUS_ACTIVE)->whereNotNull('verified_at')->whereNull('hidden_at')->count();
        $rejectedCount = Booking::where('booking_status', Booking::STATUS_CANCELLED)->whereNull('hidden_at')->count();

        return view('receptionist.bookings.index', compact(
            'bookings', 'tab', 'pendingCount', 'verifiedCount', 'rejectedCount'
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

        return view('receptionist.bookings.create', compact('roomTypes'));
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
            'room_type_id' => 'required|exists:room_types,id',
            'check_in' => 'required|date|after_or_equal:today',
            'check_out' => 'required|date|after:check_in',
            'rooms_requested' => 'required|integer|min:1|max:50',
            'adults' => 'required|integer|min:1',
            'children' => 'nullable|integer|min:0',
        ]);

        $roomType = RoomType::findOrFail($validated['room_type_id']);
        abort_unless($roomType->status === 'active', 422, 'This room type is not currently available.');

        $available = $this->availability->availableCount(
            $roomType,
            Carbon::parse($validated['check_in']),
            Carbon::parse($validated['check_out'])
        );
        if ($available < $validated['rooms_requested']) {
            return back()->withInput()->with('error', $validated['rooms_requested'] > 1
                ? "Not enough {$roomType->name} rooms available for these dates (needs {$validated['rooms_requested']}, only {$available} free)."
                : "This room type is fully booked for the requested dates.");
        }

        $children = (int) ($validated['children'] ?? 0);

        $booking = Booking::create([
            'reservation_id' => null,
            'guest_id' => null,
            'guest_first_name' => $validated['guest_first_name'],
            'guest_middle_name' => $validated['guest_middle_name'] ?? null,
            'guest_last_name' => $validated['guest_last_name'],
            'room_type_id' => $roomType->id,
            'rooms_requested' => $validated['rooms_requested'],
            'check_in' => $validated['check_in'],
            'check_out' => $validated['check_out'],
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

        Activity::log(
            'Created booking',
            "Booking #{$booking->id} for {$roomType->name} ({$booking->guest_display_name}) - created directly by staff",
            $booking
        );

        return redirect()
            ->route('receptionist.bookings.show', $booking)
            ->with('success', 'Booking created for ' . $booking->guest_display_name . '. Record their payment whenever they pay, and check them in from the Check-in Module when they arrive.');
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

        // Authoritative Total/Paid/Remaining for the "Payment & Balance"
        // card below - same total_amount_due accessor the guest-facing API
        // already exposes (Api\BookingController::index()/show()), so the
        // receptionist and guest always see the identical figure, whether
        // or not a Billing row exists yet (it doesn't until checkout - see
        // CheckOutController::generateBilling()).
        $totalDue = $booking->total_amount_due;
        $amountPaid = (float) $booking->allPayments()->where('payment_status', 'completed')->sum('amount_paid');
        $remainingBalance = max(0, round($totalDue - $amountPaid, 2));

        return view('receptionist.bookings.show', compact('booking', 'totalDue', 'amountPaid', 'remainingBalance'));
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
    public function recordPayment(Request $request, Booking $booking): RedirectResponse
    {
        if (!in_array($booking->booking_status, [Booking::STATUS_ACTIVE, Booking::STATUS_CHECKED_IN], true)) {
            return back()->with('error', 'Only an active (confirmed or checked-in) booking can have a payment recorded.');
        }

        // Walk-in (unverified, staff-declared-complete) recording only ever
        // makes sense for Cash - a GCash booking's payments must go through
        // the receipt+number submission and verification flow above
        // (Booking::gcashPaymentNeedsVerification()'s gate), never a
        // receptionist just declaring an amount received with no evidence.
        if ($booking->payment_method !== 'cash') {
            return back()->with('error', 'Only a Cash booking can have a walk-in payment recorded here.');
        }

        $booking->loadMissing(['reservation.payments', 'payments']);
        $totalDue = $booking->total_amount_due;
        $amountPaid = (float) $booking->allPayments()->where('payment_status', 'completed')->sum('amount_paid');
        $remainingBalance = max(0, round($totalDue - $amountPaid, 2));

        $validated = $request->validate([
            'amount_paid' => ['required', 'numeric', 'min:0.01', 'max:' . max(0.01, $remainingBalance)],
        ], [
            'amount_paid.max' => "The amount cannot exceed the remaining balance (₱{$remainingBalance}).",
        ]);

        DB::transaction(function () use ($booking, $validated) {
            $paymentData = [
                'payment_method' => 'cash',
                'amount_paid' => $validated['amount_paid'],
                'payment_stage' => 'deposit',
                'payment_status' => 'completed',
                'payment_date' => now(),
                'verified_by' => auth()->id(),
                'verified_at' => now(),
            ];
            if ($booking->reservation_id) {
                $paymentData['reservation_id'] = $booking->reservation_id;
            } else {
                $paymentData['booking_id'] = $booking->id;
            }
            Payment::create($paymentData);
        });

        $newRemaining = max(0, round($remainingBalance - (float) $validated['amount_paid'], 2));

        $booking->loadMissing(['reservation.guest.user', 'guest.user']);
        Activity::log(
            'Recorded walk-in payment',
            "Booking #{$booking->id} - {$booking->guest_display_name} - ₱"
                . number_format((float) $validated['amount_paid'], 2) . " (cash) - remaining balance now ₱" . number_format($newRemaining, 2),
            $booking
        );

        if ($guest = $booking->account_guest?->user) {
            $this->notifications->notifyPaymentReceived($guest, (float) $validated['amount_paid'], $booking->roomType->name ?? null, $booking->reservation_id ?? $booking->id);
        }

        return back()->with('success', 'Payment recorded. Remaining balance: ₱' . number_format($newRemaining, 2) . '.');
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

        DB::transaction(function () use ($booking, $validated) {
            $booking->update(['booking_status' => Booking::STATUS_CANCELLED, 'rejection_reason' => $validated['reason']]);

            $booking->allPayments()
                ->where('payment_status', 'pending')
                ->each(fn ($payment) => $payment->update(['payment_status' => 'failed']));
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
        $isCancelled = $booking->booking_status === Booking::STATUS_CANCELLED;

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

    /**
     * Soft-deletes a completed or rejected/failed booking - never a hard
     * DELETE (see the deleted_at migration and every other "No hard
     * delete" comment in routes/web.php); the row and its reservation/
     * payment history stay intact for accounting/audit, just excluded
     * from every default query (including this controller's own tabs)
     * from here on. Works whether or not the booking was archived first -
     * in practice this is only ever offered from the Archived List, per
     * isArchivable()'s same end-state gate.
     */
    public function destroy(Booking $booking): RedirectResponse
    {
        abort_unless($this->isArchivable($booking), 422, 'Only a completed, rejected, or failed booking can be deleted.');

        $booking->loadMissing(['reservation.guest.user', 'guest.user']);
        Activity::log(
            'Deleted booking',
            "Booking #{$booking->id} - {$booking->guest_display_name}",
            $booking
        );

        $booking->delete();

        return back()->with('success', 'Booking deleted.');
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



