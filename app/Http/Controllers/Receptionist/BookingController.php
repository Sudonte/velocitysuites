<?php

namespace App\Http\Controllers\Receptionist;

use App\Http\Controllers\Controller;
use App\Models\ActivityLog;
use App\Models\Booking;
use App\Models\Payment;
use App\Services\NotificationService;
use App\Services\ReservationWorkflowService;
use App\Services\RoomAvailabilityService;
use App\Support\Activity;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\View\View;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

/**
 * The Booking Module: a view-only registry of bookings still awaiting
 * check-in (booking_status = confirmed). Same single-stage-at-a-time rule
 * as every other module - once a guest checks in, the record disappears
 * from here and shows up in the Check-in Module's "Checked-in Guests" tab
 * (and later the Check-out Module) instead, not here as well. Room
 * assignment and check-in live in the Check-in Module.
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
        private RoomAvailabilityService $availability,
        private NotificationService $notifications,
    ) {
    }

    /**
     * Active Booking List ('pending', verified_at null) by default; other
     * tabs: 'verified' (Complete Booking List - verified but not yet
     * archived), 'rejected' (cancelled, not archived - needs a
     * receptionist decision), 'archived' (verified OR cancelled, archived
     * - see isArchivable()). ?verified=1 is kept working as an alias for
     * tab=verified for any old bookmarked links.
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
            'pending' => $query->where('booking_status', 'confirmed')->whereNull('verified_at'),
            'verified' => $query->where('booking_status', 'confirmed')->whereNotNull('verified_at')->whereNull('hidden_at'),
            'rejected' => $query->where('booking_status', 'cancelled')->whereNull('hidden_at'),
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

        // Closest check-in date first - whoever is arriving soonest is the
        // receptionist's priority, not whoever was most recently confirmed.
        $bookings = $query->orderBy('check_in')->paginate(15)->withQueryString();

        $pendingCount = Booking::where('booking_status', 'confirmed')->whereNull('verified_at')->count();
        $verifiedCount = Booking::where('booking_status', 'confirmed')->whereNotNull('verified_at')->whereNull('hidden_at')->count();
        $rejectedCount = Booking::where('booking_status', 'cancelled')->whereNull('hidden_at')->count();
        $archivedCount = Booking::whereNotNull('hidden_at')->count();

        return view('receptionist.bookings.index', compact(
            'bookings', 'tab', 'pendingCount', 'verifiedCount', 'rejectedCount', 'archivedCount'
        ));
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

        // Transaction history: every logged action against this booking
        // (confirmed, room assignment, verification, etc.), newest first -
        // the receptionist's record that a verification (or any other
        // action) actually happened and who did it.
        $history = ActivityLog::with('user')
            ->where('subject_type', 'booking')
            ->where('subject_id', $booking->id)
            ->orderByDesc('created_at')
            ->get();

        // Authoritative Total/Paid/Remaining for the "Payment & Balance"
        // card below - same total_amount_due accessor the guest-facing API
        // already exposes (Api\BookingController::index()/show()), so the
        // receptionist and guest always see the identical figure, whether
        // or not a Billing row exists yet (it doesn't until checkout - see
        // CheckOutController::generateBilling()).
        $totalDue = $booking->total_amount_due;
        $amountPaid = (float) $booking->allPayments()->where('payment_status', 'completed')->sum('amount_paid');
        $remainingBalance = max(0, round($totalDue - $amountPaid, 2));

        return view('receptionist.bookings.show', compact('booking', 'history', 'totalDue', 'amountPaid', 'remainingBalance'));
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
        if (!in_array($booking->booking_status, ['confirmed', 'checked_in'], true)) {
            return back()->with('error', 'Only an active (confirmed or checked-in) booking can have a payment recorded.');
        }

        $booking->loadMissing(['reservation.payments', 'payments']);
        $totalDue = $booking->total_amount_due;
        $amountPaid = (float) $booking->allPayments()->where('payment_status', 'completed')->sum('amount_paid');
        $remainingBalance = max(0, round($totalDue - $amountPaid, 2));

        $validated = $request->validate([
            'payment_method' => 'required|in:cash,gcash',
            'amount_paid' => ['required', 'numeric', 'min:0.01', 'max:' . max(0.01, $remainingBalance)],
        ], [
            'amount_paid.max' => "The amount cannot exceed the remaining balance (₱{$remainingBalance}).",
        ]);

        DB::transaction(function () use ($booking, $validated) {
            $paymentData = [
                'payment_method' => $validated['payment_method'],
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
            "Booking #{$booking->id} - {$booking->account_guest_full_name} - ₱"
                . number_format((float) $validated['amount_paid'], 2) . " ({$validated['payment_method']}) - remaining balance now ₱" . number_format($newRemaining, 2),
            $booking
        );

        if ($guest = $booking->account_guest?->user) {
            $this->notifications->notifyPaymentReceived($guest, (float) $validated['amount_paid'], $booking->roomType->name ?? null, $booking->reservation_id ?? $booking->id);
        }

        return back()->with('success', 'Payment recorded. Remaining balance: ₱' . number_format($newRemaining, 2) . '.');
    }

    /**
     * AJAX popup content: the room-assignment picker, pre-filtered to
     * rooms that match the booked room type and are free for the stay
     * (RoomAvailabilityService::assignableRooms(), which now also blocks
     * against other confirmed-but-not-yet-checked-in bookings, not just
     * checked-in ones - see that method's docblock). Lets a receptionist
     * assign the guest's room(s) any time after confirmation, ahead of
     * actual arrival - Receptionist\CheckInController's own panel still
     * exists for walk-up check-in and will show whatever was assigned
     * here as a pre-filled confirmation instead of a blank pick.
     */
    public function assignRoomsPanel(Booking $booking): View
    {
        abort_unless($booking->booking_status === 'confirmed', 422, 'Only a confirmed booking can have rooms assigned.');

        $booking->load(['reservation.guest.user', 'guest.user', 'roomType', 'rooms']);
        $assignableRooms = $this->availability->assignableRooms($booking);
        $assignedRoomIds = $booking->rooms->pluck('id')->all();

        return view('receptionist.bookings.partials.assign-rooms', compact('booking', 'assignableRooms', 'assignedRoomIds'));
    }

    /**
     * Assigns (or reassigns) the booking's room(s) without touching
     * booking_status or flipping room status to occupied - those only
     * happen at actual check-in (Receptionist\CheckInController::store()).
     * The guest's original room TYPE and rooms_requested count are never
     * changed here, only which specific physical room(s) fill that
     * request. Delegates validation/assignment to the same
     * RoomAvailabilityService::assignRooms() the Check-in Module uses, so
     * a stale/concurrent pick can't double-book a room here either.
     */
    public function assignRooms(Request $request, Booking $booking)
    {
        if ($booking->booking_status !== 'confirmed') {
            return response()->json(['message' => 'Only a confirmed booking can have rooms assigned.'], 422);
        }

        $validated = $request->validate([
            'room_ids' => 'required|array|size:' . $booking->rooms_requested,
            'room_ids.*' => 'required|integer|distinct',
        ], [
            'room_ids.size' => 'This booking needs exactly ' . $booking->rooms_requested . ' room(s) assigned - select ' . $booking->rooms_requested . '.',
            'room_ids.*.distinct' => 'The same room was selected more than once.',
        ]);

        try {
            $rooms = $this->availability->assignRooms($booking, $validated['room_ids']);
        } catch (HttpExceptionInterface $e) {
            return response()->json(['message' => $e->getMessage()], $e->getStatusCode());
        }

        $booking->loadMissing(['reservation.guest.user', 'guest.user']);
        Activity::log(
            'Assigned room(s)',
            "Booking #{$booking->id} - {$booking->account_guest_full_name} to " . $rooms->pluck('room_number')->implode(', '),
            $booking
        );

        $roomLabel = $rooms->count() > 1 ? 'Rooms ' . $rooms->pluck('room_number')->implode(', ') : 'Room ' . $rooms->first()->room_number;

        return response()->json(['message' => $roomLabel . ' assigned to this booking.']);
    }

    /**
     * Marks a booking Verified - moves it from the Active Booking List
     * to the Complete Booking List. Doesn't touch booking_status at all
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
        abort_unless($booking->booking_status === 'confirmed', 422, 'Only a confirmed booking can be verified.');

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
            "Booking #{$booking->id} - {$booking->account_guest_full_name}",
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
        abort_unless($booking->booking_status === 'confirmed', 422, 'Only an active, confirmed booking can be rejected.');

        $validated = $request->validate([
            'reason' => 'required|string|max:500',
        ]);

        $previousStatus = $booking->booking_status;

        DB::transaction(function () use ($booking, $validated) {
            $booking->update(['booking_status' => 'cancelled', 'rejection_reason' => $validated['reason']]);

            $booking->allPayments()
                ->where('payment_status', 'pending')
                ->each(fn ($payment) => $payment->update(['payment_status' => 'failed']));
        });

        $booking->loadMissing(['reservation.guest.user', 'guest.user']);
        Activity::log(
            'Rejected booking',
            "Booking #{$booking->id} - {$booking->account_guest_full_name} - previous status: {$previousStatus}, new status: cancelled - {$validated['reason']}",
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
     * Complete Booking List) or rejected/failed (booking_status=cancelled).
     * An active confirmed-but-not-yet-verified or checked-in/checked-out
     * booking is never archivable from here.
     */
    private function isArchivable(Booking $booking): bool
    {
        $isCompleted = $booking->booking_status === 'confirmed' && $booking->verified_at !== null;
        $isCancelled = $booking->booking_status === 'cancelled';

        return $isCompleted || $isCancelled;
    }

    /**
     * Archives a completed or rejected/failed booking - removes it from
     * the Complete Booking List or "Rejected / Failed" tab into "Archived"
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
            "Booking #{$booking->id} - {$booking->account_guest_full_name}",
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
            "Booking #{$booking->id} - {$booking->account_guest_full_name}",
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
        Booking::where('booking_status', 'confirmed')
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



