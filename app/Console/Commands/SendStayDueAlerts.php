<?php

namespace App\Console\Commands;

use App\Services\StayAlerts;
use Illuminate\Console\Command;

/**
 * Tells the receptionists about stays past 12 NN check-out or 2 PM check-in. Safe to run on any cadence: each stay is only told once.
 * The same sweep also runs from the staff pages (StayAlerts::sweepThrottled()), so it works even before cron is set up.
 */
class SendStayDueAlerts extends Command
{
    protected $signature = 'stays:send-due-alerts';

    protected $description = 'Notify receptionists of stays past 12 NN check-out or 2 PM check-in';

    public function handle(StayAlerts $alerts): int
    {
        [$out, $in] = $alerts->sweep();
        $this->info("Sent {$out} check-out and {$in} check-in alert(s).");

        return self::SUCCESS;
    }
}
