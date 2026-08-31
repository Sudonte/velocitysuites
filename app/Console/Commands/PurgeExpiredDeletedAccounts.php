<?php

namespace App\Console\Commands;

use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

/**
 * Permanently removes guest accounts whose 30-day restore window (see
 * Api\ProfileController::deleteAccount/restoreAccount) has passed. Login
 * already refuses these accounts as soon as the deadline passes
 * (Api\AuthController::login), so this command is the actual data-retention
 * cleanup step, not a safety gate - it's fine if it runs late or misses a
 * day.
 */
class PurgeExpiredDeletedAccounts extends Command
{
    protected $signature = 'accounts:purge-expired';

    protected $description = 'Permanently delete guest accounts whose 30-day account-deletion restore window has expired.';

    public function handle(): int
    {
        $expired = User::whereNotNull('deleted_at')
            ->whereNotNull('restore_deadline')
            ->where('restore_deadline', '<', now())
            ->get();

        foreach ($expired as $user) {
            Log::info("Purging expired deleted account: user_id={$user->id}, email={$user->email}");
            $user->apiTokens()->delete();
            $user->guest?->delete();
            $user->delete();
        }

        $this->info("Purged {$expired->count()} expired account(s).");

        return self::SUCCESS;
    }
}
