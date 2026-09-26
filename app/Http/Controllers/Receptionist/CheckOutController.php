<?php

namespace App\Http\Controllers\Receptionist;

use App\Http\Controllers\Controller;
use App\Models\AdditionalCharge;
use App\Models\AmenityRequest;
use App\Models\Billing;
use App\Models\Booking;
use App\Models\Discount;
use App\Models\Payment;
use App\Services\NotificationService;
use App\Support\Activity;
use Illuminate\Database\QueryException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\View\View;

/**
 * The Check-out Module: two tabs - "Expected Check-outs" (checked-in
 * guests, where check-out starts the Billing/Payment flow) and
 * "Checked-out Guests" (view-only history). Billing and Payment are steps
 * inside check-out (AJAX modals), not standalone pages.
 */
class CheckOutController extends Controller
{
    public function __construct(private NotificationService $notificationService)
    {
    }

    public function index(Request $request): View
    {
        $tab = $request->get('tab', 'expected');
        if (!in_array($tab, ['expected', 'checked_out'])) {
            $tab = 'expected';
        }

        // Unread first (viewed_at null - see checkOutBilling() below,
        // shared with Bookings/Check-in since all three operate on this
        // same Booking row), then the existing date order. simplePaginate
        // (Previous/Next only, no numbered page links) - same fix as
        // Check-in/Bookings/Reservations' index(): the numbered page-link
        // boxes render broken/oversized here for reasons that don't trace
        // back to anything in this app's own CSS.
        $bookings = Booking::with(['reservation.guest.user', 'guest.user', 'rooms', 'roomType', 'billing'])
            ->where('booking_status', $tab === 'expected' ? Booking::STATUS_CHECKED_IN : Booking::STATUS_COMPLETED)
            ->orderByRaw('viewed_at IS NULL DESC')
            ->orderBy('check_out', $tab === 'expected' ? 'asc' : 'desc')
            ->simplePaginate(15)
            ->withQueryString();

        $expectedCount = Booking::where('booking_status', Booking::STATUS_CHECKED_IN)->count();
        $checkedOutCount = Booking::where('booking_status', Booking::STATUS_COMPLETED)->count();

        return view('receptionist.check-out.index', compact('bookings', 'tab', 'expectedCount', 'checkedOutCount'));
    }

    /**
     * Open (or resume) the Billing Panel for a check-out in progress.
     * Creates a draft billing if one doesn't exist yet; does not change
     * booking or room status.
     */
    public function checkOutBilling(Booking $booking)
    {
        if ($booking->booking_status !== Booking::STATUS_CHECKED_IN) {
            return response()->json(['message' => 'Only checked-in bookings can be billed.'], 422);
        }

        $booking->load(['reservation.guest.user', 'guest.user', 'rooms']);
        $billing = $booking->billing ?? $this->firstOrGenerateBilling($booking);

        // Recomputed on every open, not just once at generateBilling()'s
        // initial creation - an amenity added mid-stay (Receptionist\
        // ReceptionistController::amenitiesStore()) or a headcount change
        // must actually reach the bill, not just show in the itemized list
        // below. Leaves room_charge/discount alone - those are locked in
        // at creation and only change via applyDiscount() itself.
        $this->refreshStayCharges($booking, $billing);

        $billing->load(['additionalCharges', 'discountApplied']);

        // First open marks it read - see index()'s ordering / the
        // red-dot indicator in the view, both keyed off viewed_at. Shared
        // across Bookings/Check-in/Check-out (all three operate on this
        // same Booking row), not just this module.
        if (! $booking->viewed_at) {
            $booking->update(['viewed_at' => now()]);
        }

        $amenityRequests = AmenityRequest::with('amenity')
            ->where($booking->reservation_id ? 'reservation_id' : 'booking_id', $booking->reservation_id ?? $booking->id)
            ->where('status', 'approved')
            ->get();

        $discounts = Discount::where('status', 'active')->orderBy('name')->get();

        return view('receptionist.check-out.partials.billing-panel', compact('booking', 'billing', 'amenityRequests', 'discounts'));
    }

