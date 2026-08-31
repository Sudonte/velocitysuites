<?php

namespace App\Services;

use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

/**
 * Shared 6-digit OTP password-reset logic for both the web
 * (Auth\ForgotPasswordController) and mobile API (Api\AuthController), so
 * the two flows can't drift. Reuses the framework's own
 * password_reset_tokens table (email primary key, token, created_at) -
 * generating a new OTP always overwrites the previous row via
 * updateOrInsert(), which is the mechanism that invalidates the prior code.
 * 15-minute expiry, checked at verify time.
 *
 * Deliberately unrelated to the registration OTP flow (registration_otps
 * table, Auth\RegisterController / Api\AuthController::issueOtp()) - that's
 * a separate concern with its own 5-minute window and pending-signup
 * payload, not password resets.
 */
class PasswordResetService
{
    public function sendOtp(User $user): void
    {
        $otp = str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT);

        DB::table('password_reset_tokens')->updateOrInsert(
            ['email' => $user->email],
            ['token' => Hash::make($otp), 'created_at' => now()]
        );

        Log::info("Password reset OTP for {$user->email}: {$otp}");

        try {
            $body = "Hi,\n\nYour VelocitySuites password reset code is: {$otp}\n\n"
                . "Enter this code to set a new password. This code expires in 15 minutes.\n\n"
                . "If you didn't request this, you can safely ignore this email.\n\n- VelocitySuites";
            Mail::raw($body, function ($message) use ($user, $otp) {
                $message->to($user->email)->subject("Your VelocitySuites password reset code: {$otp}");
            });
        } catch (\Throwable $e) {
            Log::error("Failed to email password reset OTP to {$user->email}: " . $e->getMessage());
        }
    }

    /**
     * Checks the OTP against the stored hash and its 15-minute expiry.
     * Does NOT delete the token row on success - resetPassword() still
     * needs it, and a non-destructive verify lets the caller show early
     * feedback ("code verified") without committing to a password change.
     * Deletes the row if it's found but expired, so a stale row doesn't
     * linger to be re-checked (and fail) again.
     */
    public function verifyOtp(string $email, string $otp): bool
    {
        $row = DB::table('password_reset_tokens')->where('email', $email)->first();

        if (! $row || ! Hash::check($otp, $row->token)) {
            return false;
        }

        if (now()->diffInMinutes($row->created_at) > 15) {
            DB::table('password_reset_tokens')->where('email', $email)->delete();
            return false;
        }

        return true;
    }

    public function resetPassword(User $user, string $password): void
    {
        $user->update([
            'password' => $password,
            'failed_login_attempts' => 0,
        ]);

        DB::table('password_reset_tokens')->where('email', $user->email)->delete();
    }
}
