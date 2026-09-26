<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Distinguishes confirmed internal/developer test accounts from real
 * guests so business analytics (revenue, occupancy, booking/reservation
 * counts, dashboard charts, exports) can exclude them without ever
 * hardcoding a specific account id/email at query time - see
 * App\Support\TestAccountScope, the single place every exclusion query
 * lives. Defaults false for everyone; only an authorized admin workflow
 * (Admin\UserManagementController - see that controller's own change in
 * this same commit) may flip it going forward. Never used to gate login,
 * suspend, or hide a record from its own detail/audit views - a flagged
 * account behaves identically to a real one everywhere except aggregate
 * reporting.
 *
 * One-time backfill below, evidence-based rather than a raw id list
 * wherever a generic rule was actually possible:
 * - email containing "test" or ending in the placeholder domain
 *   @example.invalid catches every account created by an audit session's
 *   own throwaway-test convention (e.g. "zztest.guesta...@example.invalid",
 *   "calc-test-...@example.invalid") - a rule that also protects against
 *   the same pattern recurring in the future, not just today's rows.
 * - the classic John/Jane Doe placeholder name pair.
 * - three specific accounts (ids 8, 10, 28) that cannot be heuristically
 *   detected any other way (real-looking names/emails) but were proven
 *   via manual investigation to be the project's own repeated real-device
 *   testing identity: 93/11/55 reservations respectively against a total
 *   of 170 reservations system-wide (every other guest account has at
 *   most 1), all three created within the project's first six weeks, and
 *   the same real name ("John Paul (Abe) Ombid" / "Jeepy Abe") recurring
 *   across all three plus this project's own prior audit-session history.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->boolean('is_test_account')->default(false)->after('status');
        });

        // Scoped to role=guest throughout - a placeholder-looking name or
        // email is only actually evidence of throwaway test data for a
        // self-registered guest account. A staff account (admin/manager/
        // receptionist) is provisioned by another administrator, not
        // self-registered, so the same heuristics don't apply - confirmed
        // live during this migration's own review: one receptionist
        // account happened to be named "John Doe" for real (a genuine,
        // actively-used staff account, not test data) and would have been
        // wrongly flagged without this guard.
        DB::table('users')
            ->where('role', 'guest')
            ->where(function ($q) {
                $q->where('email', 'like', '%test%')
                    ->orWhere('email', 'like', '%@example.invalid')
                    ->orWhere(function ($q2) {
                        $q2->where('first_name', 'John')->where('last_name', 'Doe');
                    })
                    ->orWhere(function ($q2) {
                        $q2->where('first_name', 'Jane')->where('last_name', 'Doe');
                    })
                    ->orWhereIn('id', [8, 10, 28]);
            })
            ->update(['is_test_account' => true]);
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('is_test_account');
        });
    }
};
