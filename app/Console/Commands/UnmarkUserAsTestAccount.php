<?php

namespace App\Console\Commands;

use App\Models\ActivityLog;
use App\Models\User;
use Illuminate\Console\Command;

/**
 * Reverses users:mark-test - see that command's own doc for the full
 * authorization/design rationale. Also the correct way to walk back the
 * three specific historically-proven accounts (2026_09_26_010000) if that
 * classification is ever reconsidered - no separate mechanism needed for
 * that case.
 */
class UnmarkUserAsTestAccount extends Command
{
    protected $signature = 'users:unmark-test {user : Numeric id of the user to remove test-account status from} {--force : Skip the confirmation prompt}';

    protected $description = 'Remove the internal/test-account classification from a specific user, restoring it to normal business analytics.';

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

        if (! $user->is_test_account) {
            $this->info("User #{$user->id} ({$user->first_name} {$user->last_name}) is not currently marked as a test account. Nothing to do.");

            return self::SUCCESS;
        }

        $this->table(['Field', 'Value'], [
            ['ID', $user->id],
            ['Name', "{$user->first_name} {$user->last_name}"],
            ['Email', $user->email],
            ['Role', $user->role],
            ['Status', $user->status],
        ]);

        if (! $this->option('force') && ! $this->confirm('Remove test-account status from this account? Its historical transactions will start counting toward real business analytics again.')) {
            $this->info('Cancelled - no change made.');

            return self::SUCCESS;
        }

        $user->forceFill(['is_test_account' => false])->save();
        $this->logAction($user, 'Unmarked test account');

        $this->info("User #{$user->id} is no longer marked as a test account.");

        return self::SUCCESS;
    }

    /** See MarkUserAsTestAccount::logAction()'s identical doc. */
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
