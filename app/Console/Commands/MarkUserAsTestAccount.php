<?php

namespace App\Console\Commands;

use App\Models\ActivityLog;
use App\Models\User;
use Illuminate\Console\Command;

/**
 * The one authorized, explicit way to classify a specific guest account
 * as internal/test data outside a one-time historical migration or an
 * explicit seeder/factory state - see App\Support\TestAccountScope's own
 * doc, and the 2026_09_26_030000 migration's doc for why reservation
 * count/activity volume is deliberately never treated as evidence here.
 * Requires an exact numeric user id (never a name/email search, never a
 * bulk operation) so the operator sees exactly the one account being
 * changed, with its real current role/status, before confirming. No
 * public/guest-facing route exists for this - console access to this
 * host is itself the authorization boundary.
 */
class MarkUserAsTestAccount extends Command
{
    protected $signature = 'users:mark-test {user : Numeric id of the user to classify as a test account} {--force : Skip the confirmation prompt}';

    protected $description = 'Explicitly mark a specific guest account as an internal/test account, excluding it from business analytics.';

    public function handle(): int
    {
        $raw = $this->argument('user');
        if (! ctype_digit((string) $raw)) {
            $this->error('The user argument must be a plain numeric id.');

            return self::FAILURE;
        }

        $user = User::find((int) $raw);
        if (! $user) {
            $this->error("No user found with id {$raw}.");

            return self::FAILURE;
        }

        if ($user->role !== 'guest') {
            $this->error("User #{$user->id} is a '{$user->role}' account, not a guest. is_test_account only affects guest-side business analytics and has no meaningful effect on a staff account - refusing.");

            return self::FAILURE;
        }

        if ($user->is_test_account) {
            $this->info("User #{$user->id} ({$user->first_name} {$user->last_name}) is already marked as a test account. Nothing to do.");

            return self::SUCCESS;
        }

        $this->table(['Field', 'Value'], [
            ['ID', $user->id],
            ['Name', "{$user->first_name} {$user->last_name}"],
            ['Email', $user->email],
            ['Role', $user->role],
            ['Status', $user->status],
            ['Created', $user->created_at],
        ]);

        if (! $this->option('force') && ! $this->confirm('Mark this account as an internal/test account? It stays fully visible in detail/audit views, only business analytics will exclude it.')) {
            $this->info('Cancelled - no change made.');

            return self::SUCCESS;
        }

        $user->forceFill(['is_test_account' => true])->save();
        $this->logAction($user, 'Marked test account');

        $this->info("User #{$user->id} is now marked as a test account.");

        return self::SUCCESS;
    }

    /**
     * activity_logs.user_id is NOT NULL, and this command has no
     * authenticated web/API session to attribute the action to - logged
     * against an existing admin account (whichever is found first) with
     * the description making explicit this was a console action, not a
     * real request by that specific admin. Skipped entirely (not fatal)
     * if no admin account exists at all.
     */
    private function logAction(User $user, string $action): void
    {
        $admin = User::where('role', 'admin')->first();
        if (! $admin) {
            return;
        }

        ActivityLog::create([
            'user_id' => $admin->id,
            'action' => $action,
            'description' => "User #{$user->id} ({$user->first_name} {$user->last_name}, {$user->email}) via `php artisan {$this->getName()} {$user->id}` (console, not a web session)",
            'subject_type' => 'user',
            'subject_id' => $user->id,
        ]);
    }
}
