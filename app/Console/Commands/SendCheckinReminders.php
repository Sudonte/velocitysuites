<?php

namespace App\Console\Commands;

use App\Models\Booking;
use App\Services\NotificationService;
use Illuminate\Console\Command;

/**
 * Generates a check-in reminder notification for every confirmed booking
 * whose check-in falls within the next N hours and hasn't been reminded
 * yet. checkin_reminder_sent_at is the dedup marker - once set, a booking
 * is never reminded again, so re-running this command (manually, or on
 * whatever cadence the schedule fires) is always safe.
 *
 * Requires a real cron entry pointed at `php artisan schedule:run` (see
 * routes/console.php) - Hostinger shared hosting doesn't expose crontab
 * over SSH, so this must be added through hPanel's Cron Jobs section.
 */
class SendCheckinReminders extends Command
{
    protected $signature = 'reservations:send-checkin-reminders';

    protected $description = 'Send a check-in reminder notification for each confirmed booking checking in soon';

    public function handle(NotificationService $notifications): int
    {
        $windowHours = (int) config('reservations.checkin_reminder_hours_before', 24);

        $bookings = Booking::where('booking_status', Booking::STATUS_ACTIVE)
            ->whereNull('checkin_reminder_sent_at')
            ->whereBetween('check_in', [now(), now()->addHours($windowHours)])
            ->with(['reservation.guest.user', 'roomType'])
            ->get();

        foreach ($bookings as $booking) {
            $notifications->notifyCheckinReminder($booking);

            // Direct attribute assignment (not update()) - deliberately
            // not in Booking's $fillable since it's a system-managed
            // marker, never user-supplied.
            $booking->checkin_reminder_sent_at = now();
            $booking->save();
        }

        $this->info("Sent {$bookings->count()} check-in reminder(s).");

        return self::SUCCESS;
    }
}
