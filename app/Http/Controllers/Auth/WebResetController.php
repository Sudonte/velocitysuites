<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\PasswordResetService;
use App\Support\EmailDomain;
use App\Support\PasswordPolicy;
use App\Support\PortalToken;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;

/**
 * The website's emailed-code password reset: Guest and System Administrator only (the app's own endpoints are guest-only).
 * A Manager or Receptionist never gets a code - their reset goes through the System Administrator (Auth\ForgotPasswordController's staff
 * request) - and asking for one looks exactly like asking for an unknown address. After a correct code and a valid new password the
 * person is signed in and sent to THEIR dashboard.
 *
 * Same PasswordResetService, same table, same 15-minute code, same attempt budget and resend cooldown as the app's /api/forgot-password,
 * /api/verify-reset-otp and /api/reset-password.
 */
class WebResetController extends Controller
{
    public const ROLES = ['guest', 'admin'];

    public function __construct(private PasswordResetService $passwordReset)
    {
    }

    public function send(Request $request): JsonResponse
    {
        $validated = $request->validate(['email' => 'required|email']);

        // Only a Gmail address belongs on this form (case and spaces ignored): a hotel address is pointed to the staff request, anything else to Gmail
        $email = EmailDomain::clean($validated['email']);
        if ($wrong = EmailDomain::gmailFormError($email)) {
            return response()->json(['code' => $email !== '' && EmailDomain::isHotel($email) ? 'STAFF_EMAIL' : 'NOT_GMAIL', 'message' => $wrong, 'errors' => ['email' => [$wrong]]], 422);
        }

        // Cooldown keyed on the address alone (not on whether it is registered), shared with the app's endpoint.
        $cooldownKey = 'pwreset:cooldown:' . sha1(strtolower($validated['email']));
        if (! Cache::add($cooldownKey, 1, now()->addSeconds(PasswordResetService::RESEND_COOLDOWN_SECONDS))) {
            return response()->json(['message' => 'Please wait ' . PasswordResetService::RESEND_COOLDOWN_SECONDS . ' seconds before requesting another code.', 'retry_after' => PasswordResetService::RESEND_COOLDOWN_SECONDS], 429);
        }

        // a code goes ONLY to a registered, active Guest or System Administrator account
        $user = User::whereRaw('LOWER(email) = ?', [$email])->first();
        if ($user && $user->status === 'active' && in_array($user->role, self::ROLES, true)) {
            // stored now, mailed the moment the response is sent: the answer takes the same time for every address
            $this->passwordReset->sendOtp($user, true);
        }

        return response()->json(['message' => "If an account exists for that email, we've sent a 6-digit code."]);
    }

    public function verify(Request $request): JsonResponse
    {
        $validated = $request->validate(['email' => 'required|email', 'otp' => 'required|string|size:6']);

        // a code already traded for its verification is spent, like an expired one
        if ($this->passwordReset->alreadyVerified($validated['email'], $validated['otp'])) {
            return $this->expired();
        }
        if ($refused = $this->refusal($this->passwordReset->checkOtp($validated['email'], $validated['otp']))) {
            return $refused;
        }
        $this->passwordReset->markVerified($validated['email'], $validated['otp']);

        return response()->json(['message' => 'Code verified.']);
    }

    public function complete(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'email' => 'required|email',
            'otp' => 'required|string|size:6',
            'password' => ['required', 'confirmed', PasswordPolicy::rule()],
        ]);

        if ($refused = $this->refusal($this->passwordReset->checkOtp($validated['email'], $validated['otp']))) {
            return $refused;
        }
        $user = User::whereRaw('LOWER(email) = ?', [EmailDomain::clean($validated['email'])])->first();
        if (! $user || $user->status !== 'active' || ! in_array($user->role, self::ROLES, true)) {
            return response()->json(['message' => 'Invalid or expired code.'], 422);
        }

        // the new password lifts the sign-in lock and the owner is told by email (PasswordResetService)
        $this->passwordReset->resetPassword($user, $validated['password']);
        $user->forceFill(['must_change_password' => false, 'temporary_password_expires_at' => null])->save();
        $user->apiTokens()->delete(); // the app's sessions end with the old password

        if ($user->role === 'guest') {
            // the guest pages call the same API with a per-session token
            $plain = Str::random(60);
            $user->apiTokens()->create(['token' => hash('sha256', $plain), 'device_name' => PortalToken::DEVICE, 'last_used_at' => now()]);
            if (! $user->guest) {
                return response()->json(['message' => 'Sign-in could not be completed. Please sign in again.'], 401);
            }
            Auth::login($user);
            $request->session()->regenerate();
            PortalToken::adopt($plain, $user);
        } else {
            Auth::login($user);
            $request->session()->regenerate();
        }

        return response()->json(['message' => 'Password changed. Welcome back!', 'redirect' => $user->role === 'admin' ? route('admin.dashboard') : route('guest.dashboard')]);
    }

    /** The answer for a code that was not accepted, or null when it was: wrong (with the tries left), too many tries, or expired. */
    private function refusal(array $check): ?JsonResponse
    {
        return match ($check['status']) {
            'ok' => null,
            'wrong' => response()->json([
                'code' => 'WRONG_CODE',
                'tries_left' => $check['tries_left'],
                'message' => "That code isn't right. Please try again. ({$check['tries_left']} " . ($check['tries_left'] === 1 ? 'try' : 'tries') . ' left)',
            ], 422),
            'too_many' => $this->tooMany(),
            default => $this->expired(),
        };
    }

    private function expired(): JsonResponse
    {
        return response()->json(['code' => 'CODE_EXPIRED', 'message' => 'This code has expired. Please request a new code.'], 422);
    }

    private function tooMany(): JsonResponse
    {
        return response()->json(['code' => 'TOO_MANY_TRIES', 'message' => 'Too many tries. Please request a new code.'], 429);
    }
}
