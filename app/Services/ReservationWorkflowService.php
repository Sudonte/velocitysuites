<?php

namespace App\Services;

use App\Models\AmenityRequest;
use App\Models\Booking;
use App\Models\Guest;
use App\Models\Payment;
use App\Models\Reservation;
use App\Models\RoomType;
use App\Models\User;
use App\Support\Activity;
use App\Support\PaymentMath;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Centralizes the Reservation/Booking status-transition rules so they
 * aren't duplicated between the guest-facing controllers (initial status,
 * deposit/full payments, cancellation) and the receptionist-facing
 * controller (accept/reject/convert) - all of them call into this
 * instead of setting status columns directly.
 */
class ReservationWorkflowService
{
    public function __construct(
        private RoomAvailabilityService $availability,
        private NotificationService $notifications,
    ) {
    }

    /**
     * Status a brand-new reservation should start at - purely a function
     * of payment method now. Even a Pay Now + GCash reservation (payment
     * already made at submission time) starts here, not already-converted -
     * recordDepositPayment()/tryAutoConvert() immediately auto-converts it
     * moments later in the same request once the payment itself is on
     * file, rather than the status skipping ahead of the payment record.
     */
    public function initialStatus(?string $paymentMethod): string
    {
        return $paymentMethod === 'gcash' ? Reservation::STATUS_AWAITING_GCASH : Reservation::STATUS_AWAITING_CASH;
    }

    /**
     * The amount range a guest can pay upfront for a stay - a config-
     * driven percentage of the undiscounted quoted total (room rate x
     * nights x rooms requested), for a Partial (deposit) payment; 'total'
     * itself is what a Full payment must equal. A discount is never
     * applied until a receptionist verifies it at billing, and the final
     * amount (amenities, additional charges) isn't known
     * until checkout, so "full" here means 100% of the quoted room total,
     * not a final bill.
     */
    public function depositRange(RoomType $roomType, int $nights, int $roomsRequested = 1): array
    {
        return $this->depositRangeForTotal((float) $roomType->rate * max(1, $nights) * max(1, $roomsRequested));
    }

    /**
     * Same min/max/total shape as depositRange() above, from an
     * already-computed total - the room-lines-aware entry point for a
     * genuine multi-room-type reservation, where a single roomType/
     * roomsRequested pair can't represent the real charge (see
     * BookingService::quoteRoomCharge()'s identical room_lines-summing
     * logic, which callers should use to compute $total for that case).
     */
    public function depositRangeForTotal(float $total): array
    {
        return [
            'total' => round($total, 2),
            'min' => round($total * (float) config('hotel.minimum_payment_ratio', 0.20), 2),
            'max' => round($total * (float) config('hotel.maximum_payment_ratio', 0.50), 2),
        ];
    }

    /**
     * What a guest may pay RIGHT NOW against this reservation - depositRangeForTotal()'s 20%-50% of the original
     * total for a first payment, but aware of what has already been paid, so the rest of the bill can be paid:
     *
     * - already paid  = completed payments only (PaymentMath::totalPaid - the definition every payment summary uses;
     *                   a converted reservation delegates to ReceiptService::paymentSummary()).
     * - remaining     = total due - already paid.
     * - full payment  = exactly the remaining balance.
     * - partial       = min..max, where min is 20% of the ORIGINAL total and max is 50% of it, never more than remaining.
     * - remaining below the minimum -> no partial payment is possible (can_partial false), only a full one.
     * - remaining zero -> nothing can be paid (is_settled true).
     *
     * @return array{total: float, paid: float, remaining: float, min: float, max: float, can_partial: bool, is_settled: bool}
     */
    public function payableRange(Reservation $reservation): array
    {
        if ($reservation->booking) {
            $summary = app(ReceiptService::class)->paymentSummary($reservation->booking);
            $total = (float) $summary['grand_total'];
            $paid = (float) $summary['total_amount_paid'];
        } else {
            $total = round((float) $reservation->total_amount_due, 2);
            $paid = PaymentMath::totalPaid($reservation->payments()->get());
        }

        $remaining = PaymentMath::remainingBalance($total, $paid);
        $range = $this->depositRangeForTotal($total);
        $discountPending = $this->hasPendingDiscount($reservation);

        // While a discount waits for its ID check the total of ALL payments (already paid + this one) must stay
        // under the smaller of 50% of the undiscounted total and the total after the requested discount, so
        // several deposits can never add up past what the bill will become. See pendingDepositCap().
        $depositCap = null;
        $capLeft = INF;
        if ($discountPending && ! $reservation->booking) {
            $depositCap = $this->pendingDepositCap($reservation, $total);
            $capLeft = max(0.0, round($depositCap - $paid, 2));
        }
        $max = min($range['max'], $remaining, $capLeft);

        return [
            'total' => round($total, 2),
            'paid' => $paid,
            'remaining' => $remaining,
            'min' => $range['min'],
            'max' => $max,
            'can_partial' => $remaining > 0.009 && $range['min'] <= $max + 0.009,
            'is_settled' => $remaining <= 0.009,
            'discount_pending' => $discountPending,
            'deposit_cap' => $depositCap,
            // The cap, not the balance, is what leaves no room for even the minimum deposit.
            'cap_reached' => $discountPending && $depositCap !== null && $capLeft + 0.009 < $range['min'],
        ];
    }

