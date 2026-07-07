<?php

namespace App\Services;

use App\Models\Notification;
use App\Models\User;
use Illuminate\Support\Collection;

class NotificationService
{
    /**
     * Send notification to a single user.
     */
    public function toUser(User $user, string $title, string $message, string $category = 'general'): Notification
    {
        return Notification::create([
            'user_id' => $user->id,
            'title' => $title,
            'message' => $message,
            'category' => $category,
        ]);
    }

    /**
     * Send notification to multiple users by role.
     */
    public function toRole(string $role, string $title, string $message, string $category = 'general', ?string $excludeEmail = null): Collection
    {
        $query = User::where('role', $role)->where('status', 'active');

        if ($excludeEmail) {
            $query->where('email', '!=', $excludeEmail);
        }

        $notifications = collect();
        $query->each(function ($user) use ($title, $message, $category, $notifications) {
            $notifications->push(Notification::create([
                'user_id' => $user->id,
                'title' => $title,
                'message' => $message,
                'category' => $category,
            ]));
        });

        return $notifications;
    }

    /**
     * Send notification to all staff (receptionists and managers).
     */
    public function toStaff(string $title, string $message, string $category = 'general', ?string $excludeEmail = null): Collection
    {
        $notifications = collect();

        // Notify receptionists
        $this->toRole('receptionist', $title, $message, $category, $excludeEmail)->each(function ($n) use ($notifications) {
            $notifications->push($n);
        });

        // Notify managers
        $this->toRole('manager', $title, $message, $category, $excludeEmail)->each(function ($n) use ($notifications) {
            $notifications->push($n);
        });

        return $notifications;
    }

    // ============ Booking Notifications ============

    /**
     * Notify about new reservation booking.
     */
    public function notifyNewBooking(User $guest, string $roomName): void
    {
        // Notify guest
        $this->toUser(
            $guest,
            'Reservation Pending',
            "Your reservation for {$roomName} is pending confirmation.",
            'booking'
        );

        // Notify staff
        $this->toStaff(
            'New Reservation',
            "New reservation from {$guest->full_name} for {$roomName} requires confirmation.",
            'booking',
            $guest->email
        );
    }

    /**
     * Notify about confirmed reservation.
     */
    public function notifyReservationConfirmed(User $guest, string $roomName): void
    {
        $this->toUser(
            $guest,
            'Reservation Confirmed',
            "Great news! Your reservation for {$roomName} has been confirmed.",
            'booking'
        );
    }

    /**
     * Notify about cancelled reservation.
     */
    public function notifyReservationCancelled(User $guest, string $roomName): void
    {
        $this->toUser(
            $guest,
            'Reservation Cancelled',
            'Your reservation has been cancelled.',
            'booking'
        );

        $this->toRole(
            'receptionist',
            'Reservation Cancelled',
            "{$guest->full_name} has cancelled their reservation for {$roomName}.",
            'booking',
            $guest->email
        );
    }

    // ============ Check-in/Check-out Notifications ============

    /**
     * Notify about check-in.
     */
    public function notifyCheckIn(User $guest, string $roomName): void
    {
        $this->toUser(
            $guest,
            'Checked In',
            "Welcome! You have been checked into {$roomName}.",
            'check_in'
        );

        $this->toRole(
            'manager',
            'Guest Checked In',
            "{$guest->full_name} has checked into {$roomName}.",
            'check_in'
        );
    }

    /**
     * Notify about check-out.
     */
    public function notifyCheckOut(User $guest, string $roomName): void
    {
        $this->toUser(
            $guest,
            'Checked Out',
            'Thank you for staying with us! Your bill is ready for review.',
            'check_out'
        );

        $this->toRole(
            'manager',
            'Guest Checked Out',
            "{$guest->full_name} has checked out from {$roomName}.",
            'check_out'
        );
    }

    // ============ Payment Notifications ============

    /**
     * Notify about payment received.
     */
    public function notifyPaymentReceived(User $guest, float $amount, ?string $roomName = null): void
    {
        $message = "A payment of ₱" . number_format($amount, 2) . ' has been recorded.';
        if ($roomName) {
            $message .= " ({$roomName})";
        }

        $this->toUser($guest, 'Payment Received', $message, 'payment');
    }

    /**
     * Notify about full payment (receipt available).
     */
    public function notifyPaymentComplete(User $guest): void
    {
        $this->toUser(
            $guest,
            'Payment Complete',
            'Your payment is complete. Thank you for staying with us! Your digital receipt is now available.',
            'payment'
        );

        $this->toRole(
            'manager',
            'Bill Fully Paid',
            "{$guest->full_name} has fully paid their bill.",
            'payment'
        );
    }

    /**
     * Notify manager about payment.
     */
    public function notifyManagerPayment(User $guest, float $amount, string $billStatus, ?string $roomName = null): void
    {
        $message = "Payment of ₱" . number_format($amount, 2) . " recorded for {$guest->full_name}";
        if ($roomName) {
            $message .= " ({$roomName})";
        }
        if ($billStatus === 'paid') {
            $message .= '. Bill fully paid.';
        }

        $this->toRole('manager', 'Payment Recorded', $message, 'payment');
    }
}