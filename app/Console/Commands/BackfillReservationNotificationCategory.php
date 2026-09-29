<?php

namespace App\Console\Commands;

use App\Models\Booking;
use App\Models\Notification;
use App\Models\Reservation;
use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * One-off historical data correction for the Booking-vs-Reservation
 * notification category bug fixed in NotificationService (see that class's
 * notifyNewBooking()/notifyNewDirectBooking()/notifyNoShow()/
 * notifyBookingNoShow() docblocks) - every notification created before that
 * fix used category='booking' for both a true Reservation-lifecycle event
 * and a true direct-Booking-lifecycle event, distinguishable only by title
 * text and, for the one ambiguous title, by which table reference_id
 * actually resolves against.
 *
 * Deliberately scoped to guest-role notifications only (531 rows confirmed
 * in production at the time this command was written) - staff-role
 * notifications share the same historical mislabeling but recategorizing
 * them is a separate, not-yet-authorized cleanup.
 *
 * Only ever changes `category`, never `title` - a historical notification's
 * displayed text is left exactly as it was originally written; only which
 * filter bucket it appears under is corrected.
 *
 * Idempotent: only touches rows still at category='booking' matching the
 * known title set below, so re-running after a partial failure (or after
 * the real run, to confirm zero rows remain) is a safe no-op. Always run
 * --dry-run first and compare its per-title counts against the answer key
 * this command's own docblock and the project's plan document already
 * established before running for real.
 */
class BackfillReservationNotificationCategory extends Command
{
    protected $signature = 'notifications:backfill-reservation-category {--dry-run : Only report what would change, write nothing}';

    protected $description = 'Recategorize historical guest notifications mislabeled category=booking into booking vs reservation';

    /** Titles that are always reservation-only, regardless of reference_id - their sole historical call site was always reservation-specific. */
    private const ALWAYS_RESERVATION = [
        'Reservation Cancelled',
        'Reservation Confirmed',
        'Reservation Accepted',
        'Payment Deadline Approaching',
        'Reservation Expired',
        'Reservation Rejected',
    ];

    /** Titles that are already correctly 'booking' - listed only so the dry-run report accounts for every row it selects; never actually written. */
    private const ALREADY_CORRECT_BOOKING = [
        'Booking Cancelled',
    ];

    /** Titles produced by a call site that served both Reservation and Booking creation - needs a per-row reference_id cross-check, defaulting to reservation when the linked record no longer exists in either table (permanently deleted by the guest since). */
    private const NEEDS_CROSS_CHECK = [
        'Reservation Pending',
        'Reservation Cancelled - No Show',
    ];

    public function handle(): int
    {
        $dryRun = (bool) $this->option('dry-run');

        $guestIds = User::where('role', 'guest')->pluck('id');

        $allTitles = [...self::ALWAYS_RESERVATION, ...self::ALREADY_CORRECT_BOOKING, ...self::NEEDS_CROSS_CHECK];

        $rows = Notification::whereIn('user_id', $guestIds)
            ->where('category', 'booking')
            ->whereIn('title', $allTitles)
            ->get(['id', 'title', 'reference_id']);

        $refIds = $rows->pluck('reference_id')->filter()->unique();
        $reservationIds = Reservation::whereIn('id', $refIds)->pluck('id')->flip();
        $bookingIds = Booking::whereIn('id', $refIds)->pluck('id')->flip();

        $toReservation = [];
        $alreadyCorrectCount = 0;
        $crossCheckConfirmedReservation = 0;
        $crossCheckConfirmedBooking = 0;
        $crossCheckUnresolvedDefaulted = 0;

        foreach ($rows as $row) {
            if (in_array($row->title, self::ALWAYS_RESERVATION, true)) {
                $toReservation[] = $row->id;
                continue;
            }

            if (in_array($row->title, self::ALREADY_CORRECT_BOOKING, true)) {
                $alreadyCorrectCount++;
                continue;
            }

            // NEEDS_CROSS_CHECK
            if ($row->reference_id !== null && isset($reservationIds[$row->reference_id])) {
                $toReservation[] = $row->id;
                $crossCheckConfirmedReservation++;
            } elseif ($row->reference_id !== null && isset($bookingIds[$row->reference_id])) {
                $alreadyCorrectCount++; // already 'booking', stays 'booking', no write needed
                $crossCheckConfirmedBooking++;
            } else {
                $toReservation[] = $row->id; // best-effort default - linked record no longer exists in either table
                $crossCheckUnresolvedDefaulted++;
            }
        }

        $this->info('Per-title row counts (guest role, category=booking, matching the known title set):');
        foreach ($rows->countBy('title')->sortDesc() as $title => $count) {
            $this->line("  {$title}: {$count}");
        }

        $this->newLine();
        $this->info('Resolution summary:');
        $this->line('  Total rows examined: ' . $rows->count());
        $this->line('  Already correctly booking (no write): ' . $alreadyCorrectCount);
        $this->line('    of which cross-check-confirmed booking: ' . $crossCheckConfirmedBooking);
        $this->line('  To be recategorized -> reservation: ' . count($toReservation));
        $this->line('    of which cross-check-confirmed reservation: ' . $crossCheckConfirmedReservation);
        $this->line('    of which unresolved, defaulted to reservation: ' . $crossCheckUnresolvedDefaulted);

        if ($dryRun) {
            $this->newLine();
            $this->info('Dry run - no rows written.');

            return self::SUCCESS;
        }

        if (empty($toReservation)) {
            $this->info('Nothing to write - all matching rows are already correctly categorized.');

            return self::SUCCESS;
        }

        DB::transaction(function () use ($toReservation) {
            Notification::whereIn('id', $toReservation)->update(['category' => 'reservation']);
        });

        $this->newLine();
        $this->info('Backfill complete. Final category distribution for guest-role notifications:');
        $after = Notification::whereIn('user_id', $guestIds)
            ->selectRaw('category, count(*) as c')
            ->groupBy('category')
            ->orderByDesc('c')
            ->pluck('c', 'category');
        foreach ($after as $category => $count) {
            $this->line("  {$category}: {$count}");
        }

        return self::SUCCESS;
    }
}