    /**
     * True while a discount the guest asked for (Senior Citizen / PWD, or any other) is still waiting for the
     * receptionist's ID check (discount_verification_status = 'pending'). Until it is decided only a deposit may be
     * paid, and deposits are capped (see pendingDepositCap()): a Full payment of the undiscounted balance could
     * overpay once the discount is applied at checkout.
     */
    public function hasPendingDiscount(Reservation $reservation): bool
    {
        return $reservation->discount_verification_status === 'pending';
    }

    /**
     * The total this reservation will come to once the discount the guest REQUESTED is applied: the same quote the
     * guest estimate and the Billing use (BookingService::quoteRoomCharge - promotions plus the Senior/PWD discount
     * through App\Support\BillDiscount), and, for a requested discount the quote does not pre-apply (VIP and the like -
     * the receptionist applies those at billing), the same BillDiscount amount on top. Null when it cannot be worked out.
     */
    public function requestedDiscountedTotal(Reservation $reservation): ?float
    {
        if (! $reservation->roomType) {
            return null;
        }

        $quote = app(BookingService::class)->quoteRoomCharge($reservation);
        $discounted = (float) $quote['total'];

        [$requested] = \App\Support\DiscountSelection::resolve($reservation->discount_id, $reservation->id_card_type, null, false);
        if ($requested !== null && ! $requested->isStatutory()) {
            $discounted -= \App\Support\BillDiscount::amount($requested, (float) $quote['room_charge'], (float) $quote['add_ons']);
        }

        return round(max(0.0, $discounted), 2);
    }

    /**
     * The most ALL payments together may add up to while the discount is pending: the smaller of 50% of the
     * undiscounted total and the total after the requested discount (a 50%-or-larger discount is what makes the
     * second one win).
     */
    public function pendingDepositCap(Reservation $reservation, float $undiscountedTotal): float
    {
        $cap = round($undiscountedTotal * (float) config('hotel.maximum_payment_ratio', 0.50), 2);
        $discounted = $this->requestedDiscountedTotal($reservation);

        return $discounted === null ? $cap : min($cap, $discounted);
    }

    /** The same cap for a transaction that does not exist yet (a reservation or booking created together with its payment). */
    public function pendingDepositCapForNewTransaction(float $undiscountedTotal, ?\App\Models\Discount $discount): float
    {
        $cap = round($undiscountedTotal * (float) config('hotel.maximum_payment_ratio', 0.50), 2);
        $discounted = max(0.0, $undiscountedTotal - \App\Support\BillDiscount::amount($discount, $undiscountedTotal, 0.0));

        return min($cap, round($discounted, 2));
    }

    /** Why Full payment is unavailable while a discount is being verified - one wording for every payment entry point. */
    public function pendingDiscountFullPaymentMessage(?array $depositRange = null): string
    {
        $message = 'Your discount is being verified, so Full payment is not available yet. You can pay a deposit now';
        if ($depositRange !== null && $depositRange['min'] <= $depositRange['max'] + 0.009) {
            $message .= ' (between ₱' . number_format($depositRange['min'], 2) . ' and ₱' . number_format($depositRange['max'], 2) . ')';
        }

        return $message . ' and pay the rest after the discount is applied.';
    }

    /** Nothing more can be paid online while the discount is pending: the deposit cap has been used up. */
    public function maxDepositReachedMessage(): string
    {
        return "You've paid the maximum deposit while your discount is being verified. The rest is settled at the front desk.";
    }

    private function peso(float $amount): string
    {
        return '₱' . number_format($amount, 2);
    }

    /**
     * Why $amount is not an acceptable payment right now, as the 422 body (message + errors.amount_paid), or null if
     * it is. $range comes from payableRange(). The ONE amount rule for every guest payment entry point (the mobile
     * API and the website), so they can never disagree.
     */
    public function amountError(array $range, string $paymentType, float $amount): ?array
    {
        $message = null;

        if ($range['is_settled']) {
            $message = 'This reservation is already fully paid. There is nothing left to pay.';
        } elseif ($range['discount_pending'] && ! $range['can_partial'] && $range['cap_reached']) {
            $message = $this->maxDepositReachedMessage();
        } elseif ($paymentType === 'full') {
            if ($range['discount_pending']) {
                // A discount waiting for the ID check: deposits only, whatever the amount (see hasPendingDiscount()).
                $message = $this->pendingDiscountFullPaymentMessage($range['can_partial'] ? $range : null);
            } elseif (abs($amount - $range['remaining']) > 0.01) {
                $message = 'Full payment must equal the remaining balance (' . $this->peso($range['remaining']) . ').';
            }
        } elseif (! $range['can_partial'] && $range['discount_pending']) {
            $message = 'Your discount is being verified. Payment is on hold until it is applied; the remaining balance ('
                . $this->peso($range['remaining']) . ') is below the minimum deposit (' . $this->peso($range['min']) . ').';
        } elseif (! $range['can_partial']) {
            $message = 'Full payment is required: the remaining balance (' . $this->peso($range['remaining'])
                . ') is below the minimum down payment (' . $this->peso($range['min']) . '). Pay the full '
                . $this->peso($range['remaining']) . ' instead.';
        } elseif ($amount < $range['min'] - 0.005 || $amount > $range['max'] + 0.005) {
            $minPercent = (int) round((float) config('hotel.minimum_payment_ratio', 0.20) * 100);
            $maxPercent = (int) round((float) config('hotel.maximum_payment_ratio', 0.50) * 100);
            $message = 'The payment must be between ' . $this->peso($range['min']) . ' and ' . $this->peso($range['max'])
                . ($range['discount_pending']
                    ? ' while your discount is being verified (a deposit of ' . $minPercent . '%-' . $maxPercent . '% of the '
                        . $this->peso($range['total']) . ' total, and no more than the discounted total).'
                    : " ({$minPercent}%-{$maxPercent}% of the " . $this->peso($range['total']) . ' total, and never more than the '
                        . $this->peso($range['remaining']) . ' still owed).');
        }

        return $message === null ? null : ['message' => $message, 'errors' => ['amount_paid' => [$message]]];
    }

