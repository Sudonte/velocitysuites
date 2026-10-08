<?php

namespace App\Console\Commands;

use App\Models\Booking;
use Carbon\Carbon;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * One-off, safe, re-runnable fix for COMPLETED bookings (and the reservations
 * that became them) whose Transaction Timeline has steps with no recorded time
 * (checked_in_at / checked_out_at / completed_at were never stored before the
 * 2026_10_08_000002 migration, so the guest app showed those steps as Pending).
 *
 * Rules, deliberately conservative:
 *  - only bookings whose booking_status is COMPLETED_BOOKING are touched;
 *  - only columns that are currently NULL are filled - a value that already
 *    exists is never overwritten, so running this twice changes nothing more;
 *  - payments, billings, receipts and every other table are NOT modified (a
 *    'completed' payment already counts as verified in the timeline);
 *  - the filled times are best-available estimates from data the system did
 *    record (room check-out times, the paid billing's timestamp, the stay's
 *    check-in date), kept in order check-in <= check-out <= completion, and
 *    every change is written to the application log;
 *  - --dry-run prints what would change without writing anything.
 */
class BackfillCompletedTimeline extends Command
{
    protected $signature = 'timeline:backfill-completed {--dry-run : Show what would change without writing}';

    protected $description = 'Fill missing check-in / check-out / completion / discount-verification times on COMPLETED bookings.';

    public function handle(): int
    {
        $dry = (bool) $this->option('dry-run');
        $changed = 0;
        $untouched = 0;

        Booking::where('booking_status', Booking::STATUS_COMPLETED)->orderBy('id')->chunkById(100, function ($bookings) use ($dry, &$changed, &$untouched) {
            foreach ($bookings as $booking) {
                $updates = $this->missingTimes($booking);
                if ($updates === []) {
                    $untouched++;

                    continue;
                }

                $changed++;
                $this->line(($dry ? '[dry-run] ' : '')."Booking #{$booking->id}: ".json_encode($updates));
                if (! $dry) {
                    // Raw query update: leaves updated_at (the stay's real last-change time) alone.
                    DB::table('bookings')->where('id', $booking->id)->update($updates);
                    Log::info('timeline:backfill-completed filled booking times', ['booking_id' => $booking->id, 'filled' => $updates]);
                }
            }
        });

        $this->info(($dry ? 'Would update ' : 'Updated ')."{$changed} completed booking(s); {$untouched} already complete.");

        return self::SUCCESS;
    }

    /** @return array<string,string> column => 'Y-m-d H:i:s' (UTC) for each currently-NULL timeline column */
    public function missingTimes(Booking $booking): array
    {
        $billing = $booking->billing;
        $lastRoomOut = DB::table('booking_rooms')->where('booking_id', $booking->id)->max('checked_out_at');

        // The earlier of "the paid bill was last touched" and "the booking was last touched":
        // either alone can be pushed later by unrelated maintenance (e.g. a receipt-number
        // backfill that bumped many billings' updated_at), the earlier one is the closer
        // estimate of when the stay was actually closed out.
        $paidAt = $billing && $billing->billing_status === 'paid' ? $billing->updated_at : null;
        $candidates = array_filter([$paidAt, $booking->updated_at]);
        $completedAt = $booking->completed_at
            ?? (count($candidates) ? collect($candidates)->map(fn ($c) => Carbon::parse($c))->min() : $booking->created_at);
        $checkedOutAt = $booking->checked_out_at
            ?? ($lastRoomOut ? Carbon::parse($lastRoomOut) : null)
            ?? $completedAt;
        $checkedInAt = $booking->checked_in_at
            ?? $booking->check_in
            ?? $booking->verified_at
            ?? $booking->created_at;

        $checkedOutAt = Carbon::parse($checkedOutAt);
        $completedAt = Carbon::parse($completedAt);
        $checkedInAt = Carbon::parse($checkedInAt);

        // Order: check-in <= check-out <= completion, and nothing before the booking existed.
        if ($completedAt->lt($checkedOutAt)) {
            $completedAt = $checkedOutAt->copy();
        }
        if ($checkedInAt->gt($checkedOutAt)) {
            $checkedInAt = Carbon::parse($booking->verified_at ?? $booking->created_at);
            if ($checkedInAt->gt($checkedOutAt)) {
                $checkedInAt = $checkedOutAt->copy();
            }
        }
        if ($booking->created_at && $checkedInAt->lt($booking->created_at) && Carbon::parse($booking->created_at)->lte($checkedOutAt)) {
            $checkedInAt = Carbon::parse($booking->created_at);
        }

        $updates = [];
        if ($booking->checked_in_at === null) {
            $updates['checked_in_at'] = $checkedInAt->utc()->toDateTimeString();
        }
        if ($booking->checked_out_at === null) {
            $updates['checked_out_at'] = $checkedOutAt->utc()->toDateTimeString();
        }
        if ($booking->completed_at === null) {
            $updates['completed_at'] = $completedAt->utc()->toDateTimeString();
        }
        if ($booking->discount_requested
            && $booking->discount_verified_at === null
            && $booking->discount_verification_status === 'approved') {
            $verifiedAt = $billing?->discount_verified_at ?? $completedAt;
            $updates['discount_verified_at'] = Carbon::parse($verifiedAt)->utc()->toDateTimeString();
        }

        return $updates;
    }
}
