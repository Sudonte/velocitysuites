<?php

namespace App\Services;

use App\Models\Announcement;
use App\Models\Booking;
use App\Models\Notification;
use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

class NotificationService
{
    /**
     * Send notification to a single user.
     */
    public function toUser(User $user, string $title, string $message, string $category = 'general', ?int $referenceId = null, ?array $targetAudience = null): Notification
    {
        return Notification::create([
            'user_id' => $user->id,
            'title' => $title,
            'message' => $message,
            'category' => $category,
            'reference_id' => $referenceId,
            'target_audience' => $targetAudience,
        ]);
    }

    /**
     * Send notification to multiple users by role.
     */
    public function toRole(string $role, string $title, string $message, string $category = 'general', ?string $excludeEmail = null, ?int $referenceId = null, ?array $targetAudience = null): Collection
    {
        $query = User::where('role', $role)->where('status', 'active');

        if ($excludeEmail) {
            $query->where('email', '!=', $excludeEmail);
        }

        $notifications = collect();
        $query->each(function ($user) use ($title, $message, $category, $referenceId, $targetAudience, $notifications) {
            $notifications->push(Notification::create([
                'user_id' => $user->id,
                'title' => $title,
                'message' => $message,
                'category' => $category,
                'reference_id' => $referenceId,
                'target_audience' => $targetAudience,
            ]));
        });

        return $notifications;
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
     * Notify about new reservation booking.
     */
    public function notifyNewBooking(User $guest, string $roomName, ?int $referenceId = null): void
    {
        // Notify guest
        $this->toUser(
            $guest,
            'Reservation Pending',
            "Your reservation for {$roomName} is pending confirmation.",
            'booking',
            $referenceId
        );

        // Notify staff
        $this->toStaff(
            'New Reservation',
            "New reservation from {$guest->full_name} for {$roomName} requires confirmation.",
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
            'booking',
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
            'booking',
            $referenceId
        );

        $this->toRole(
            'receptionist',
            'Reservation Cancelled',
            "{$guest->full_name} has cancelled their reservation for {$roomName}.",
            'booking',
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
            'booking',
            $referenceId
        );

        $this->toRole(
            'receptionist',
            'Reservation Expired',
            "A reservation for {$roomName} was automatically cancelled - its 48-hour payment deadline expired unpaid.",
            'booking',
            null,
            $referenceId
        );
    }

    /**
     * Notify about a reservation automatically cancelled as a No-Show -
     * the guest never arrived or paid before the configured check-in
     * cutoff. See ReservationWorkflowService::processNoShow().
     */
    public function notifyNoShow(User $guest, string $roomName, ?int $referenceId = null): void
    {
        $this->toUser(
            $guest,
            'Reservation Cancelled - No Show',
            "Your reservation for {$roomName} was cancelled because you did not arrive before the allowed check-in deadline.",
            'booking',
            $referenceId
        );

        $this->toRole(
            'receptionist',
            'Reservation Cancelled - No Show',
            "A reservation for {$roomName} was automatically cancelled as a No-Show.",
            'booking',
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
            'booking',
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
    public function notifyCheckIn(User $guest, string $roomName, ?int $referenceId = null): void
    {
        $this->toUser(
            $guest,
            'Checked In',
            "Welcome! You have been checked into {$roomName}.",
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
                . "{$checkIn->format('F j, Y')} at {$checkIn->format('g:i A')}. We look forward to welcoming you!",
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
     * Notify about full payment (receipt available).
     */
    public function notifyPaymentComplete(User $guest, ?int $referenceId = null): void
    {
        $this->toUser(
            $guest,
            'Payment Complete',
            'Your payment is complete. Thank you for staying with us! Your digital receipt is now available.',
            'payment',
            $referenceId
        );

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
    public function notifyPaymentVerified(User $guest, float $amount, string $roomName, ?int $referenceId = null): void
    {
        $this->toUser(
            $guest,
            'Payment Verified',
            'Your GCash payment of ₱' . number_format($amount, 2) . " for {$roomName} has been verified. Thank you!",
            'payment',
            $referenceId
        );
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