    /** 'final' when the payment settles everything still owed (whichever option the guest picked), else 'deposit'. */
    public function stageFor(array $range, float $amount): string
    {
        return abs($amount - $range['remaining']) <= 0.01 ? 'final' : 'deposit';
    }

    /**
     * True if this guest already has an active (not cancelled/rejected)
     * reservation or booking of the same room type whose stay genuinely
     * OVERLAPS the requested dates (a partial overlap, not an exact
     * check_in/check_out match) - used by Guest\ReservationController::store(),
     * Api\ReservationController::store(), and Api\ReservationController::update()
     * to block a guest from committing to an overlapping stay for the same
     * room type. An EXACT date match is deliberately NOT treated as a
     * conflict here: a guest is allowed to create multiple Bookings, and
     * separately multiple Reservations, using the same check-in/check-out
     * dates (e.g. two separate transactions for the same trip), as long as
     * room availability and every other condition is independently
     * satisfied - only a genuinely different, overlapping range blocks.
     * Half-open interval comparison (check_in < newCheckOut AND
     * check_out > newCheckIn) so back-to-back stays (one check-out day the
     * next check-in day) don't count as overlapping either.
     */
    public function hasOverlappingReservation(Guest $guest, RoomType $roomType, Carbon $checkIn, Carbon $checkOut, ?int $excludeReservationId = null): bool
    {
        return Reservation::where('guest_id', $guest->id)
            ->whereNotIn('status', [Reservation::STATUS_CANCELLED, Reservation::STATUS_REJECTED])
            ->when($excludeReservationId, fn ($q) => $q->where('id', '!=', $excludeReservationId))
            // whereHas(roomLines) rather than the parent row's own
            // room_type_id - that column only ever reflects a multi-room-
            // type reservation's FIRST line (kept in sync purely for
            // backward-compatible display - see e.g. DirectBookingService::
            // create()'s identical convention on the Booking side), so
            // checking it alone silently missed an overlap on any 2nd+ room
            // type in a multi-room-type reservation. reservation_room_lines
            // is the authoritative per-type record.
            ->whereHas('roomLines', fn ($q) => $q->where('room_type_id', $roomType->id))
            ->where('check_in', '<', $checkOut)
            ->where('check_out', '>', $checkIn)
            ->where(fn ($q) => $q->where('check_in', '!=', $checkIn)->orWhere('check_out', '!=', $checkOut))
            ->exists();
    }

    /**
     * Guest submits a GCash payment - either at booking/reservation time
     * (pay_now) or later against an existing Pay Later reservation, as
     * either a Partial (deposit) or Full (final) payment. A GCash payment
     * (partial or full) immediately attempts to auto-convert the
     * reservation straight into a Booking (see tryAutoConvert()), since
     * GCash is the only payment method that can be self-reported by the
     * guest and still trusted enough to skip the receptionist's manual
     * Convert step. Cash can never be verified online, so it's recorded
     * separately (see recordCashIntent()) and never auto-converts - a Cash
     * reservation only ever converts when a receptionist does it.
     */
    public function recordDepositPayment(Reservation $reservation, array $paymentData, string $paymentStage = 'deposit'): Payment
    {
        $payment = Payment::create(array_merge($paymentData, [
            'reservation_id' => $reservation->id,
            'billing_id' => null,
            'payment_stage' => $paymentStage,
            'payment_status' => 'pending',
            'payment_date' => now(),
        ]));

        // Reservation.payment_method was previously never actually set anywhere - only
        // the Payment row's own payment_method was. Anything reading $reservation->payment_method
        // directly (dashboard cards, receptionist views) was always seeing null. Keep the
        // reservation's own record in sync with what was just paid. payment_preference is
        // left untouched here - it reflects the guest's original Pay Now/Pay Later choice,
        // not necessarily whether they're paying at this exact moment.
        $reservation->update([
            'payment_method' => $paymentData['payment_method'] ?? $reservation->payment_method,
        ]);

        if (($paymentData['payment_method'] ?? null) === 'gcash') {
            $this->tryAutoConvert($reservation, $payment);
        }

        return $payment;
    }

    /**
     * Guest states an intended cash amount at reservation time - purely
     * informational for the receptionist, never auto-advances the status
     * (cash always behaves like Pay Later: stays in "To Be Confirmed"
     * until staff reviews it in person, and never auto-converts).
     */
    public function recordCashIntent(Reservation $reservation, float $amount, string $paymentStage = 'deposit', ?string $idempotencyKey = null): Payment
    {
        $payment = Payment::create([
            'reservation_id' => $reservation->id,
            'billing_id' => null,
            'idempotency_key' => $idempotencyKey,
            'payment_method' => 'cash',
            'amount_paid' => $amount,
            'payment_stage' => $paymentStage,
            'payment_status' => 'pending',
            'payment_date' => now(),
        ]);

        $reservation->update(['payment_method' => 'cash']);

        return $payment;
    }

