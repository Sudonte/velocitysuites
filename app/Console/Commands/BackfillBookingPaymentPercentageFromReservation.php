<?php

namespace App\Console\Commands;

use App\Models\Booking;
use App\Models\Reservation;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * One-time historical repair for a since-fixed bug in Api\PaymentController::
 * store(): the reservation's own selected_payment_percentage/
 * required_payment_amount used to be persisted AFTER recordDepositPayment()
 * already ran (which synchronously auto-converts a GCash reservation into a
 * Booking via ReservationWorkflowService::tryAutoConvert() ->
 * createBookingFromReservation(), snapshotting those two fields onto the
 * new Booking row at that exact moment) - every auto-converted Booking
 * created before that ordering fix landed got NULL for both fields
 * regardless of what the guest actually selected, even though the source
 * Reservation's OWN copy of those fields (persisted correctly, just too
 * late for the snapshot to see) is still sitting there, unaffected, as the
 * authoritative record of the guest's real choice.
 *
 * This command ONLY copies Reservation -> Booking for rows where:
 *   - the Booking's own selected_payment_percentage is NULL (never
 *     overwrites an existing value, valid or not - see --dry-run's report
 *     for anything that looks wrong instead of touching it), AND
 *   - the linked Reservation has one of the 5 supported values (20/30/40/
 *     50/100) - never inferred, never guessed, never derived from a
 *     required_payment_amount/grand-total ratio (audited separately and
 *     confirmed there are zero rows where that ratio-recovery path would
 *     even apply, since required_payment_amount is always NULL exactly
 *     when selected_payment_percentage is, on both tables - they're set
 *     together in the same update() call).
 *
 * A direct "New Booking" (reservation_id NULL) or a Booking whose OWN
 * linked Reservation also has a NULL percentage is left untouched - there
 * is no authoritative source to recover from for those, and this command
 * does not guess.
 */
class BackfillBookingPaymentPercentageFromReservation extends Command
{
    protected $signature = 'bookings:backfill-payment-percentage {--dry-run : Preview affected rows without writing any changes}';

    protected $description = 'Backfill Booking.selected_payment_percentage/required_payment_amount from the linked Reservation for rows the pre-fix conversion-ordering bug left NULL.';

    public function handle(): int
    {
        $dryRun = (bool) $this->option('dry-run');

        $candidates = Booking::query()
            ->whereNull('selected_payment_percentage')
            ->whereNotNull('reservation_id')
            ->with('reservation')
            ->get()
            ->filter(function (Booking $booking) {
                $pct = $booking->reservation?->selected_payment_percentage;

                return $pct !== null && in_array((int) $pct, [20, 30, 40, 50, 100], true);
            });

        if ($candidates->isEmpty()) {
            $this->info('No recoverable Booking rows found - nothing to do.');

            return self::SUCCESS;
        }

        $this->info(($dryRun ? '[DRY RUN] ' : '') . "Found {$candidates->count()} Booking row(s) recoverable from their linked Reservation:");

        $rows = [];
        foreach ($candidates as $booking) {
            $reservation = $booking->reservation;
            $rows[] = [
                $booking->id,
                $reservation->id,
                (int) $reservation->selected_payment_percentage,
                $booking->required_payment_amount === null ? 'NULL -> ' . number_format((float) $reservation->required_payment_amount, 2) : (string) $booking->required_payment_amount . ' (kept, already set)',
            ];
        }
        $this->table(['Booking #', 'Reservation #', 'Percentage to set', 'required_payment_amount'], $rows);

        if ($dryRun) {
            $this->comment('Dry run - no changes written. Re-run without --dry-run to apply.');

            return self::SUCCESS;
        }

        $updated = 0;
        DB::transaction(function () use ($candidates, &$updated) {
            foreach ($candidates as $booking) {
                $reservation = $booking->reservation;
                $updates = [
                    'selected_payment_percentage' => (int) $reservation->selected_payment_percentage,
                ];
                // Only fill required_payment_amount if the Booking doesn't
                // already have its own value - never overwrite an existing
                // one, per the conservative "only touch true NULLs" rule.
                if ($booking->required_payment_amount === null && $reservation->required_payment_amount !== null) {
                    $updates['required_payment_amount'] = $reservation->required_payment_amount;
                }

                Booking::whereKey($booking->id)->update($updates);
                $updated++;
            }
        });

        $this->info("Backfilled {$updated} Booking row(s).");

        return self::SUCCESS;
    }
}
