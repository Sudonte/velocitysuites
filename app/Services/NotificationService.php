<?php

namespace App\Services;

use App\Models\Announcement;
use App\Models\Booking;
use App\Models\Notification;
use App\Models\Promotion;
use App\Models\User;
use App\Support\StayBill;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

class NotificationService
{
    /**
     * Send notification to a single user.
     *
     * $receiptNumber/$receiptType are additive, optional metadata - always an
     * already-minted receipt_number (Payment::ensureReceiptNumber()/
     * Billing::ensureOfficialReceiptNumber(), called by the caller BEFORE
     * reaching here) and its matching PARTIAL_RECEIPT/FULL_PAYMENT_RECEIPT/
     * OFFICIAL_RECEIPT type string - this method never generates or infers
     * either. Both stay null for every non-payment notification and for a
     * payment notification whose payment wasn't receipt-eligible, exactly as
     * before this metadata existed - see PAYMENT_RECEIPT_HISTORY_BACKEND_SPEC.md
     * Phase 5 notification-integration section.
     */
    public function toUser(User $user, string $title, string $message, string $category = 'general', ?int $referenceId = null, ?array $targetAudience = null, ?string $receiptNumber = null, ?string $receiptType = null): Notification
    {
        $notification = Notification::create([
            'user_id' => $user->id,
            'title' => $title,
            'message' => $message,
            'category' => $category,
            'reference_id' => $referenceId,
            'target_audience' => $targetAudience,
            'receipt_number' => $receiptNumber,
            'receipt_type' => $receiptType,
        ]);

        Notification::forgetUnreadCountFor($user->id);

        return $notification;
    }

    /**
     * Send notification to multiple users by role. Bulk-inserted in one
     * query (chunked) rather than one Notification::create() per matching
     * user - with dozens/hundreds of guests, the old per-row loop meant a
     * role-wide broadcast (an announcement, "New Reservation" to every
     * receptionist, etc.) issued that many sequential INSERTs inside the
     * request that triggered it, blocking whoever published/booked/checked
     * someone in until every single one finished. Returns an empty
     * Collection - no caller uses the created rows themselves (fire-and-
     * forget), so there's nothing worth re-selecting them for.
     */
    public function toRole(string $role, string $title, string $message, string $category = 'general', ?string $excludeEmail = null, ?int $referenceId = null, ?array $targetAudience = null): Collection
    {
        $query = User::where('role', $role)->where('status', 'active');

        if ($excludeEmail) {
            $query->where('email', '!=', $excludeEmail);
        }

        // Raw insert() bypasses Eloquent's date-cast/mutator pipeline
        // entirely (unlike create()), so timestamps must already be
        // DB-ready strings here, not Carbon instances.
        $now = now()->toDateTimeString();
        $targetAudienceJson = $targetAudience !== null ? json_encode($targetAudience) : null;

        $query->select('id')->chunkById(500, function ($users) use ($title, $message, $category, $referenceId, $targetAudienceJson, $now) {
            $rows = $users->map(fn ($user) => [
                'user_id' => $user->id,
                'title' => $title,
                'message' => $message,
                'category' => $category,
                'reference_id' => $referenceId,
                'target_audience' => $targetAudienceJson,
                'is_read' => false,
                'created_at' => $now,
                'updated_at' => $now,
            ])->all();

            Notification::insert($rows);

            foreach ($users as $user) {
                Notification::forgetUnreadCountFor($user->id);
            }
        });

        return collect();
    }

    /**
     * Send notification to all staff (receptionists and managers).
     */
    public function toStaff(string $title, string $message, string $category = 'general', ?string $excludeEmail = null, ?int $referenceId = null): Collection
    {
        $notifications = collect();

        // Notify receptionists
        $this->toRole('receptionist', $title, $message, $category, $excludeEmail, $referenceId)->each(function ($n) use ($notifications) {
            $notifications->push($n);
        });

        // Notify managers
        $this->toRole('manager', $title, $message, $category, $excludeEmail, $referenceId)->each(function ($n) use ($notifications) {
            $notifications->push($n);
        });

        return $notifications;
    }

    /**
     * Notify the System Administrator role about an admin-relevant event -
     * account changes, room updates, reservation activity, system
     * warnings. Deliberately a small, curated set of call sites (see
     * their docblocks) rather than every event toStaff() already covers -
     * admin doesn't need a ping for every guest booking, only things
     * outside day-to-day front-desk/manager operations.
     */
    public function notifyAdmin(string $title, string $message, string $category = 'general', ?int $referenceId = null): Collection
    {
        return $this->toRole('admin', $title, $message, $category, null, $referenceId);
    }