    /**
     * Attempts to immediately convert a just-paid GCash reservation into
     * a Booking. If inventory isn't actually available for the dates (a
     * rare race - most reservations don't hold inventory until
     * conversion), conversion is simply skipped and the reservation stays
     * awaiting-GCash for a receptionist to resolve manually via
     * convertToBooking() - the payment itself is never blocked or rolled
     * back over an availability race.
     *
     * Defense-in-depth against duplicate conversion: Api\PaymentController::
     * store() (this method's only current caller) already re-fetches the
     * reservation with lockForUpdate() before calling recordDepositPayment()
     * -> this method, so two concurrent payment submissions for the same
     * reservation are already fully serialized before either reaches here.
     * The re-lock and existing-Booking check below are a second,
     * independent safety net - correct even if a future caller ever
     * invokes recordDepositPayment()/tryAutoConvert() outside that locked
     * context - rather than relying on the caller's lock alone.
     */
    private function tryAutoConvert(Reservation $reservation, Payment $payment): void
    {
        if ($reservation->status !== Reservation::STATUS_AWAITING_GCASH) {
            return;
        }

        if ($this->firstUnavailableLine($reservation)) {
            return;
        }

        DB::transaction(function () use ($reservation, $payment) {
            $locked = Reservation::whereKey($reservation->id)->lockForUpdate()->first();

            if (! $locked || $locked->status !== Reservation::STATUS_AWAITING_GCASH) {
                // Already converted (or otherwise moved on) by another
                // request that won the race between the unlocked check
                // above and this lock - nothing left to convert. Still
                // mark this specific payment completed so it isn't left
                // permanently 'pending' just because it lost the race.
                $payment->update(['payment_status' => 'completed']);

                return;
            }

            $existingBooking = Booking::where('reservation_id', $locked->id)->first();
            if ($existingBooking) {
                $payment->update(['payment_status' => 'completed']);

                return;
            }

            // The lockForUpdate() above only serializes against another
            // request touching THIS SAME reservation row - it does nothing
            // to stop a DIFFERENT reservation (or a receptionist's manual
            // convertToBooking()) racing for the same room type's last free
            // room at the same instant. Locking the room_type row(s) here,
            // then re-running the exact same availability check the
            // pre-transaction call above already ran, is what actually
            // closes that gap. On a genuine shortfall this behaves exactly
            // like the pre-transaction check already did: leaves the
            // reservation awaiting-GCash and the payment pending for a
            // receptionist to resolve manually - never blocks or rolls back
            // the payment itself (see this method's own top doc).
            $this->availability->lockRoomTypesForAvailabilityCheck($this->roomTypeIdsForReservation($locked));
            if ($this->firstUnavailableLine($locked)) {
                return;
            }

            $this->createBookingFromReservation($locked);
            $payment->update(['payment_status' => 'completed']);
        });
    }

    /**
     * Every distinct room_type_id this reservation actually needs a room
     * for - real reservation_room_lines for a genuine multi-room-type
     * reservation, or the single legacy room_type_id otherwise. Shared by
     * convertToBooking()/tryAutoConvert()'s identical need to lock every
     * relevant room_type row before re-checking availability.
     */
    public function roomTypeIdsForReservation(Reservation $reservation): array
    {
        $lines = $reservation->roomLines()->get();

        return $lines->isEmpty() ? [$reservation->room_type_id] : $lines->pluck('room_type_id')->all();
    }

    /**
     * Checks availability per room-type line for a genuine multi-room-type
     * reservation (real reservation_room_lines), or the single roomType/
     * rooms_requested pair for a legacy single-room-type one - checking only
     * $reservation->roomType/$reservation->rooms_requested (the FIRST
     * line's type and the SUMMED quantity across every line) would
     * incorrectly pass or fail conversion based on the wrong room type's
     * inventory the moment more than one room type is involved. Returns
     * null when everything requested is available, or
     * ['name' => ..., 'quantity' => ..., 'available' => ...] for the first
     * line found short, for a clear error message.
     */
    public function firstUnavailableLine(Reservation $reservation): ?array
    {
        $lines = $reservation->roomLines()->get();
        if ($lines->isEmpty()) {
            $available = $this->availability->availableCount($reservation->roomType, $reservation->check_in, $reservation->check_out);
            if ($available < $reservation->rooms_requested) {
                return ['name' => $reservation->roomType->name, 'quantity' => $reservation->rooms_requested, 'available' => $available];
            }

            return null;
        }

        foreach ($lines as $line) {
            $roomType = RoomType::find($line->room_type_id);
            $available = $this->availability->availableCount($roomType, $reservation->check_in, $reservation->check_out);
            if ($available < $line->quantity) {
                return ['name' => $line->room_type_name, 'quantity' => $line->quantity, 'available' => $available];
            }
        }

        return null;
    }

