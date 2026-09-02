<?php

namespace App\Console\Commands;

use App\Models\Reservation;
use App\Services\NotificationService;
use Illuminate\Console\Command;

/**
 * Reminds a guest their 48-hour payment_deadline (see
 * Reservation::getPaymentDeadlineAttribute()) is within the configured
 * reminder window and hasn't been reminded yet. Stamps
 * payment_reminder_sent_at so a reservation is only ever reminded once,
 * regardless of how many times this runs before the deadline passes (or the
 * reservation is expired by reservations:expire-unpaid).
 */
class SendPaymentReminders extends Command
{
    protected $signature = 'reservations:send-payment-reminders';

    protected $description = 'Notify guests whose 48-hour reservation payment deadline is approaching.';

    /** Hours before the deadline that a reminder should fire. */
    private const REMINDER_WINDOW_HOURS = 6;

    public function handle(NotificationService $notifications): int
    {
        $candidates = Reservation::whereIn('status', Reservation::ACTIVE_STATUSES)
            ->whereNull('payment_reminder_sent_at')
            ->whereDoesntHave('payments', fn ($q) => $q->where('payment_status', 'completed'))
            ->with(['roomType', 'guest.user'])
            ->get()
            ->filter(function (Reservation $r) {
                if ($r->payment_deadline === null) {
                    return false;
                }
                $deadline = \Carbon\Carbon::parse($r->payment_deadline);

                return now()->gte($deadline->copy()->subHours(self::REMINDER_WINDOW_HOURS)) && now()->lt($deadline);
            });

        foreach ($candidates as $reservation) {
            if ($guest = $reservation->guest?->user) {
                $notifications->notifyPaymentDeadlineReminder($guest, $reservation->roomType->name, $reservation->id);
            }
            $reservation->payment_reminder_sent_at = now();
            $reservation->save();
        }

        $this->info("Sent {$candidates->count()} payment deadline reminder(s).");

        return self::SUCCESS;
    }
}
