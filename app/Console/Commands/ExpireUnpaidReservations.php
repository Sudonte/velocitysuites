<?php

namespace App\Console\Commands;

use App\Models\Reservation;
use App\Services\ReservationWorkflowService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

/**
 * Automatically rejects a Pay Later/Pay Now reservation whose 48-hour
 * payment deadline (Reservation::payment_deadline - only applies once
 * check_in is 2+ days out, see that accessor's docblock) has passed with
 * no completed payment. A lazy, cron-independent safety-net version of
 * this same check also runs inline from Api\ReservationController/
 * Guest\ReservationController before returning a reservation (see
 * ReservationWorkflowService::expireUnpaid()'s docblock), so this command
 * running late or being missed for a day isn't a correctness problem -
 * same "not a safety gate" relationship accounts:purge-expired has to its
 * own login-time check.
 */
class ExpireUnpaidReservations extends Command
{
    protected $signature = 'reservations:expire-unpaid';

    protected $description = 'Automatically reject Pay Later/Pay Now reservations whose 48-hour payment deadline has passed unpaid.';

    public function handle(ReservationWorkflowService $workflow): int
    {
        $candidates = Reservation::whereIn('status', Reservation::ACTIVE_STATUSES)
            ->whereDoesntHave('payments', fn ($q) => $q->where('payment_status', 'completed'))
            ->with(['roomType', 'guest.user', 'payments'])
            ->get()
            ->filter(fn (Reservation $r) => $r->payment_deadline !== null && now()->gte($r->payment_deadline));

        foreach ($candidates as $reservation) {
            Log::info("Expiring unpaid reservation: id={$reservation->id}, deadline={$reservation->payment_deadline}");
            $workflow->expireUnpaid($reservation);
        }

        $this->info("Expired {$candidates->count()} unpaid reservation(s) past their payment deadline.");

        return self::SUCCESS;
    }
}