    /**
     * Discard a draft billing (no payments recorded yet) started from Check-Out.
     */
    public function checkOutCancelBilling(Billing $billing)
    {
        if ($billing->billing_status === 'paid') {
            return response()->json(['message' => 'Cannot cancel a paid bill.'], 422);
        }

        if ($billing->payments()->where('payment_status', 'completed')->exists()) {
            return response()->json(['message' => 'This bill already has recorded payments and cannot be discarded.'], 422);
        }

        $billing->delete();

        return response()->json(['ok' => true]);
    }

    /**
     * Open the Payment Panel for a locked billing.
     *
     * Grand Total / Total Amount Paid / Remaining Balance / Payment Status
     * come from Booking::paymentSummary() (ReceiptService - the same
     * authoritative source the guest-facing API and the Payment
     * Transaction History below both read) rather than this controller
     * computing its own $billing->balance/completed-payments-sum
     * aggregate a second, independent way - see
     * PAYMENT_RECEIPT_HISTORY_BACKEND_SPEC.md §17 ("do not calculate
     * critical payment data differently" across surfaces). $balance is
     * kept as its own variable only because payment-panel.blade.php's
     * existing amount-input/change-due JS already reads it via a
     * data-balance attribute - same value, just still exposed under its
     * pre-existing name so that JS doesn't need to change.
     */
    public function checkOutPaymentPanel(Billing $billing)
    {
        $billing->load(['booking.reservation.guest.user', 'booking.rooms', 'payments', 'additionalCharges', 'discountApplied']);

        $booking = $billing->booking;
        $paymentSummary = $booking->paymentSummary();
        $paymentTransactions = $booking->paymentTransactionsPayload();
        $balance = $paymentSummary['remaining_balance'];

        return view('receptionist.check-out.partials.payment-panel', compact(
            'billing', 'booking', 'balance', 'paymentSummary', 'paymentTransactions'
        ));
    }

