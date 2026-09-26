<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Withdraws the reservation-count-based auto-classification introduced by
 * the immediately-prior migration (2026_09_26_020000), which added it as
 * a well-intentioned portability fix for THAT migration's own hardcoded
 * id list - but in doing so introduced a real, separate problem: "count
 * >= 10" is a behavioral prediction, not a trusted fact. A genuine,
 * loyal, high-frequency real guest could organically reach that count
 * over time and would be silently excluded from business analytics with
 * no one ever deciding that should happen. High activity must never be
 * suficient evidence of test-account status.
 *
 * Does not edit either prior migration (already deployed to production -
 * rewriting deployed migration history would make a fresh environment's
 * migration run diverge from what production already recorded). Instead
 * re-validates every currently-flagged guest account against ONLY the two
 * mechanisms this project actually trusts:
 * - the three specific accounts independently proven via extensive manual
 *   investigation in 2026_09_26_010000 (93/55/11 reservations each,
 *   documented across many audit sessions as the same real development
 *   identity) - explicitly preserved, never touched here.
 * - the narrow, generic email/name pattern that migration also used
 *   ("test" substring, @example.invalid domain, the literal "John Doe"/
 *   "Jane Doe" name pair) - a real customer cannot organically end up
 *   matching this the way they can organically accumulate reservations,
 *   so it stays a legitimate one-time historical signal.
 *
 * Any OTHER currently-flagged guest account - which can only mean
 * 2026_09_26_020000's count-based rule flagged it - is un-flagged here.
 * On this production database this is a verified no-op (all 8 flagged
 * accounts already match one of the two trusted signals above); on any
 * other database (fresh dev/CI/staging/restore) this is exactly the
 * corrective safety net that prevents a real high-volume guest from
 * ending up silently misclassified there.
 *
 * Going forward, the ONLY ways to set this flag are: this project's own
 * historical migrations (already run), an explicit seeder/factory state
 * (see DatabaseSeeder's own demo guest, updated in this same commit to
 * self-mark rather than rely on the name-pattern rule to catch it), or
 * the authorized `php artisan users:mark-test`/`users:unmark-test`
 * commands added in this same commit. Nothing infers this flag from
 * reservation count, booking count, revenue, or any other behavioral
 * signal, ever again.
 */
return new class extends Migration
{
    private const KNOWN_HISTORICAL_IDS = [8, 10, 28];

    public function up(): void
    {
        DB::table('users')
            ->where('role', 'guest')
            ->where('is_test_account', true)
            ->whereNotIn('id', self::KNOWN_HISTORICAL_IDS)
            ->get(['id', 'first_name', 'last_name', 'email'])
            ->each(function ($user) {
                $email = strtolower((string) $user->email);
                $matchesEmailPattern = str_contains($email, 'test') || str_ends_with($email, '@example.invalid');
                $matchesDoePair = ($user->first_name === 'John' && $user->last_name === 'Doe')
                    || ($user->first_name === 'Jane' && $user->last_name === 'Doe');

                if (! $matchesEmailPattern && ! $matchesDoePair) {
                    DB::table('users')->where('id', $user->id)->update(['is_test_account' => false]);
                }
            });
    }

    public function down(): void
    {
        // Deliberately a no-op - see up()'s own doc. There is nothing
        // distinct to reverse; this migration only removes flags that
        // should never have been considered trustworthy in the first
        // place (pure reservation-count inference).
    }
};