    // ============ Booking Notifications ============

    /**
     * Notify about a new reservation (Pay Later / awaiting-confirmation
     * path) - never a direct "New Booking" purchase, see
     * notifyNewDirectBooking() for that. category='reservation' so it never
     * gets mixed up with a genuinely separate Booking-lifecycle event -
     * see notifyNewDirectBooking()'s own docblock for the historical bug
     * this split fixes.
     */
    public function notifyNewBooking(User $guest, string $roomName, ?int $referenceId = null): void
    {
        // Notify guest
        $this->toUser(
            $guest,
            'Reservation Pending',
            "Your reservation for {$roomName} is pending confirmation.",
            'reservation',
            $referenceId
        );

        // Notify staff
        $this->toStaff(
            'New Reservation',
            "New reservation from {$guest->full_name} for {$roomName} requires confirmation.",
            'reservation',
            $guest->email,
            $referenceId
        );
    }

    /**
     * Notify about a new direct "New Booking" purchase (Api\BookingController::store(),
     * the mobile pay-first path - see Services\DirectBookingService's docblock) - a
     * genuinely different transaction from a Reservation, never derived from one.
     * Distinct title ("Booking Pending" vs notifyNewBooking()'s "Reservation Pending")
     * and category ('booking' vs 'reservation') so a guest's notification feed can
     * never mislabel one as the other - this call site used to reuse
     * notifyNewBooking() and always said "Reservation Pending" even for a direct
     * Booking purchase, which was wrong.
     */
    public function notifyNewDirectBooking(User $guest, string $roomName, ?int $referenceId = null): void
    {
        $this->toUser(
            $guest,
            'Booking Pending',
            "Your booking for {$roomName} is pending confirmation.",
            'booking',
            $referenceId
        );

        $this->toStaff(
            'New Booking',
            "New booking from {$guest->full_name} for {$roomName} requires confirmation.",
            'booking',
            $guest->email,
            $referenceId
        );
    }

    /**
     * Notify about confirmed reservation.
     */
    public function notifyReservationConfirmed(User $guest, string $roomName, ?int $referenceId = null): void
    {
        $this->toUser(
            $guest,
            'Reservation Confirmed',
            "Great news! Your reservation for {$roomName} has been confirmed.",
            'reservation',
            $referenceId
        );
    }

    /**
     * Notify the guest that their own edit (the one-time "Modify" action -
     * see Api\ReservationController::update()/Guest\ReservationController::update(),
     * both gated by Reservation::edited_at) to a still-pending reservation was
     * saved - the reservation itself doesn't change status here, only its
     * dates/room/guest details, so this is deliberately a lighter-weight
     * guest-only notice (no staff broadcast) rather than reusing
     * notifyNewBooking()'s "requires confirmation" staff ping again.
     */
    public function notifyReservationModified(User $guest, string $roomName, ?int $referenceId = null): void
    {
        $this->toUser(
            $guest,
            'Reservation Updated',
            "Your reservation for {$roomName} has been updated.",
            'reservation',
            $referenceId
        );
    }

    /**
     * Notify about cancelled reservation.
     */
    public function notifyReservationCancelled(User $guest, string $roomName, ?int $referenceId = null): void
    {
        $this->toUser(
            $guest,
            'Reservation Cancelled',
            'Your reservation has been cancelled.',
            'reservation',
            $referenceId
        );

        $this->toRole(
            'receptionist',
            'Reservation Cancelled',
            "{$guest->full_name} has cancelled their reservation for {$roomName}.",
            'reservation',
            $guest->email,
            $referenceId
        );
    }

    /**
     * Notify about a cancelled direct Booking (the mobile "New Booking"
     * pay-first path, never derived from a Reservation) - distinct title/
     * wording from notifyReservationCancelled() above so a guest and
     * receptionist never see a Booking mislabeled as a Reservation, per
     * the spec's Booking-vs-Reservation notification distinction.
     */
    public function notifyBookingCancelled(User $guest, string $roomName, ?int $referenceId = null): void
    {
        $this->toUser(
            $guest,
            'Booking Cancelled',
            'Your booking has been cancelled.',
            'booking',
            $referenceId
        );

        $this->toRole(
            'receptionist',
            'Booking Cancelled',
            "{$guest->full_name} has cancelled their booking for {$roomName}.",
            'booking',
            $guest->email,
            $referenceId
        );
    }

