<?php

namespace App\Console\Commands;

use App\Support\StaysBackfill;
use Illuminate\Console\Command;

/** Safe to run any time: gives a stay to every booking / reservation that has none (see App\Support\StaysBackfill). */
class BackfillStays extends Command
{
    protected $signature = 'stays:backfill';

    protected $description = 'Give every booking and reservation without a stay row its single stay (idempotent).';

    public function handle(): int
    {
        $made = StaysBackfill::run();
        $this->info("Stays created: {$made['bookings']} for bookings, {$made['reservations']} for reservations.");

        return self::SUCCESS;
    }
}
