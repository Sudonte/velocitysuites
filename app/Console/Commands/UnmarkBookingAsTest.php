<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;

/** Reverses bookings:mark-test - see MarkBookingAsTest. */
class UnmarkBookingAsTest extends Command
{
    protected $signature = 'bookings:unmark-test {booking : Numeric id of the booking to remove the test-data mark from} {--force : Skip the confirmation prompt}';

    protected $description = 'Remove the test-data mark from a specific booking, including it in business analytics again.';

    public function handle(): int
    {
        return MarkBookingAsTest::setFlag($this, false);
    }
}