    /**
     * Receptionist rejects a reservation from either tab. Always requires a
     * reason (e.g. ineligible, or the room type is fully booked for the
     * requested dates).
     */
    public function reject(Reservation $reservation, string $reason, User $staff): void
    {
        abort_unless(
            in_array($reservation->status, Reservation::ACTIVE_STATUSES, true),
            422,
            'Only an active reservation can be rejected.'
        );

        DB::transaction(function () use ($reservation, $reason, $staff) {
            $reservation->update(['status' => Reservation::STATUS_REJECTED, 'rejection_reason' => $reason]);

            // Not stage-filtered: a Pay-Now-Full GCash submission is
            // payment_stage 'final', not 'deposit' - filtering to deposit
            // only left a full-payment submission's Payment row stuck at
            // 'pending' forever once its reservation was rejected, with no
            // other code path that would ever touch it again.
            $reservation->payments()
                ->where('payment_status', 'pending')
                ->get()
                ->each(fn ($payment) => $payment->update([
                    'payment_status' => 'failed',
                    'verified_by' => $staff->id,
                    'verified_at' => now(),
                ]));

            // Keep this reservation's booking-time amenity requests
            // (created pending by ReservationAmenityService::snapshot())
            // in lockstep with the reservation's own rejection.
            AmenityRequest::where('reservation_id', $reservation->id)
                ->where('status', 'pending')
                ->update(['status' => 'rejected']);
        });

        Activity::log(
            'Rejected reservation request',
            "Reservation #{$reservation->id} for {$reservation->roomType->name} ({$reservation->guest_display_name}) - {$reason}",
            $reservation
        );
    }

    /**
     * System counterpart to reject() above - automatically rejects a
     * reservation whose Reservation::payment_deadline (2-day/48-hour rule,
     * see that accessor's docblock) has passed with no completed payment.
     * Called both from the reservations:expire-unpaid scheduled command
     * and, as a cron-independent safety net (this host has no reachable
     * crontab - see routes/console.php), lazily from Api\ReservationController/
     * Guest\ReservationController's own index()/show() right before they
     * return a reservation that's now overdue. No $staff/Activity::log
     * here (Activity::log silently no-ops without an authenticated user
     * anyway, matching the same convention accounts:purge-expired already
     * uses for its own unattended cleanup) - the rejection_reason text
     * itself is the guest-visible record of why this happened.
     */
    public function expireUnpaid(Reservation $reservation): void
    {
        $deadline = $reservation->payment_deadline;

        if ($deadline !== null) {
            if (now()->lt($deadline)) {
                return;
            }
            $reason = 'Automatically rejected: the required payment was not completed within the 48-hour deadline.';
        } elseif ($this->isUnpaidPastCheckInDay($reservation)) {
            // Short-notice reservations have no 48-hour deadline (see
            // Reservation::getPaymentDeadlineAttribute()); they expire once
            // their check-in day ends still unpaid.
            $reason = 'Automatically rejected: the reservation was not paid or confirmed by the end of its check-in date.';
        } else {
            return;
        }

        DB::transaction(function () use ($reservation, $reason) {
            $reservation->update(['status' => Reservation::STATUS_REJECTED, 'rejection_reason' => $reason]);

            // Not stage-filtered - see reject()'s identical comment above.
            $reservation->payments()
                ->where('payment_status', 'pending')
                ->update(['payment_status' => 'failed']);

            AmenityRequest::where('reservation_id', $reservation->id)
                ->where('status', 'pending')
                ->update(['status' => 'rejected']);
        });

        if ($guest = $reservation->guest?->user) {
            $this->notifications->notifyReservationExpired($guest, $reservation->roomType->name, $reservation->id);
        }
    }

    private function isUnpaidPastCheckInDay(Reservation $reservation): bool
    {
        return in_array($reservation->status, Reservation::ACTIVE_STATUSES, true)
            && now()->gt($reservation->check_in->copy()->endOfDay())
            && ! $reservation->payments()->where('payment_status', 'completed')->exists();
    }

    /**
     * Convert a reservation into a Booking - gated on room-type inventory
     * actually being available for the requested dates. This is the
     * receptionist's manual path, and the ONLY way a Cash reservation ever
     * converts - there's no auto-convert for Cash, since it can't be
     * verified online. A GCash reservation usually never reaches here at
     * all, since recordDepositPayment() already auto-converted it via
     * tryAutoConvert() the moment payment came in; this stays reachable
     * for a GCash reservation too as the manual fallback for the rare case
     * where auto-convert skipped it (no inventory free at that instant -
     * see tryAutoConvert()'s docblock).
     */
    public function convertToBooking(Reservation $reservation, User $staff): Booking
    {
        abort_unless(in_array($reservation->status, Reservation::AWAITING_STATUSES, true), 422, 'Only an active reservation can be converted.');

        // Defense in depth: a GCash reservation should never reach here
        // without a payment attempt already on file - recordDepositPayment()
        // always creates a Payment first. This backstops that invariant
        // against a receptionist manually forcing Convert on a GCash
        // reservation the guest never actually paid.
        if ($reservation->payment_method === 'gcash' && $reservation->payments()->doesntExist()) {
            abort(422, 'This reservation has not received a GCash payment submission yet.');
        }

        if ($shortfall = $this->firstUnavailableLine($reservation)) {
            abort(422, $shortfall['quantity'] > 1
                ? "Not enough {$shortfall['name']} rooms available for the requested dates (needs {$shortfall['quantity']}, only {$shortfall['available']} free)."
                : "{$shortfall['name']} is fully booked for the requested dates.");
        }

        $booking = DB::transaction(function () use ($reservation, $staff) {
            // The check above is only a fast-fail for the common case -
            // two receptionists converting different reservations of the
            // same room type (or this manual convert racing tryAutoConvert()
            // for a different reservation) could both pass it before either
            // committed. Locking the room_type row(s) and re-checking here,
            // with the lock held, is the actual guard (mirrors
            // DirectBookingService::create()'s identical fix for the
            // guest-facing direct-booking path).
            $this->availability->lockRoomTypesForAvailabilityCheck($this->roomTypeIdsForReservation($reservation));
            if ($shortfall = $this->firstUnavailableLine($reservation)) {
                abort(422, $shortfall['quantity'] > 1
                    ? "Not enough {$shortfall['name']} rooms available for the requested dates (needs {$shortfall['quantity']}, only {$shortfall['available']} free)."
                    : "{$shortfall['name']} is fully booked for the requested dates.");
            }

            $booking = $this->createBookingFromReservation($reservation);

            // Not stage-filtered: a Pay-Now-Full GCash reservation that
            // missed tryAutoConvert()'s auto-conversion window (e.g. the
            // room type was momentarily fully booked) has its payment at
            // payment_stage 'final', not 'deposit' - a receptionist
            // manually converting it here needs that payment completed
            // too, not left stuck at 'pending'.
            $reservation->payments()
                ->where('payment_status', 'pending')
                ->get()
                ->each(fn ($payment) => $payment->update([
                    'payment_status' => 'completed',
                    'verified_by' => $staff->id,
                    'verified_at' => now(),
                ]));

            // A Cash conversion is the receptionist's own in-person
            // confirmation - there's nothing left for anyone to verify
            // afterward, unlike GCash (a guest-submitted receipt still
            // needs review - see Booking::gcashPaymentNeedsVerification(),
            // the gate Receptionist\BookingController::verify() checks,
            // and the Bookings module's "For Verification" tab). Auto-
            // verifying here means a Cash booking never has to wait in
            // that tab for a second, redundant staff sign-off.
            if ($reservation->payment_method !== 'gcash') {
                $booking->update(['verified_at' => now(), 'verified_by' => $staff->id]);
            }

            return $booking;
        });

        Activity::log(
            'Converted reservation to booking',
            "Reservation #{$reservation->id} for {$reservation->roomType->name} ({$reservation->guest_display_name})",
            $booking
        );

        return $booking;
    }