    /**
     * Notify about a reservation automatically cancelled because its
     * 48-hour payment deadline passed unpaid - distinct wording from
     * notifyReservationCancelled() (a guest's own voluntary cancel) so the
     * guest understands this happened automatically, not by their own
     * action. See ReservationWorkflowService::expireUnpaid().
     */
    public function notifyReservationExpired(User $guest, string $roomName, ?int $referenceId = null): void
    {
        $this->toUser(
            $guest,
            'Reservation Expired',
            "Your reservation for {$roomName} was cancelled because the 48-hour payment deadline expired.",
            'reservation',
            $referenceId
        );

        $this->toRole(
            'receptionist',
            'Reservation Expired',
            "A reservation for {$roomName} was automatically cancelled - its 48-hour payment deadline expired unpaid.",
            'reservation',
            null,
            $referenceId
        );
    }

    /**
     * Remind the guest their 48-hour payment deadline is approaching -
     * fired once per reservation by reservations:send-payment-reminders
     * (guarded by Reservation::payment_reminder_sent_at so it never repeats).
     */
    public function notifyPaymentDeadlineReminder(User $guest, string $roomName, ?int $referenceId = null): void
    {
        $this->toUser(
            $guest,
            'Payment Deadline Approaching',
            "Complete your payment for {$roomName} before the deadline to avoid automatic cancellation of your reservation.",
            'reservation',
            $referenceId
        );
    }

    /**
     * A receptionist rejected an already-converted Booking with feedback
     * (see Receptionist\BookingController::reject()) - the guest-facing
     * counterpart of notifyPaymentRejected(), for a whole-booking
     * rejection rather than a single payment attempt.
     */
    public function notifyBookingRejected(User $guest, string $roomName, string $reason, ?int $referenceId = null): void
    {
        $this->toUser(
            $guest,
            'Booking Rejected',
            "Your booking for {$roomName} was rejected: {$reason}",
            'booking',
            $referenceId
        );
    }

    // ============ Check-in/Check-out Notifications ============

    /**
     * Notify about check-in.
     */
    public function notifyCheckIn(User $guest, string $roomName, ?int $referenceId = null, ?Booking $booking = null): void
    {
        // The guest is told both ends of the stay: today's check-in and the check-out day they booked.
        $dates = $booking
            ? ' Your stay: ' . $booking->check_in->format('M j, Y') . ' to ' . $booking->check_out->format('M j, Y')
                . ' (' . StayBill::nightsLabel(max(1, (int) $booking->check_in->copy()->startOfDay()->diffInDays($booking->check_out->copy()->startOfDay()))) . ').'
            : '';
        $this->toUser(
            $guest,
            'Checked In',
            "Welcome! You have been checked into {$roomName}.{$dates}",
            'check_in',
            $referenceId
        );

        $this->toRole(
            'manager',
            'Guest Checked In',
            "{$guest->full_name} has checked into {$roomName}.",
            'check_in',
            null,
            $referenceId
        );
    }

    /**
     * The guest's stay ended on different dates than the ones they booked (a late check-out bills the extra
     * nights, an early one only the nights stayed): tell them the dates, the nights and the amount that now apply.
     * $stay is App\Support\StayBill - the same figures their Payment Receipt shows.
     */
    public function notifyStayUpdated(User $guest, string $roomName, array $stay, ?int $referenceId = null): void
    {
        $fmt = fn (string $d) => \Carbon\Carbon::parse($d)->format('M j, Y');
        $nights = StayBill::nightsLabel($stay['actual_nights']);
        $was = StayBill::nightsLabel($stay['scheduled_nights']);
        $extra = $stay['extra_nights'] > 0
            ? ' ' . StayBill::nightsLabel($stay['extra_nights']) . ' extra ' . ($stay['extra_nights'] === 1 ? 'was' : 'were') . ' added to your bill.'
            : '';

        $this->toUser(
            $guest,
            'Your Stay Dates Changed',
            "Your stay in {$roomName} is now {$fmt($stay['check_in'])} to {$fmt($stay['actual_check_out'])} ({$nights}, booked as {$was})."
                . "{$extra} Updated total: ₱" . number_format($stay['total'], 2) . '.',
            'check_out',
            $referenceId
        );
    }

    /**
     * Notify about check-out.
     */
    public function notifyCheckOut(User $guest, string $roomName, ?int $referenceId = null): void
    {
        $this->toUser(
            $guest,
            'Checked Out',
            'Thank you for staying with us! Your bill is ready for review.',
            'check_out',
            $referenceId
        );

        $this->toRole(
            'manager',
            'Guest Checked Out',
            "{$guest->full_name} has checked out from {$roomName}.",
            'check_out',
            null,
            $referenceId
        );
    }

