<?php

namespace App\Console\Commands;

use App\Models\ActivityLog;
use App\Models\Booking;
use App\Models\User;
use Illuminate\Console\Command;

/**
 * Marks one booking (and the reservation it came from, if any) as test data,
 * excluding it from reports and dashboards - the booking-level twin of
 * users:mark-test, for walk-in test bookings that have no guest account to
 * flag. Exact numeric id only, never bulk; the record stays fully visible in
 * detail and audit views. See App\Support\TestAccountScope.
 */
class MarkBookingAsTest extends Command
{
    protected $signature = 'bookings:mark-test {booking : Numeric id of the booking to classify as test data} {--force : Skip the confirmation prompt}';

    protected $description = 'Explicitly mark a specific booking as test data, excluding it from business analytics.';

    public function handle(): int
    {
        return self::setFlag($this, true);
    }

    /** Shared by bookings:mark-test and bookings:unmark-test. */
    public static function setFlag(Command $command, bool $flag): int
    {
        $raw = $command->argument('booking');
        if (! ctype_digit((string) $raw)) {
            $command->error('The booking argument must be a plain numeric id.');

            return self::FAILURE;
        }

        $booking = Booking::withTrashed()->find((int) $raw);
        if (! $booking) {
            $command->error("No booking found with id {$raw}.");

            return self::FAILURE;
        }

        if ((bool) $booking->is_test_booking === $flag) {
            $command->info("Booking #{$booking->id} is already " . ($flag ? 'marked' : 'not marked') . ' as test data. Nothing to do.');

            return self::SUCCESS;
        }

        $command->table(['Field', 'Value'], [
            ['Booking', "#{$booking->id}"],
            ['Reservation', $booking->reservation_id ? "#{$booking->reservation_id}" : '-'],
            ['Guest', $booking->guest_display_name],
            ['Account', $booking->account_guest?->user?->email ?? '(walk-in, no account)'],
            ['Stay', $booking->check_in?->toDateString() . ' to ' . $booking->check_out?->toDateString()],
            ['Status', $booking->booking_status],
        ]);

        $question = $flag
            ? 'Mark this booking as test data? Reports and dashboards will exclude it; detail/audit views still show it.'
            : 'Remove the test-data mark? Reports and dashboards will include it again.';
        if (! $command->option('force') && ! $command->confirm($question)) {
            $command->info('Cancelled - no change made.');

            return self::SUCCESS;
        }

        $booking->forceFill(['is_test_booking' => $flag])->save();

        if ($admin = User::where('role', 'admin')->first()) {
            ActivityLog::create([
                'user_id' => $admin->id,
                'action' => $flag ? 'Marked test booking' : 'Unmarked test booking',
                'description' => "Booking #{$booking->id} ({$booking->guest_display_name}) via `php artisan {$command->getName()} {$booking->id}` (console, not a web session)",
                'subject_type' => 'booking',
                'subject_id' => $booking->id,
            ]);
        }

        $command->info("Booking #{$booking->id} is now " . ($flag ? 'marked' : 'no longer marked') . ' as test data.');

        return self::SUCCESS;
    }
}