    /**
     * Shared by the receptionist's manual convert and the automatic
     * GCash-payment conversion - just the Booking row + reservation
     * status flip. Always starts with verified_at/verified_by null here -
     * convertToBooking() (the only caller that ever has a Cash
     * reservation to convert) sets those itself right after this returns,
     * since GCash needs a later separate verification step
     * (Receptionist\BookingController::verify(), gated on
     * Booking::gcashPaymentNeedsVerification()) and Cash doesn't.
     *
     * Also copies the reservation's Representative Name (guest_first/
     * middle/last_name), children's ages (additional_guest_details), and
     * Senior/PWD ID fields onto the new Booking row - these are all
     * already in Booking::$fillable but were previously never actually
     * copied here, silently leaving them null on every reservation-
     * derived Booking (only ever populated for a direct "New Booking"
     * mobile transaction, which creates its Booking without a Reservation
     * at all). That's a real preserve-all-reservation-details bug: once
     * converted, Booking::getStayGuestFullNameAttribute() would return
     * null instead of the guest's actual Representative Name.
     *
     * Also bulk-flips this reservation's still-pending booking-time
     * amenity requests (created by ReservationAmenityService::snapshot())
     * to approved, for both a Cash and a GCash reservation alike - there's
     * no separate "verify the reservation" step anymore, so conversion
     * itself is the moment a reservation's amenity selections become real.
     */
    private function createBookingFromReservation(Reservation $reservation): Booking
    {
        $booking = Booking::create([
            'reservation_id' => $reservation->id,
            'room_type_id' => $reservation->room_type_id,
            'rooms_requested' => $reservation->rooms_requested,
            'check_in' => $reservation->check_in,
            'check_out' => $reservation->check_out,
            'adults' => $reservation->adults,
            'children' => $reservation->children,
            'number_of_guests' => $reservation->number_of_guests,
            'confirmed_at' => now(),
            'booking_status' => Booking::STATUS_ACTIVE,
            'payment_method' => $reservation->payment_method,
            'guest_first_name' => $reservation->guest_first_name,
            'guest_middle_name' => $reservation->guest_middle_name,
            'guest_last_name' => $reservation->guest_last_name,
            'additional_guest_details' => $reservation->additional_guest_details,
            'id_card_type' => $reservation->id_card_type,
            'discount_id' => $reservation->discount_id,
            'id_card_image_path' => $reservation->id_card_image_path,
            'discount_requested' => $reservation->discount_requested,
            'discount_verification_status' => $reservation->discount_verification_status,
            // Both guest-selected-at-creation-time metadata, not recomputed
            // - previously omitted here entirely, so a receptionist opening
            // Booking Details right after conversion saw "N/A" for Payment
            // Percentage even though the guest had explicitly picked one
            // (e.g. 30%) and Amount Paid/Remaining Balance were already
            // correct (those are computed independently from the payments
            // table, never from this column - only the display label was
            // lost).
            'selected_payment_percentage' => $reservation->selected_payment_percentage,
            'required_payment_amount' => $reservation->required_payment_amount,
        ]);

        // Carry every itemized room-type line over to the new Booking - a
        // genuine multi-room-type reservation (real reservation_room_lines,
        // not the legacy single room_type_id/rooms_requested pair) must not
        // lose its per-line breakdown at conversion, or Booking::
        // getTotalAmountDueAttribute()/BookingService::ensureBilling() would
        // silently fall back to pricing the whole booking at just the first
        // line's rate x the summed quantity once room_lines is empty.
        foreach ($reservation->roomLines()->get() as $line) {
            $booking->roomLines()->create([
                'room_type_id' => $line->room_type_id,
                'room_type_name' => $line->room_type_name,
                'quantity' => $line->quantity,
                'price_per_night' => $line->price_per_night,
                'number_of_nights' => $line->number_of_nights,
                'subtotal' => $line->subtotal,
            ]);
        }

        $reservation->update(['status' => Reservation::STATUS_CONVERTED]);

        AmenityRequest::where('reservation_id', $reservation->id)
            ->where('status', 'pending')
            ->update(['status' => 'approved']);

        return $booking;
    }