    /**
     * Notify about an upcoming check-in on an already-confirmed booking.
     * Distinct from notifyCheckIn(), which fires at actual check-in time -
     * this fires in advance (see Console\Commands\SendCheckinReminders).
     * Reference id/number matches the guest-facing convention used
     * elsewhere ("Reservation #{id}", see guest/reservations/show.blade.php)
     * even though the booking itself has its own row, since that's how
     * guests already know this stay by the time it's converted.
     */
    public function notifyCheckinReminder(Booking $booking): void
    {
        $guest = $booking->account_guest?->user;
        if (! $guest) {
            return;
        }
        $checkIn = $booking->check_in;

        // A reservation-derived booking is still known to the guest by its
        // Reservation #; a direct "New Booking" transaction (reservation_id
        // null) has no reservation at all, so it's referenced by its own
        // Booking # instead.
        $referenceLabel = $booking->reservation_id
            ? "Reservation #{$booking->reservation_id}"
            : "Booking #{$booking->id}";

        $this->toUser(
            $guest,
            'Upcoming Check-In Reminder',
            "{$referenceLabel} for {$booking->roomType->name} is scheduled to check in on "
                . "{$checkIn->format('F j, Y')} at {$checkIn->format('g:i A')} and check out on {$booking->check_out->format('F j, Y')}. We look forward to welcoming you!",
            'checkin_reminder',
            // notifications.reference_id has no FK constraint (relax_
            // notifications_reference_id_constraint migration), so it can
            // hold either a Reservation id or a Booking id - the Booking id
            // for a direct "New Booking" transaction (no reservation at all).
            $booking->reservation_id ?? $booking->id
        );
    }

    // ============ Payment Notifications ============

    /**
     * Notify about payment received.
     */
    public function notifyPaymentReceived(User $guest, float $amount, ?string $roomName = null, ?int $referenceId = null): void
    {
        $message = "A payment of ₱" . number_format($amount, 2) . ' has been recorded.';
        if ($roomName) {
            $message .= " ({$roomName})";
        }

        $this->toUser($guest, 'Payment Received', $message, 'payment', $referenceId);
    }

    /**
     * Notify about full payment (receipt available). Always OFFICIAL_RECEIPT
     * when $receiptNumber is present - this only ever fires from
     * Receptionist\CheckOutController::recordPayment()'s checkout-completion
     * branch, right after Billing::ensureOfficialReceiptNumber() mints it, so
     * there is no other receipt type this call site could ever mean.
     */
    public function notifyPaymentComplete(User $guest, ?int $referenceId = null, ?string $receiptNumber = null): void
    {
        $message = 'Your payment is complete. Thank you for staying with us! Your Official Payment Receipt'
            . ($receiptNumber ? " ({$receiptNumber})" : '')
            . ' is now available.';

        $this->toUser($guest, 'Payment Complete', $message, 'payment', $referenceId, null,
            $receiptNumber, $receiptNumber ? 'OFFICIAL_RECEIPT' : null);

        $this->toRole(
            'manager',
            'Bill Fully Paid',
            "{$guest->full_name} has fully paid their bill.",
            'payment',
            null,
            $referenceId
        );
    }

    /**
     * Notify about a guest-submitted payment claim (e.g. GCash) awaiting
     * staff verification - distinct from notifyPaymentReceived, which is
     * for a payment staff already recorded/confirmed themselves.
     */
    public function notifyPaymentSubmitted(User $guest, float $amount, string $roomName, ?int $referenceId = null): void
    {
        $this->toUser(
            $guest,
            'Payment Pending Validation',
            'Your payment of ₱' . number_format($amount, 2) . " for {$roomName} has been submitted and is pending validation.",
            'payment',
            $referenceId
        );

        $this->toStaff(
            'Payment Submitted for Review',
            "{$guest->full_name} submitted a payment of ₱" . number_format($amount, 2) . " for {$roomName} - needs verification.",
            'payment',
            $guest->email,
            $referenceId
        );
    }

