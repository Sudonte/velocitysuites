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
     * amount (extra guest fees, amenities, additional charges) isn't known
     * until checkout, so "full" here means 100% of the quoted room total,
     * not a final bill.
     */
    public function depositRange(RoomType $roomType, int $nights, int $roomsRequested = 1): array
    {
        $total = (float) $roomType->rate * max(1, $nights) * max(1, $roomsRequested);

        return [
            'total' => round($total, 2),
            'min' => round($total * (float) config('hotel.minimum_payment_ratio', 0.20), 2),
            'max' => round($total * (float) config('hotel.maximum_payment_ratio', 0.50), 2),
        ];
    }

    /**
     * True if this guest already has an active (not cancelled/rejected)
     * reservation or booking of the same room type whose stay overlaps the
     * requested dates - used by both Guest\ReservationController::store()
     * and Api\ReservationController::store() to block an accidental duplicate
     * submission (e.g. a double-tap, or resubmitting the same request form)
     * before it ever reaches the database, per the same guard the Android
     * app already enforces client-side (BookingAndReservationActivity's
     * findOverlappingBooking()) - this makes it a real, server-side rule
     * both platforms share instead of one client's local-only check.
     * Half-open interval comparison (check_in < newCheckOut AND
     * check_out > newCheckIn) so back-to-back stays (one check-out day the
     * next check-in day) don't count as overlapping.
     */
    public function hasOverlappingReservation(Guest $guest, RoomType $roomType, Carbon $checkIn, Carbon $checkOut, ?int $excludeReservationId = null): bool
    {
        return Reservation::where('guest_id', $guest->id)
            ->where('room_type_id', $roomType->id)
            ->whereNotIn('status', [Reservation::STATUS_CANCELLED, Reservation::STATUS_REJECTED])
            ->when($excludeReservationId, fn ($q) => $q->where('id', '!=', $excludeReservationId))
            ->where('check_in', '<', $checkOut)
            ->where('check_out', '>', $checkIn)
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
    public function recordCashIntent(Reservation $reservation, float $amount, string $paymentStage = 'deposit'): Payment
    {
        $payment = Payment::create([
            'reservation_id' => $reservation->id,
            'billing_id' => null,
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
     */
    private function tryAutoConvert(Reservation $reservation, Payment $payment): void
    {
        if ($reservation->status !== Reservation::STATUS_AWAITING_GCASH) {
            return;
        }

        $available = $this->availability->availableCount($reservation->roomType, $reservation->check_in, $reservation->check_out);
        if ($available < $reservation->rooms_requested) {
            return;
        }

        DB::transaction(function () use ($reservation, $payment) {
            $this->createBookingFromReservation($reservation);
            $payment->update(['payment_status' => 'completed']);
        });
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

            $reservation->payments()
                ->where('payment_stage', 'deposit')
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
        if ($reservation->payment_deadline === null || now()->lt($reservation->payment_deadline)) {
            return;
        }

        $reason = 'Automatically rejected: the required payment was not completed within the 48-hour deadline.';

        DB::transaction(function () use ($reservation, $reason) {
            $reservation->update(['status' => Reservation::STATUS_REJECTED, 'rejection_reason' => $reason]);

            $reservation->payments()
                ->where('payment_stage', 'deposit')
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

    /**
     * Automatically cancels a still-active (awaiting cash or GCash),
     * still-unpaid reservation once its check-in date has reached the
     * configured no-show cutoff (hotel.no_show_checkin_hour + grace hours -
     * see config/hotel.php's docblock). Deliberately not restricted to
     * reservations that never had a 48-hour payment_deadline: a deadline-
     * bearing reservation is normally already expired by expireUnpaid()
     * well before its check-in date arrives, but this is the sole,
     * unconditional catch-all for the short-notice (tomorrow/2-days-out)
     * reservations that are exempt from that deadline entirely - no
     * special-casing needed, this one check covers both. Called both from
     * reservations:process-no-shows and, like expireUnpaid(), as a
     * cron-independent safety net inline from Api\ReservationController/
     * Guest\ReservationController's own index()/show().
     */
    public function processNoShow(Reservation $reservation): void
    {
        if (! in_array($reservation->status, Reservation::ACTIVE_STATUSES, true)) {
            return;
        }

        if ($reservation->payments()->where('payment_status', 'completed')->exists()) {
            return;
        }

        $cutoff = $reservation->check_in->copy()
            ->setTime((int) config('hotel.no_show_checkin_hour', 14), 0)
            ->addHours((int) config('hotel.no_show_grace_hours', 5));

        if (now()->lt($cutoff)) {
            return;
        }

        // Recognizable prefix so the mobile app can render its existing
        // "NO-SHOW" badge (see Android's status_no_show string) instead of
        // a generic Cancelled/Rejected one, without needing a whole new
        // status column - same technique payment_deadline's rejection_reason
        // already uses to distinguish an auto-expiry from a manual reject.
        $reason = 'NO_SHOW: guest did not arrive or complete payment before the check-in deadline.';

        DB::transaction(function () use ($reservation, $reason) {
            $reservation->update(['status' => Reservation::STATUS_CANCELLED, 'rejection_reason' => $reason]);

            $reservation->payments()
                ->where('payment_stage', 'deposit')
                ->where('payment_status', 'pending')
                ->update(['payment_status' => 'failed']);

            AmenityRequest::where('reservation_id', $reservation->id)
                ->where('status', 'pending')
                ->update(['status' => 'rejected']);
        });

        if ($guest = $reservation->guest?->user) {
            $this->notifications->notifyNoShow($guest, $reservation->roomType->name, $reservation->id);
        }
    }

    /**
     * Automatically cancels a still-confirmed Booking (already paid/verified and past
     * receptionist accept - the "will the guest actually show up" stage, distinct from
     * processNoShow()'s unpaid-Reservation check) once its check-in date reaches the same
     * configured no-show cutoff (hotel.no_show_checkin_hour + grace hours). A booking that
     * has already progressed to checked_in/checked_out/cancelled is left alone entirely -
     * "confirmed" is the only status that means "hasn't shown up yet".
     *
     * Uses a conditional atomic UPDATE (not a fetch-then-save like processNoShow() above)
     * so a receptionist checking the guest in at the same moment this runs can never lose
     * the race: the update only takes effect if booking_status is still 'confirmed' at the
     * instant it executes, and the notification/log only fire if a row actually changed.
     *
     * Reuses the exact same "NO_SHOW:" rejection_reason prefix convention as
     * processNoShow() - Android's Booking.isNoShow()/getNoShowReason() already read this
     * generically, and the receptionist web module's rejected tab already filters on
     * booking_status='cancelled', so both surfaces pick this up with no further changes.
     */
    public function processBookingNoShow(Booking $booking): void
    {
        if ($booking->booking_status !== Booking::STATUS_ACTIVE) {
            return;
        }

        $cutoff = $booking->check_in->copy()
            ->setTime((int) config('hotel.no_show_checkin_hour', 14), 0)
            ->addHours((int) config('hotel.no_show_grace_hours', 5));

        if (now()->lt($cutoff)) {
            return;
        }

        $reason = 'NO_SHOW: guest did not arrive within the scheduled check-in period.';

        $affected = Booking::where('id', $booking->id)
            ->where('booking_status', Booking::STATUS_ACTIVE)
            ->update(['booking_status' => Booking::STATUS_CANCELLED, 'rejection_reason' => $reason]);

        if (! $affected) {
            // Lost the race - a receptionist check-in or other update landed first.
            return;
        }

        Activity::log(
            'Auto-cancelled booking',
            "Booking #{$booking->id} for {$booking->roomType->name} - guest did not arrive before the check-in deadline.",
            $booking
        );

        if ($guest = $booking->account_guest?->user) {
            $this->notifications->notifyNoShow($guest, $booking->roomType->name ?? 'your stay', $booking->id);
        }
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

        $available = $this->availability->availableCount($reservation->roomType, $reservation->check_in, $reservation->check_out);
        if ($available < $reservation->rooms_requested) {
            abort(422, $reservation->rooms_requested > 1
                ? "Not enough {$reservation->roomType->name} rooms available for the requested dates (needs {$reservation->rooms_requested}, only {$available} free)."
                : "This room type is fully booked for the requested dates.");
        }

        $booking = DB::transaction(function () use ($reservation, $staff) {
            $booking = $this->createBookingFromReservation($reservation);

            $reservation->payments()
                ->where('payment_stage', 'deposit')
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
            'id_card_image_path' => $reservation->id_card_image_path,
            'discount_requested' => $reservation->discount_requested,
            'discount_verification_status' => $reservation->discount_verification_status,
        ]);

        $reservation->update(['status' => Reservation::STATUS_CONVERTED]);

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

            $reservation->payments()
                ->where('payment_stage', 'deposit')
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
