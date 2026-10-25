<?php

namespace App\Services;

use App\Models\User;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;

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
    /** Wrong codes allowed per OTP; the code is destroyed on the last one and a new one must be requested. */
    public const MAX_ATTEMPTS = 5;

    /** How long the one-time token issued after a verified OTP stays usable. */
    public const RESET_TOKEN_TTL_MINUTES = 10;

    /** Minimum gap between two codes for the same email (enforced by Api\AuthController::forgotPassword()). */
    public const RESEND_COOLDOWN_SECONDS = 30;

    private function attemptsKey(string $email): string
    {
        return 'pwreset:attempts:' . sha1(strtolower($email));
    }

    private function tokenKey(string $plainToken): string
    {
        return 'pwreset:token:' . hash('sha256', $plainToken);
    }

    /** True once MAX_ATTEMPTS wrong codes were entered against the current OTP. */
    public function tooManyAttempts(string $email): bool
    {
        return (int) Cache::get($this->attemptsKey($email), 0) >= self::MAX_ATTEMPTS;
    }

    /**
     * A short-lived, single-use token proving this email's OTP was verified. The authenticated
     * password-change endpoint refuses to run without one (see Api\AuthController::changePasswordWithToken()).
     * Only a hash of it is used as the cache key, and it is bound to the email it was issued for.
     */
    public function issueResetToken(string $email): string
    {
        $plain = Str::random(48);
        Cache::put($this->tokenKey($plain), strtolower($email), now()->addMinutes(self::RESET_TOKEN_TTL_MINUTES));

        return $plain;
    }

    /** Cache key remembering which code was already traded for a reset token. */
    private function verifiedKey(string $email): string
    {
        return 'pw_otp_verified:'.hash('sha256', strtolower($email));
    }

    /** True when this exact code was already verified (and so already issued a token) - a code is good for one verification. */
    public function alreadyVerified(string $email, string $otp): bool
    {
        $seen = Cache::get($this->verifiedKey($email));

        return is_string($seen) && hash_equals($seen, hash('sha256', $otp));
    }

    public function markVerified(string $email, string $otp): void
    {
        Cache::put($this->verifiedKey($email), hash('sha256', $otp), now()->addMinutes(15));
    }

    /** Consumes the token (it works exactly once) and checks it was issued for this email. */
    public function consumeResetToken(string $plainToken, string $email): bool
    {
        $bound = Cache::pull($this->tokenKey($plainToken));

        return is_string($bound) && hash_equals($bound, strtolower($email));
    }

    /**
     * Returns whether the OTP email was actually handed off successfully.
     * Callers that show the guest a definite "code sent" message (as
     * opposed to the deliberately non-committal "if that email is
     * registered..." API response, which must stay identical either way
     * to avoid account enumeration - see Api\AuthController::forgotPassword())
     * need this to avoid lying about delivery that didn't happen.
     */
    public function sendOtp(User $user): bool
    {
        $otp = str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT);
        Cache::forget($this->attemptsKey($user->email)); // a fresh code starts with a fresh attempt budget
        Cache::forget($this->verifiedKey($user->email));

        DB::table('password_reset_tokens')->updateOrInsert(
            ['email' => $user->email],
            ['token' => Hash::make($otp), 'created_at' => now()]
        );

        try {
            $body = "Hi,\n\nYour VelocitySuites password reset code is: {$otp}\n\n"
                . "Enter this code to set a new password. This code expires in 15 minutes.\n\n"
                . "If you didn't request this, you can safely ignore this email.\n\n- VelocitySuites";
            Mail::raw($body, function ($message) use ($user, $otp) {
                $message->to($user->email)->subject("Your VelocitySuites password reset code: {$otp}");
            });

            // Only the fact that a code went out - never the code itself.
            Log::info('Password reset code sent', ['user_id' => $user->id, 'channel' => 'email', 'at' => now()->toIso8601String()]);

            return true;
        } catch (\Throwable $e) {
            Log::error("Failed to email password reset OTP to user #{$user->id}: " . $e->getMessage());

            return false;
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
        if ($this->tooManyAttempts($email)) {
            return false;
        }

        $row = DB::table('password_reset_tokens')->where('email', $email)->first();

        if (! $row) {
            return false;
        }

        if (! Hash::check($otp, $row->token)) {
            // Brute-force guard: MAX_ATTEMPTS wrong guesses destroy this code outright.
            $key = $this->attemptsKey($email);
            Cache::add($key, 0, now()->addMinutes(15));
            if (Cache::increment($key) >= self::MAX_ATTEMPTS) {
                DB::table('password_reset_tokens')->where('email', $email)->delete();
            }

            return false;
        }

        // now()->diffInMinutes() returns a value signed the opposite of
        // what it looks like here (confirmed against production: a
        // genuinely-past created_at came back NEGATIVE), so "> 15" was
        // never true for any real expired row - this OTP effectively
        // never expired. isPast() has no such sign ambiguity.
        if (\Carbon\Carbon::parse($row->created_at)->addMinutes(15)->isPast()) {
            DB::table('password_reset_tokens')->where('email', $email)->delete();
            return false;
        }

        Cache::forget($this->attemptsKey($email));

        return true;
    }

    public function resetPassword(User $user, string $password): void
    {
        // A new password also lifts any sign-in lock: the reset is the way out of one.
        $user->update([
            'password' => $password,
            'failed_login_attempts' => 0,
            'last_failed_login_at' => null,
        ]);

        DB::table('password_reset_tokens')->where('email', $user->email)->delete();

        $this->notifyPasswordChanged($user);
    }

    /** Tells the account's owner by email that the password was just changed (a failure to send never blocks the change). */
    private function notifyPasswordChanged(User $user): void
    {
        try {
            $body = "Hi,\n\n"
                . "The password for your VelocitySuites account was just changed.\n\n"
                . "If this was you, there is nothing more to do. If it was not you, reset your password right away "
                . "(Sign in, then Forgot password) and contact the hotel.\n\n"
                . "- VelocitySuites";
            Mail::raw($body, function ($message) use ($user) {
                $message->to($user->email)->subject('Your VelocitySuites password was changed');
            });
        } catch (\Throwable $e) {
            Log::error('Failed to email the password-changed notice: ' . $e->getMessage());
        }
    }
}