    /**
     * Record a payment against a billing from the Payment Panel. Completes the
     * check-out (reservation + room status, notifications) only once the
     * balance reaches zero; a partial payment leaves the guest checked in.
     *
     * amount_paid may be 0 - that's the "already fully paid before
     * checkout" case (e.g. a 100%-tier reservation converted with its
     * Grand Total already covered by a receptionist-verified payment):
     * the Payment Panel still has to be able to finalize the check-out
     * (transition booking_status to STATUS_COMPLETED) even though there's
     * nothing left to collect. A 0 submission is only ever accepted when
     * the billing's balance is already covered by prior completed
     * payments - it must never be a way to skip a genuine outstanding
     * balance, so that's checked explicitly before anything else runs.
     *
     * CONCURRENCY: the route-model-bound $billing/its ->booking are only
     * used to resolve which row to lock - every decision below (is this
     * still awaiting checkout, what's the real remaining balance, does a
     * new Payment need creating) is made from a FRESH copy re-read INSIDE
     * the transaction while holding lockForUpdate() on both the Billing
     * and Booking rows. Two simultaneous requests for the same billing
     * both attempt this lock; the database itself serializes them (the
     * second blocks until the first's transaction commits or rolls back),
     * and the second then sees the first's already-committed state (no
     * longer STATUS_CHECKED_IN, or balance already covered) and exits
     * with no writes at all - never a second checkout Payment row, never
     * a second booking_status transition, never a second OR (see
     * Billing::ensureOfficialReceiptNumber()'s own lock-and-recheck for
     * why that part was already safe even before this fix). A plain
     * status re-check without a lock would not be enough - two requests
     * could both pass that check before either commits; the lock is what
     * actually prevents that.
     */
    public function recordPayment(Request $request, Billing $billing)
    {
        $validated = $request->validate([
            'payment_method' => 'required|in:cash,gcash',
            'reference_number' => 'required_if:payment_method,gcash|nullable|string|max:255',
            'amount_paid' => 'required|numeric|min:0',
        ]);

        $result = DB::transaction(function () use ($validated, $billing) {
            // Re-fetch WITH a row lock - the $billing the route model binder
            // handed in was read before this transaction started and before
            // any lock was held, so it may already be stale by the time we
            // get here (a concurrent request could have committed in between).
            $lockedBilling = Billing::whereKey($billing->id)->lockForUpdate()->first();
            if (!$lockedBilling) {
                return ['status' => 'not_found'];
            }

            $lockedBooking = Booking::whereKey($lockedBilling->booking_id)->lockForUpdate()->first();
            if (!$lockedBooking || $lockedBooking->booking_status !== Booking::STATUS_CHECKED_IN) {
                // Authoritative re-check while holding the lock - this is what
                // actually catches a concurrent duplicate submit (or a request
                // that arrived after checkout already completed), not just a
                // sequential double-click. No write has happened yet, so this
                // exits cleanly with nothing to roll back.
                return ['status' => 'not_awaiting_checkout'];
            }

            // Re-derive the balance from the LOCKED billing's own fresh
            // payment sum - never the pre-transaction $billing->balance,
            // which could be stale relative to a payment another request
            // just committed.
            $currentPaid = (float) $lockedBilling->payments()->where('payment_status', 'completed')->sum('amount_paid');
            $currentBalance = max(0, (float) $lockedBilling->total_amount - $currentPaid);

            $amountPaid = (float) $validated['amount_paid'];
            if ($amountPaid <= 0 && $currentBalance > 0.009) {
                return ['status' => 'amount_required', 'balance' => $currentBalance];
            }

            if ($amountPaid > 0) {
                $referenceNumber = $validated['reference_number'] ?? null;
                if (empty($referenceNumber)) {
                    $referenceNumber = 'PAY-' . strtoupper(Str::random(10));
                }

                Payment::create([
                    'billing_id' => $lockedBilling->id,
                    'payment_method' => $validated['payment_method'],
                    'reference_number' => $referenceNumber,
                    'amount_paid' => $amountPaid,
                    'payment_status' => 'completed',
                    'payment_stage' => 'final',
                    'payment_date' => now(),
                ]);
            }

            $paid = (float) $lockedBilling->payments()
                ->where('payment_status', 'completed')
                ->sum('amount_paid');

            $completed = $paid >= (float) $lockedBilling->total_amount;
            $lockedBilling->update(['billing_status' => $completed ? 'paid' : 'partial']);

            $guest = $lockedBooking->account_guest?->user;
            $rooms = $lockedBooking->rooms->isNotEmpty() ? $lockedBooking->rooms : collect([$lockedBooking->room])->filter();
            $roomName = $rooms->pluck('room_name')->implode(', ');

            if ($amountPaid > 0) {
                if ($guest) {
                    $this->notificationService->notifyPaymentReceived(
                        $guest,
                        $amountPaid,
                        $roomName,
                        $lockedBooking->reservation_id ?? $lockedBooking->id
                    );
                }

                Activity::log(
                    'Recorded payment',
                    "Booking #{$lockedBooking->id} - ₱" . number_format($amountPaid, 2) . " ({$validated['payment_method']}) from " . ($guest->full_name ?? $lockedBooking->stay_guest_full_name ?? 'guest'),
                    $lockedBooking
                );
            }

            $officialReceiptNumber = null;
            if ($completed) {
                $lockedBooking->update(['booking_status' => Booking::STATUS_COMPLETED]);
                foreach ($rooms as $room) {
                    $room->update(['status' => 'available']);
                }

                // Mint the Official Payment Receipt number now, inside the
                // same transaction that just settled the balance, so it's
                // already on file the moment the guest-facing notification
                // below fires - see Billing::ensureOfficialReceiptNumber().
                // This is also the first moment this booking's
                // billing_status is actually 'paid' - the one rule that
                // gates the Official Receipt (PAYMENT_RECEIPT_HISTORY_BACKEND_SPEC.md §20).
                // Still idempotent on its own (lock-and-recheck) even though
                // this whole method can now only ever reach here once per
                // booking - defense in depth, not load-bearing anymore.
                $officialReceiptNumber = $lockedBilling->ensureOfficialReceiptNumber();

                if ($guest) {
                    $this->notificationService->notifyCheckOut($guest, $roomName, $lockedBooking->reservation_id ?? $lockedBooking->id);
                    $this->notificationService->notifyPaymentComplete($guest, $lockedBooking->reservation_id ?? $lockedBooking->id, $officialReceiptNumber);
                }

                Activity::log(
                    'Checked out guest',
                    "Booking #{$lockedBooking->id} - " . ($guest->full_name ?? $lockedBooking->stay_guest_full_name ?? 'guest') . " from {$roomName}",
                    $lockedBooking
                );
            } else {
                if ($guest) {
                    $this->notificationService->notifyManagerPayment(
                        $guest,
                        $amountPaid,
                        $lockedBilling->billing_status,
                        $roomName,
                        $lockedBooking->reservation_id ?? $lockedBooking->id
                    );
                }
            }

            return [
                'status' => 'ok',
                'completed' => $completed,
                'balance' => max(0, (float) $lockedBilling->total_amount - $paid),
                'billing' => $lockedBilling,
            ];
        });

        if ($result['status'] === 'not_found') {
            return response()->json(['message' => 'Billing not found.'], 404);
        }
        if ($result['status'] === 'not_awaiting_checkout') {
            return response()->json(['message' => 'This booking is not awaiting checkout.'], 422);
        }
        if ($result['status'] === 'amount_required') {
            return response()->json([
                'message' => 'A payment amount is required - the remaining balance is ₱' . number_format($result['balance'], 2) . '.',
            ], 422);
        }

        $lockedBilling = $result['billing'];

        return response()->json([
            'completed' => $result['completed'],
            'balance' => $result['balance'],
            'message' => $result['completed'] ? 'Payment complete. Guest checked out.' : 'Partial payment recorded.',
            'receipt_url' => $result['completed'] ? route('receptionist.billing.receipt', $lockedBilling) : null,
            'official_receipt_number' => $result['completed'] ? $lockedBilling->receipt_number : null,
        ]);
    }

