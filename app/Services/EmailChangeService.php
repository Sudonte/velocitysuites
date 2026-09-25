<?php

namespace App\Services;

use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

/**
 * 6-digit OTP email-change flow for the mobile API (Api\ProfileController).
 * Mirrors PasswordResetService's shape/conventions (hashed OTP, 15-minute
 * expiry via created_at) but is a deliberately separate table/purpose -
 * an email-change OTP must never satisfy a password-reset check or vice
 * versa, and one user changing their own email must never be usable to
 * verify a different action on a different account.
 *
 * The OTP is sent to the PROPOSED new address (proving the requester
 * actually controls the destination they are switching to) - the current
 * address already had its say via the required current-password check in
 * Api\ProfileController::requestEmailChange().
 */
class EmailChangeService
{
    public function requestChange(User $user, string $newEmail): void
    {
        $otp = str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT);

        DB::table('email_change_requests')->updateOrInsert(
            ['user_id' => $user->id],
            ['new_email' => $newEmail, 'otp' => Hash::make($otp), 'created_at' => now()]
        );

        Log::info("Email change OTP requested for user {$user->id} -> {$newEmail}");

        try {
            $body = "Hi,\n\nYou requested to change your VelocitySuites registered email to this address.\n\n"
                . "Your verification code is: {$otp}\n\n"
                . "Enter this code in the app to confirm the change. This code expires in 15 minutes.\n\n"
                . "If you didn't request this, you can safely ignore this email - your account email will not change.\n\n- VelocitySuites";
            Mail::raw($body, function ($message) use ($newEmail, $otp) {
                $message->to($newEmail)->subject("Confirm your new VelocitySuites email: {$otp}");
            });
        } catch (\Throwable $e) {
            Log::error("Failed to email change-of-email OTP to {$newEmail}: " . $e->getMessage());
        }
    }

    /**
     * Verifies the OTP and, only if valid/unexpired/still-unique, applies
     * the change and clears the pending request. Also best-effort notifies
     * the OLD address afterward so the real owner notices if this wasn't
     * them - never blocks the change on that notification succeeding.
     *
     * @return array{success: bool, message: string}
     */
    public function confirmChange(User $user, string $otp): array
    {
        $row = DB::table('email_change_requests')->where('user_id', $user->id)->first();

        if (! $row || ! Hash::check($otp, $row->otp)) {
            return ['success' => false, 'message' => 'Invalid verification code.'];
        }

        // now()->diffInMinutes() returns a value signed the opposite of
        // what it looks like here (confirmed against production: a
        // genuinely-past created_at came back NEGATIVE), so "> 15" was
        // never true for any real expired row - this OTP effectively
        // never expired. isPast() has no such sign ambiguity.
        if (\Carbon\Carbon::parse($row->created_at)->addMinutes(15)->isPast()) {
            DB::table('email_change_requests')->where('user_id', $user->id)->delete();
            return ['success' => false, 'message' => 'Verification code has expired. Please request a new one.'];
        }

        $oldEmail = $user->email;
        $newEmail = $row->new_email;

        // Re-check uniqueness at confirm time too - the address could have
        // been claimed by someone else in the window since the request was
        // first made.
        if (User::where('email', $newEmail)->where('id', '!=', $user->id)->exists()) {
            DB::table('email_change_requests')->where('user_id', $user->id)->delete();
            return ['success' => false, 'message' => 'That email address is already registered to another account.'];
        }

        DB::transaction(function () use ($user, $newEmail) {
            $user->update(['email' => $newEmail]);
            DB::table('email_change_requests')->where('user_id', $user->id)->delete();
        });

        try {
            Mail::raw(
                "Hi,\n\nYour VelocitySuites registered email was changed from {$oldEmail} to {$newEmail}.\n\n"
                . "If you didn't make this change, please contact the System Administrator immediately.\n\n- VelocitySuites",
                function ($message) use ($oldEmail) {
                    $message->to($oldEmail)->subject('Your VelocitySuites email address was changed');
                }
            );
        } catch (\Throwable $e) {
            Log::error("Failed to email change-of-email notice to old address {$oldEmail}: " . $e->getMessage());
        }

        return ['success' => true, 'message' => 'Email address updated successfully.'];
    }
}
