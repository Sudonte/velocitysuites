<?php

namespace App\Console\Commands;

use App\Models\Reservation;
use App\Services\ReservationWorkflowService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

/**
 * Automatically cancels a still-active (pending_review/ready_for_booking),
 * still-unpaid reservation once its check-in date has reached the
 * configured no-show cutoff (see ReservationWorkflowService::processNoShow()'s
 * docblock and config/hotel.php's no_show_checkin_hour/no_show_grace_hours).
 * A lazy, cron-independent safety-net version of this same check also runs
 * inline from Api\ReservationController/Guest\ReservationController before
 * returning a reservation, so this command running late or being missed for
 * a day isn't a correctness problem - same relationship
 * reservations:expire-unpaid has to its own inline safety net.
 */
class ProcessNoShows extends Command
{
    protected $signature = 'reservations:process-no-shows';

    protected $description = 'Automatically cancel reservations that reached their configured no-show check-in cutoff still unpaid.';

    public function handle(ReservationWorkflowService $workflow): int
    {
        $candidates = Reservation::whereIn('status', ['pending_review', 'ready_for_booking'])
            ->whereDoesntHave('payments', fn ($q) => $q->where('payment_status', 'completed'))
            ->whereDate('check_in', '<=', now()->toDateString())
            ->with(['roomType', 'guest.user', 'payments'])
            ->get();

        $processed = 0;
        foreach ($candidates as $reservation) {
            $before = $reservation->status;
            $workflow->processNoShow($reservation);
            if ($reservation->fresh()->status !== $before) {
                Log::info("Cancelled no-show reservation: id={$reservation->id}, check_in={$reservation->check_in}");
                $processed++;
            }
        }

        $this->info("Processed {$processed} no-show reservation(s).");

        return self::SUCCESS;
    }
}
