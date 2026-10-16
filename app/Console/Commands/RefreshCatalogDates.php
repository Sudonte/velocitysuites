<?php

namespace App\Console\Commands;

use App\Models\Discount;
use App\Models\Promotion;
use Illuminate\Console\Command;

/**
 * Daily: switch off one-time promotions and discounts whose end date has
 * passed, and move each yearly promotion's start/end dates on to its next
 * occurrence once the current one is over.
 */
class RefreshCatalogDates extends Command
{
    protected $signature = 'catalog:refresh-dates';

    protected $description = 'Deactivate expired promotions/discounts and roll yearly promotions to their next dates.';

    public function handle(): int
    {
        $today = \App\Support\CheckInWindow::date();

        $promotions = Promotion::where('is_recurring', false)->where('status', 'active')
            ->whereDate('end_date', '<', $today)->update(['status' => 'inactive']);
        $discounts = Discount::where('status', 'active')->whereNotNull('end_date')
            ->whereDate('end_date', '<', $today)->update(['status' => 'inactive']);

        $rolled = 0;
        Promotion::where('is_recurring', true)->whereDate('end_date', '<', $today)->get()
            ->each(function (Promotion $promotion) use (&$rolled) {
                $promotion->syncRecurringDates();
                $promotion->save();
                $rolled++;
            });

        $this->info("Deactivated {$promotions} promotion(s) and {$discounts} discount(s); moved {$rolled} yearly promotion(s) to their next dates.");

        return self::SUCCESS;
    }
}