    /**
     * Notify the guest that a receptionist has verified their submitted
     * GCash payment - the counterpart to notifyPaymentSubmitted() above.
     * Title contains "Verified" so the mobile app's NotificationStatusResolver
     * (substring match on the title) resolves the correct status pill
     * without needing a structured notification type field.
     */
    public function notifyPaymentVerified(User $guest, float $amount, string $roomName, ?int $referenceId = null, ?string $receiptNumber = null, string $receiptLabel = 'Partial Payment Receipt', ?string $receiptType = null): void
    {
        $message = 'Your GCash payment of ₱' . number_format($amount, 2) . " for {$roomName} has been verified. Thank you!";
        // $receiptNumber is optional/backward-compatible - stays plain
        // wording if this payment somehow wasn't eligible for a receipt at
        // all (e.g. a ₱0 payment) - see Payment::ensureReceiptNumber().
        // $receiptLabel lets the caller (Receptionist\PaymentController::
        // verify()) say "Partial Payment Receipt" or the generic "Payment
        // Receipt" depending on Payment::isFullPaymentReceiptEligible() - a
        // verified 100% pre-checkout payment must never be announced as
        // "Partial" (see Payment::qualifiesForNewPreCheckoutReceipt()'s doc).
        // $receiptType is the same eligibility result's machine-readable form
        // (Payment::receiptType() - 'PARTIAL_RECEIPT'/'FULL_PAYMENT_RECEIPT'),
        // computed by the caller from the same already-minted receipt_number -
        // never re-derived here from $receiptLabel's free text.
        if ($receiptNumber) {
            $message .= " Your {$receiptLabel} ({$receiptNumber}) is now available.";
        }

        $this->toUser($guest, 'Payment Verified', $message, 'payment', $referenceId, null, $receiptNumber, $receiptType);
    }

    /**
     * Notify the guest that a receptionist has rejected their submitted
     * GCash payment, including the reason, so they can correct and
     * resubmit (the mobile app's existing "Resubmit Payment" action on
     * PaymentActivity handles the resubmit half). Title contains
     * "Rejected" for the same NotificationStatusResolver reason as above.
     */
    public function notifyPaymentRejected(User $guest, float $amount, string $roomName, string $reason, ?int $referenceId = null): void
    {
        $this->toUser(
            $guest,
            'Payment Rejected',
            'Your GCash payment of ₱' . number_format($amount, 2) . " for {$roomName} was rejected: {$reason}. Please correct and resubmit your payment.",
            'payment',
            $referenceId
        );
    }

    /**
     * Notify the guest that a receptionist recorded a new additional
     * charge (damage, lost item, mini bar, etc.) against their bill,
     * including the resulting outstanding balance so it's clear whether
     * anything is now payable - see Receptionist\CheckOutController::
     * storeAdditionalCharge().
     */
    public function notifyAdditionalCharge(User $guest, string $description, float $amount, float $balanceDue, ?int $referenceId = null): void
    {
        $message = "A charge of ₱" . number_format($amount, 2) . " ({$description}) was added to your bill.";
        if ($balanceDue > 0.009) {
            $message .= ' Outstanding balance: ₱' . number_format($balanceDue, 2) . '.';
        }

        $this->toUser($guest, 'Additional Charge Added', $message, 'payment', $referenceId);
    }

    /**
     * Notify manager about payment.
     */
    public function notifyManagerPayment(User $guest, float $amount, string $billStatus, ?string $roomName = null, ?int $referenceId = null): void
    {
        $message = "Payment of ₱" . number_format($amount, 2) . " recorded for {$guest->full_name}";
        if ($roomName) {
            $message .= " ({$roomName})";
        }
        if ($billStatus === 'paid') {
            $message .= '. Bill fully paid.';
        }

        $this->toRole('manager', 'Payment Recorded', $message, 'payment', null, $referenceId);
    }

    // ============ Promotion Notifications ============

    /**
     * Notify every active guest that a promotion went live - the guest-facing
     * counterpart of notifyAnnouncement() below, for Admin\PromotionManagementController's
     * notifyIfActive() guard (fires once per promotion, the first time it's actually
     * active - see that guard's own docblock). Guests only: unlike Announcement,
     * Promotion has no target_audience concept - it's a guest-facing marketing
     * campaign, never staff-relevant.
     */
    public function notifyPromotion(Promotion $promotion): void
    {
        $message = Str::limit((string) $promotion->description, 5000);

        $this->toRole('guest', $promotion->promo_name, $message, 'promotion', null, $promotion->id);
    }

    // ============ Announcement Notifications ============

    /**
     * Notify every user in each of the announcement's targeted roles
     * (guest/manager/receptionist - 'public' has no account to notify) that
     * a new announcement went live. The full title and full content are
     * stored as-is (capped at a generous length purely as a storage safety
     * bound, not a real-world truncation) so every recipient's notification
     * is a complete, self-contained snapshot - it never needs to re-fetch
     * the live Announcement row, which may later be edited or deleted.
     * target_audience carries the resolved role list (never the announcement's
     * own possibly-null value) so every recipient sees the same concrete
     * "who this was sent to" list regardless of which role they are.
     */
    public function notifyAnnouncement(Announcement $announcement): void
    {
        $message = Str::limit($announcement->content, 5000);
        $roles = $announcement->notifiableRoles();

        foreach ($roles as $role) {
            $this->toRole($role, $announcement->title, $message, 'announcement', null, null, $roles);
        }
    }
}
