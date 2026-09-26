<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * The original backfill (2026_09_26_010000_add_is_test_account_to_users_table)
 * correctly classified this production database's own developer-test
 * cluster, but did so partly via three hardcoded user ids (8, 10, 28) -
 * safe here (verified against real evidence before being hardcoded), but
 * NOT safe if that same migration file is ever run against a different
 * database (a fresh dev/CI setup, a staging environment, or a restore
 * where ids don't correspond to this production history at all) - those
 * specific ids could belong to any ordinary, unrelated guest there.
 *
 * This migration does not touch the original one (already deployed and
 * recorded as run - rewriting deployed migration history would create a
 * real inconsistency between what production already ran and what a
 * fresh environment would run instead). It re-derives the same
 * classification from portable evidence computed fresh from whichever
 * database this actually runs against, in both directions:
 *
 * 1. Un-flags any of those three ids if THIS database's own data doesn't
 *    actually back up the classification (protects a database where
 *    those ids are unrelated real guests).
 * 2. Flags (id-independent) any guest account whose own reservation count
 *    is the same kind of gross outlier - every genuine guest anywhere in
 *    this project's real history has at most 1 or 2; the confirmed test
 *    cluster has 11/55/93. A threshold of 10 sits with a wide margin on
 *    both sides.
 *
 * On THIS production database the net effect is nothing changes - the
 * same three accounts already correctly flagged remain flagged, backed
 * now by live-recomputed evidence instead of a fixed id list.
 */
return new class extends Migration
{
    private const OUTLIER_RESERVATION_THRESHOLD = 10;

    public function up(): void
    {
        DB::table('users')
            ->where('role', 'guest')
            ->where('is_test_account', true)
            ->whereIn('id', [8, 10, 28])
            ->get(['id'])
            ->each(function ($user) {
                $guestId = DB::table('guests')->where('user_id', $user->id)->value('id');
                $reservationCount = $guestId ? DB::table('reservations')->where('guest_id', $guestId)->count() : 0;
                if ($reservationCount < self::OUTLIER_RESERVATION_THRESHOLD) {
                    DB::table('users')->where('id', $user->id)->update(['is_test_account' => false]);
                }
            });

        DB::table('users')
            ->where('role', 'guest')
            ->where('is_test_account', false)
            ->whereIn('id', function ($query) {
                $query->select('guests.user_id')
                    ->from('guests')
                    ->join('reservations', 'reservations.guest_id', '=', 'guests.id')
                    ->groupBy('guests.user_id')
                    ->havingRaw('COUNT(*) >= ?', [self::OUTLIER_RESERVATION_THRESHOLD]);
            })
            ->update(['is_test_account' => true]);
    }

    public function down(): void
    {
        // Deliberately a no-op - this migration only re-derives/corrects
        // flag values the original migration already set from its own
        // evidence; there is nothing distinct here to reverse.
    }
};
