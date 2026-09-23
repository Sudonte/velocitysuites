<?php

namespace App\Services;

use App\Models\AccountReactivation;
use App\Models\User;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

/**
 * 6-digit OTP challenge gating voluntary-account-reactivation, used by
 * Api\AuthController::login()/reactivateResend()/reactivateVerify(). A
 * deliberately separate concern from RegistrationOtp (new-account signup)
 * and PasswordResetService (password_reset_tokens table) - an OTP issued
 * for one purpose must never verify another, so this has its own table
 * (account_reactivations) rather than reusing either.
 *
 * The OTP itself is hashed at rest (Hash::make), never stored/logged in
 * plain text - unlike RegistrationOtp, which predates this requirement.
 * The reactivation_token (not the OTP, not the user id/email) is the only
 * thing the Android client is given to carry between login() and the
 * resend/verify calls, so a client can never submit an arbitrary account.
 */
class AccountReactivationService
{
    private const EXPIRY_MINUTES = 10;
    private const RESEND_COOLDOWN_SECONDS = 60;
    private const MAX_ATTEMPTS = 5;

    /** Always replaces any prior pending challenge for this user - only the newest OTP/token is ever valid. */
    public function issue(User $user): AccountReactivation
    {
        AccountReactivation::where('user_id', $user->id)->delete();

        $otp = str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT);

        $challenge = AccountReactivation::create([
            'user_id' => $user->id,
            'email' => $user->email,
            'reactivation_token' => bin2hex(random_bytes(32)),
            'otp_hash' => Hash::make($otp),
            'attempts' => 0,
            'expires_at' => now()->addMinutes(self::EXPIRY_MINUTES),
            'last_sent_at' => now(),
        ]);

        Log::info("Account reactivation OTP issued for user #{$user->id}");
        $this->sendOtpEmail($user->email, $otp);

        return $challenge;
    }

    /**
     * @return array{ok: bool, error?: string, retry_after?: int}
     */
    public function resend(string $token): array
    {
        $challenge = AccountReactivation::where('reactivation_token', $token)->first();
        if (! $challenge) {
            return ['ok' => false, 'error' => 'invalid'];
        }

        // Carbon 3 (Laravel 11) returns a *signed* diff by default (negative
        // here, since last_sent_at is in the past) - abs() is required or
        // the < comparison below would always be true and resend would be
        // throttled forever. Confirmed via live testing (verify_deactivation.php).
        $secondsSinceLastSend = abs(now()->diffInSeconds($challenge->last_sent_at));
        if ($secondsSinceLastSend < self::RESEND_COOLDOWN_SECONDS) {
            return ['ok' => false, 'error' => 'throttled', 'retry_after' => self::RESEND_COOLDOWN_SECONDS - $secondsSinceLastSend];
        }

        $otp = str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT);
        $challenge->update([
            'otp_hash' => Hash::make($otp),
            'attempts' => 0,
            'expires_at' => now()->addMinutes(self::EXPIRY_MINUTES),
            'last_sent_at' => now(),
        ]);

        Log::info("Account reactivation OTP resent for user #{$challenge->user_id}");
        $this->sendOtpEmail($challenge->email, $otp);

        return ['ok' => true];
    }

    /**
     * @return array{ok: bool, user?: User, error?: string}
     */
    public function verify(string $token, string $otp): array
    {
        $challenge = AccountReactivation::where('reactivation_token', $token)->first();
        if (! $challenge) {
            return ['ok' => false, 'error' => 'invalid'];
        }

        if ($challenge->expires_at->isPast()) {
            $challenge->delete();
            return ['ok' => false, 'error' => 'expired'];
        }

        if ($challenge->attempts >= self::MAX_ATTEMPTS) {
            $challenge->delete();
            return ['ok' => false, 'error' => 'too_many_attempts'];
        }

        $user = User::find($challenge->user_id);

        // Re-check the account is still voluntarily deactivated at verify time,
        // not just at issue time - guards against a suspended-in-the-meantime
        // account (or one already reactivated through a second concurrent
        // attempt) being reactivated through a stale challenge (see task's
        // "must not confuse voluntary deactivation with administrative
        // suspension" requirement).
        if (! $user || $user->status !== 'deactivated') {
            $challenge->delete();
            return ['ok' => false, 'error' => 'not_deactivated'];
        }

        if (! Hash::check($otp, $challenge->otp_hash)) {
            $challenge->increment('attempts');
            return ['ok' => false, 'error' => 'incorrect'];
        }

        $challenge->delete();

        return ['ok' => true, 'user' => $user];
    }

    private function sendOtpEmail(string $email, string $otp): void
    {
        try {
            $body = "Hi,\n\n"
                . "We received a request to reactivate your VelocitySuites account.\n\n"
                . "Your verification code is: {$otp}\n\n"
                . "Enter this code in the app to reactivate your account. This code expires in "
                . self::EXPIRY_MINUTES . " minutes.\n\n"
                . "If you didn't attempt to reactivate your account, you can safely ignore this email.\n\n"
                . "- VelocitySuites";
            Mail::raw($body, function ($message) use ($email, $otp) {
                $message->to($email)->subject("Your VelocitySuites reactivation code: {$otp}");
            });
        } catch (\Throwable $e) {
            Log::error("Failed to email reactivation OTP to {$email}: " . $e->getMessage());
        }
    }
}
