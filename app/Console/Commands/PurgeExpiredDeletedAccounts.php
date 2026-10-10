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
            Log::info("Purging expired deleted account: user_id={$user->id}");
            $user->apiTokens()->delete();

            // A guest with reservations / bookings / payments keeps them: they are proof for the hotel's accounting
            // (and the foreign keys refuse to cascade them away). Only the PERSON goes - name, contact details and
            // login are scrubbed and the account is closed; the records stay attached to an anonymous guest.
            $guest = $user->guest;
            if ($guest && ($guest->reservations()->exists() || \App\Models\Booking::withTrashed()->where('guest_id', $guest->id)->exists())) {
                $guest->forceFill([
                    'mobile_number' => null, 'address' => null, 'profile_picture' => null,
                    'date_of_birth' => null, 'age' => null, 'gender' => null,
                ])->save();
                $user->forceFill([
                    'first_name' => 'Deleted', 'last_name' => 'Guest', 'middle_name' => null,
                    'email' => 'deleted-guest-'.$user->id.'@invalid.local',
                    'password' => bcrypt(\Illuminate\Support\Str::random(40)),
                    'status' => 'deactivated', 'restore_deadline' => null,
                ])->save();
                $this->warn("User {$user->id} kept as an anonymous account - their transactions are retained.");

                continue;
            }

            $guest?->delete();
            $user->delete();
        }

        $this->info("Purged {$expired->count()} expired account(s).");

        return self::SUCCESS;
    }
}