    /**
     * Cancel a reservation or an already-converted booking. Pre-
     * conversion, this is the existing simple path (nothing to verify yet
     * - no Booking exists). Post-conversion, only allowed before check-in
     * and only while the resulting Booking hasn't been receptionist-
     * verified yet (Booking::verified_at null - see
     * Api\BookingController::cancel()'s docblock for why this is keyed
     * off verified_at rather than payment amount/payment_status). A
     * cancelled partial-GCash deposit is forfeited (never refunded, and
     * this method doesn't touch the payment record itself since the
     * money already changed hands outside this system).
     */
    public function cancel(Reservation $reservation): void
    {
        if ($reservation->status === Reservation::STATUS_CONVERTED) {
            $this->cancelConvertedBooking($reservation);
            return;
        }

        abort_unless(in_array($reservation->status, Reservation::ACTIVE_STATUSES, true), 422, 'Cannot cancel this reservation.');

        DB::transaction(function () use ($reservation) {
            $reservation->update(['status' => Reservation::STATUS_CANCELLED]);

            // Not stage-filtered - see reject()'s identical comment above.
            $reservation->payments()
                ->where('payment_status', 'pending')
                ->update(['payment_status' => 'failed']);
        });

        $this->logCancellation($reservation);
    }

    private function cancelConvertedBooking(Reservation $reservation): void
    {
        $booking = $reservation->booking;
        abort_if(! $booking, 404, 'Booking not found.');

        abort_if(
            in_array($booking->booking_status, [Booking::STATUS_CHECKED_IN, Booking::STATUS_COMPLETED, Booking::STATUS_CANCELLED]),
            422,
            'This booking can no longer be cancelled.'
        );

        abort_if($booking->verified_at !== null, 422, 'This booking has already been verified by our staff and can no longer be cancelled.');

        DB::transaction(function () use ($reservation, $booking) {
            $booking->update(['booking_status' => Booking::STATUS_CANCELLED]);
            $reservation->update(['status' => Reservation::STATUS_CANCELLED]);
        });

        $this->logCancellation($reservation);
    }

    private function logCancellation(Reservation $reservation): void
    {
        $guestName = $reservation->guest_display_name;

        Activity::log(
            'Cancelled reservation',
            "Reservation #{$reservation->id} for {$reservation->roomType->name} ({$guestName})",
            $reservation
        );

        $this->notifications->notifyAdmin(
            'Reservation Cancelled',
            "Reservation #{$reservation->id} for {$reservation->roomType->name} ({$guestName}) was cancelled.",
            'reservation',
            $reservation->id
        );
    }

    /**
     * One-time switch of a Cash reservation's payment method to GCash - lets a guest
     * who originally chose Cash (walk-in payment) decide to pay online instead. Once
     * used, payment_method_locked_at is permanently set, so this can never be called
     * again for this reservation (DB-enforced, unlike the Android-local
     * LocalTransactionState.hasModifiedOnce gate this replaces for payment-method
     * purposes specifically - that still separately gates the dates/guests Modify form).
     * After this succeeds, the reservation's normal GCash Pay Now flow
     * (Api\PaymentController::store()) becomes available immediately - no separate
     * unlock step needed, since eligibility there is just payment_method/status, which
     * this already updates.
     *
     * Also updates `status` to STATUS_AWAITING_GCASH, mirroring exactly what
     * Api\ReservationController::store() sets at creation time for a
     * reservation that started as GCash - previously this method only
     * updated payment_method, leaving `status` at STATUS_AWAITING_CASH.
     * tryAutoConvert() (called from recordDepositPayment() the moment the
     * guest's GCash payment is submitted) gates on
     * `$reservation->status !== Reservation::STATUS_AWAITING_GCASH` and
     * returns early otherwise - so a Cash reservation switched to GCash via
     * this method could complete a real GCash payment and still never
     * auto-convert to a Booking, silently, with no error surfaced to the
     * guest (confirmed live: payment recorded successfully, `status`
     * remained AWAITING_CASH_CONFIRMATION, `booking` stayed null). Android's
     * own post-payment navigation already correctly branches on the
     * server-reported converted-booking state and only falls back to
     * Transaction History when the server didn't report one - so this was
     * the entire root cause, not an Android defect.
     */
    public function switchToGcash(Reservation $reservation): void
    {
        abort_unless(
            in_array($reservation->status, Reservation::ACTIVE_STATUSES, true),
            422,
            'This reservation is no longer eligible to change its payment method.'
        );
        abort_unless($reservation->payment_method === 'cash', 422, 'Only a Cash reservation can be switched to GCash.');
        abort_if($reservation->payment_method_locked_at !== null, 422, 'The payment method for this reservation has already been changed once and cannot be changed again.');

        $reservation->update([
            'payment_method' => 'gcash',
            'status' => Reservation::STATUS_AWAITING_GCASH,
            'payment_method_locked_at' => now(),
        ]);
    }