    /**
     * Apply a manually-selected, ID-verified authorized discount (Senior
     * Citizen, PWD, Student, etc.) to a draft billing. Only reachable when
     * the guest actually requested one at reservation time - a receptionist
     * never picks a discount out of thin air. Fully separate from
     * Promotions: this only ever touches Discount/billings.discount_id,
     * never a Promotion record.
     */
    public function applyDiscount(Request $request, Billing $billing)
    {
        if ($billing->billing_status === 'paid') {
            return response()->json(['message' => 'Cannot change the discount on a paid bill.'], 422);
        }

        // A reservation-derived booking's discount request lives on its
        // Reservation; a direct "New Booking" transaction (no reservation
        // at all) carries the exact same fields directly on the Booking
        // itself instead (see the discount_requested/discount_verification_status
        // migration mirroring reservations' equivalent columns).
        $booking = $billing->booking;
        $discountTarget = $booking->reservation ?? $booking;
        if (!$discountTarget->discount_requested) {
            return response()->json(['message' => 'This guest did not request a discount.'], 422);
        }

        $validated = $request->validate([
            'discount_id' => 'required|exists:discounts,id',
        ]);

        $discount = Discount::where('status', 'active')->findOrFail($validated['discount_id']);

        DB::transaction(function () use ($billing, $discount, $discountTarget) {
            $subtotal = (float) $billing->room_charge
                + (float) $billing->additional_guest_fee
                + (float) $billing->amenity_charge
                + $billing->additional_charges_total;

            $amount = $discount->discount_type === 'percentage'
                ? $subtotal * ((float) $discount->value / 100)
                : (float) $discount->value;

            $billing->update([
                'discount' => round(min($amount, $subtotal), 2),
                'discount_id' => $discount->id,
                'discount_verified_by' => auth()->id(),
                'discount_verified_at' => now(),
            ]);
            $billing->recalculateTotal();

            if ($discountTarget->discount_verification_status !== 'approved') {
                $discountTarget->update(['discount_verification_status' => 'approved']);
            }
        });

        $billing->refresh()->load('discountApplied');
        $discounts = Discount::where('status', 'active')->orderBy('name')->get();

        return response()->json([
            'html' => view('receptionist.check-out.partials.discount-panel', compact('billing', 'discounts'))->render(),
            'running_total' => $billing->running_total,
        ]);
    }