    /**
     * One-time switch of a GCash reservation's payment method to Cash - the mirror
     * of switchToGcash() above, sharing the same payment_method_locked_at DB-enforced
     * one-time lock, so a reservation's payment method can only ever be changed once
     * total, in either direction. Lets a guest who originally chose GCash decide to
     * pay walk-in instead; unlike switchToGcash(), this doesn't unlock a Pay Now flow -
     * the reservation simply becomes eligible for walk-in cash settlement like any
     * other Cash reservation.
     *
     * Also updates `status` to STATUS_AWAITING_CASH, mirroring
     * switchToGcash()'s identical fix and creation-time convention - keeps
     * `status` and `payment_method` from ever disagreeing in either
     * direction, even though nothing currently gates auto-conversion on
     * this side (Cash never auto-converts).
     */
    public function switchToCash(Reservation $reservation): void
    {
        abort_unless(
            in_array($reservation->status, Reservation::ACTIVE_STATUSES, true),
            422,
            'This reservation is no longer eligible to change its payment method.'
        );
        abort_unless($reservation->payment_method === 'gcash', 422, 'Only a GCash reservation can be switched to Cash.');
        abort_if($reservation->payment_method_locked_at !== null, 422, 'The payment method for this reservation has already been changed once and cannot be changed again.');

        $reservation->update([
            'payment_method' => 'cash',
            'status' => Reservation::STATUS_AWAITING_CASH,
            'payment_method_locked_at' => now(),
        ]);
    }

    /**
     * Guest-initiated hide: removes a transaction from the guest's own
     * list without ever hard-deleting the underlying reservation/booking/
     * payment rows (the hotel still needs them for accounting/audit, and
     * receptionist dashboards must keep showing them regardless). Only
     * allowed once the transaction has actually reached a terminal state -
     * this guard must stay in sync with the Android app's
     * TransactionCategorizer (COMPLETED/CANCELLED), since that's what
     * decides when the Delete button is even shown, but this is the
     * authoritative check.
     */
    public function hide(Reservation $reservation): void
    {
        $booking = $reservation->booking;

        $isCancelled = in_array($reservation->status, [Reservation::STATUS_CANCELLED, Reservation::STATUS_REJECTED])
            || ($booking && $booking->booking_status === Booking::STATUS_CANCELLED);
        $isCompleted = $booking && $booking->booking_status === Booking::STATUS_COMPLETED;

        abort_unless($isCancelled || $isCompleted, 422, 'Only completed or cancelled bookings/reservations can be deleted.');

        DB::transaction(function () use ($reservation, $booking) {
            $reservation->update(['hidden_at' => now()]);
            if ($booking) {
                $booking->update(['hidden_at' => now()]);
            }
        });

        Activity::log(
            'Removed transaction from view',
            "Reservation #{$reservation->id} for {$reservation->roomType->name}",
            $reservation
        );
    }

    /**
     * Cancels a confirmed, not-yet-verified Booking whose gating GCash
     * payment (latestGcashPayment()) has no number/receipt ever attached,
     * or has already been rejected - there's no resubmission path for an
     * already-converted booking (see Booking::gcashPaymentNeedsVerification(),
     * which stays true forever for a rejected payment), so leaving
     * booking_status untouched would strand the booking permanently: no
     * Verify action, and "must be verified above" shown forever. Rejects
     * the payment itself first if it isn't rejected yet (the "never
     * submitted" case) and notifies the guest; no-ops entirely for a
     * payment still genuinely awaiting review, or once the booking is
     * already verified or no longer confirmed. Called reactively from
     * Receptionist\BookingController on every Bookings-module page load,
     * and immediately after a receptionist manually rejects a payment
     * (Receptionist\PaymentController::reject()).
     */
    public function reconcileGcashBookingPayment(Booking $booking): void
    {
        if ($booking->booking_status !== Booking::STATUS_ACTIVE || $booking->verified_at !== null) {
            return;
        }

        $payment = $booking->latestGcashPayment();
        if (!$payment || $payment->isVerified()) {
            return;
        }

        $wasAlreadyRejected = $payment->isRejected();
        $incomplete = empty($payment->gcash_number) || empty($payment->receipt_path);
        if (!$wasAlreadyRejected && !$incomplete) {
            return;
        }

        DB::transaction(function () use ($booking, $payment, $wasAlreadyRejected) {
            if (!$wasAlreadyRejected) {
                $payment->update([
                    'rejected_at' => now(),
                    'rejection_reason' => 'Auto-rejected: no GCash number or receipt was ever submitted for this payment.',
                    'payment_status' => 'rejected',
                ]);
            }
            $booking->update(['booking_status' => Booking::STATUS_CANCELLED]);
        });

        Activity::log(
            $wasAlreadyRejected ? 'Cancelled booking' : 'Auto-rejected booking',
            $wasAlreadyRejected
                ? "Booking #{$booking->id} for {$booking->roomType->name} - cancelled after its GCash payment was rejected."
                : "Booking #{$booking->id} for {$booking->roomType->name} - GCash payment auto-rejected (no number/receipt was ever submitted).",
            $booking
        );

        if (!$wasAlreadyRejected && $guest = $booking->account_guest?->user) {
            $this->notifications->notifyPaymentRejected(
                $guest,
                (float) $payment->amount_paid,
                $booking->roomType->name ?? 'your stay',
                'no GCash number or receipt was ever submitted for this payment',
                $booking->reservation_id
            );
        }
    }
}