    /**
     * Store a new additional charge for a billing (Billing Panel, AJAX).
     */
    public function storeAdditionalCharge(Request $request, Billing $billing)
    {
        $validated = $request->validate([
            'description' => 'required|string|max:255',
            'amount' => 'required|numeric|min:0.01',
            'category' => 'required|in:damage,lost_item,broken_equipment,mini_bar,laundry,other',
            'notes' => 'nullable|string|max:1000',
        ]);

        if ($billing->billing_status === 'paid') {
            return response()->json(['message' => 'Cannot add charges to a paid bill.'], 422);
        }

        DB::transaction(function () use ($billing, $validated) {
            AdditionalCharge::create([
                'billing_id' => $billing->id,
                'description' => $validated['description'],
                'amount' => $validated['amount'],
                'category' => $validated['category'],
                'notes' => $validated['notes'] ?? null,
            ]);

            $billing->recalculateTotal();
        });

        // account_guest is an accessor (Booking::getAccountGuestAttribute()),
        // not a real relation, so it can't be dot-loaded via loadMissing() -
        // eager-load both branches it reads from instead.
        $billing->refresh()->loadMissing(['booking.reservation.guest.user', 'booking.guest.user']);
        Activity::log(
            'Recorded additional charge',
            "Billing #{$billing->id} - {$validated['description']} (₱" . number_format((float) $validated['amount'], 2) . ')',
            $billing->booking ?? $billing
        );
        if ($guest = $billing->booking?->account_guest?->user) {
            $this->notificationService->notifyAdditionalCharge(
                $guest,
                $validated['description'],
                (float) $validated['amount'],
                $billing->balance,
                $billing->booking->id
            );
        }

        return $this->chargesTableResponse($billing);
    }

    /**
     * Update an existing additional charge (Billing Panel, AJAX).
     */
    public function updateAdditionalCharge(Request $request, AdditionalCharge $additionalCharge)
    {
        $validated = $request->validate([
            'description' => 'required|string|max:255',
            'amount' => 'required|numeric|min:0.01',
            'category' => 'required|in:damage,lost_item,broken_equipment,mini_bar,laundry,other',
            'notes' => 'nullable|string|max:1000',
        ]);

        $billing = $additionalCharge->billing;

        if ($billing->billing_status === 'paid') {
            return response()->json(['message' => 'Cannot edit charges on a paid bill.'], 422);
        }

        DB::transaction(function () use ($additionalCharge, $validated, $billing) {
            $additionalCharge->update([
                'description' => $validated['description'],
                'amount' => $validated['amount'],
                'category' => $validated['category'],
                'notes' => $validated['notes'] ?? null,
            ]);

            $billing->recalculateTotal();
        });

        Activity::log(
            'Updated additional charge',
            "Billing #{$billing->id} - {$validated['description']} (₱" . number_format((float) $validated['amount'], 2) . ')',
            $billing->booking ?? $billing
        );

        return $this->chargesTableResponse($billing);
    }

    /**
     * Remove an additional charge (Billing Panel, AJAX).
     */
    public function destroyAdditionalCharge(AdditionalCharge $additionalCharge)
    {
        $billing = $additionalCharge->billing;

        if ($billing->billing_status === 'paid') {
            return response()->json(['message' => 'Cannot remove charges from a paid bill.'], 422);
        }

        $description = $additionalCharge->description;
        $amount = (float) $additionalCharge->amount;

        DB::transaction(function () use ($additionalCharge, $billing) {
            $additionalCharge->delete();

            $billing->recalculateTotal();
        });

        Activity::log(
            'Removed additional charge',
            "Billing #{$billing->id} - {$description} (₱" . number_format($amount, 2) . ')',
            $billing->booking ?? $billing
        );

        return $this->chargesTableResponse($billing);
    }

    /**
     * Refreshes additional_guest_fee/amenity_charge from the booking's
     * current headcount/rooms and approved amenity requests - same
     * calculation generateBilling() uses at creation, just re-run every
     * time the panel opens so anything added mid-stay actually reaches
     * the bill (see checkOutBilling()). room_charge/discount are
     * deliberately left alone - those are locked in at creation and only
     * change via applyDiscount() itself.
     *
     * Also re-parents any completed payment still missing a billing_id
     * (Receptionist\BookingController::recordPayment() records a walk-in
     * Cash payment directly against the reservation/booking, never
     * against a Billing - previously only generateBilling()'s one-time
     * creation ever did this re-parenting, so a payment recorded after
     * the Billing already existed stayed permanently invisible to
     * Billing::getBalanceAttribute(), understating the balance forever)
     * and recomputes billing_status from the resulting total, so a
     * mid-stay payment actually moves the bill from pending to
     * partial/paid instead of only ever happening once at creation.
     */
    private function refreshStayCharges(Booking $booking, Billing $billing): void
    {
        $rooms = $booking->rooms->isNotEmpty() ? $booking->rooms : collect([$booking->room])->filter();
        $adults = $booking->adults ?? $booking->number_of_guests;
        $totalCapacity = $rooms->sum('room_capacity');
        $extraGuests = max(0, $adults - $totalCapacity);
        $extraGuestFee = $extraGuests * (float) config('hotel.extra_guest_fee_rate', 0);

        $amenityCharge = (float) AmenityRequest::where(function ($q) use ($booking) {
                if ($booking->reservation_id) {
                    $q->where('reservation_id', $booking->reservation_id);
                } else {
                    $q->where('booking_id', $booking->id);
                }
            })
            ->where('status', 'approved')
            ->sum(DB::raw('charge * quantity'));

        $billing->update([
            'additional_guest_fee' => round($extraGuestFee, 2),
            'amenity_charge' => round($amenityCharge, 2),
        ]);
        $billing->recalculateTotal();

        // Reservation-derived: re-parent every completed, still-unparented
        // payment regardless of stage. A prior version of this only
        // reparented 'deposit'-stage payments, on the mistaken assumption
        // that a 'final'-stage one (a Pay-Now-Full reservation, or a Cash
        // reservation confirmed in full at conversion time - see
        // ReservationWorkflowService::convertToBooking()'s own "Not stage-
        // filtered" comment) was "already re-parented at conversion time" -
        // conversion only ever marks that payment payment_status=completed,
        // it never touches billing_id, since no Billing exists yet at
        // conversion time (one is only ever created later, here, at
        // check-in/checkout). The result was a real, live bug: a guest who
        // paid their full reservation total upfront (GCash Pay-Now-Full, or
        // a Cash reservation confirmed in full) had that entire payment
        // silently excluded from every checkout balance calculation below,
        // making checkout believe the full original amount was still owed
        // all over again. Direct booking (else branch): unaffected either
        // way, already unconditional.
        if ($booking->reservation_id) {
            $booking->reservation->payments()
                ->where('payment_status', 'completed')
                ->whereNull('billing_id')
                ->update(['billing_id' => $billing->id]);
        } else {
            $booking->payments()
                ->where('payment_status', 'completed')
                ->whereNull('billing_id')
                ->update(['billing_id' => $billing->id]);
        }

        $paid = (float) $billing->payments()->where('payment_status', 'completed')->sum('amount_paid');
        $billing->update([
            'billing_status' => $paid <= 0 ? 'pending' : ($paid >= (float) $billing->total_amount ? 'paid' : 'partial'),
        ]);
    }

    /**
     * A booking must only ever have one Billing (Booking::billing() is
     * hasOne, and billings.booking_id is now DB-unique to guarantee it) -
     * this closes the race checkOutBilling()'s own $booking->billing ??
     * generateBilling($booking) can't close by itself: two concurrent
     * checkout-panel opens for the same booking could both see billing()
     * as null and both call generateBilling(). Whichever loses that race
     * now hits the unique constraint instead of creating a duplicate row -
     * caught here and treated as "someone else just created it", re-
     * fetching and using that one instead of erroring out.
     */
    private function firstOrGenerateBilling(Booking $booking): Billing
    {
        try {
            return $this->generateBilling($booking);
        } catch (QueryException $e) {
            if ((int) $e->getCode() !== 23000) {
                throw $e;
            }

            return $booking->billing()->firstOrFail();
        }
    }

    /**
     * Generate a billing record for a booking. Any already-verified deposit
     * payment made at reservation time (see
     * ReservationWorkflowService::convertToBooking()) is re-parented onto
     * this billing so it counts toward the balance immediately - the
     * checkout Billing/Payment Panels need no other changes to reflect it.
     */
    private function generateBilling(Booking $booking): Billing
    {
        $nights = max(1, abs($booking->check_out->diffInDays($booking->check_in)));

        // A multi-room booking's rooms may each have their own rate
        // override, so this sums per-room rather than multiplying a single
        // rate by rooms_requested. Falls back to the legacy single room()
        // relation for the rare pre-migration booking with no pivot rows.
        $rooms = $booking->rooms->isNotEmpty() ? $booking->rooms : collect([$booking->room])->filter();
        $roomCharge = $rooms->sum(fn ($room) => (float) $room->room_rate) * $nights;

        // No automatic discount - Promotions are package/amenity-only now
        // (their inclusions are zero-charge amenity requests granted at
        // conversion time, handled separately via amenity_charge below).
        // Authorized discounts (Senior Citizen, PWD, etc.) are applied
        // manually here by the receptionist after verifying the guest's
        // uploaded ID, via applyDiscount() above.
        $billing = Billing::create([
            'booking_id' => $booking->id,
            'room_charge' => round($roomCharge, 2),
            'additional_guest_fee' => 0,
            'amenity_charge' => 0,
            'discount' => 0,
            'total_amount' => round($roomCharge, 2),
            'billing_status' => 'pending',
        ]);

        // Fills in additional_guest_fee/amenity_charge and the resulting
        // total, re-parents any already-completed pre-checkout payment
        // onto this billing, and sets billing_status accordingly - same
        // logic refreshStayCharges() re-runs on every later
        // checkOutBilling() open, so there's exactly one place this lives.
        $this->refreshStayCharges($booking, $billing);

        return $billing;
    }

    /**
     * Re-render the additional charges table fragment with updated totals,
     * used to refresh the Billing Panel after an AJAX charge mutation.
     */
    private function chargesTableResponse(Billing $billing)
    {
        $billing->refresh()->load('additionalCharges');

        return response()->json([
            'html' => view('receptionist.check-out.partials.charges-table', compact('billing'))->render(),
            'running_total' => $billing->running_total,
            'additional_charges_total' => $billing->additional_charges_total,
        ]);
    }
}
