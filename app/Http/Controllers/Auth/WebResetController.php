<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\PasswordResetService;
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

        // Cooldown keyed on the address alone (not on whether it is registered), shared with the app's endpoint.
        $cooldownKey = 'pwreset:cooldown:' . sha1(strtolower($validated['email']));
        if (! Cache::add($cooldownKey, 1, now()->addSeconds(PasswordResetService::RESEND_COOLDOWN_SECONDS))) {
            return response()->json(['message' => 'Please wait ' . PasswordResetService::RESEND_COOLDOWN_SECONDS . ' seconds before requesting another code.', 'retry_after' => PasswordResetService::RESEND_COOLDOWN_SECONDS], 429);
        }

        $user = User::where('email', $validated['email'])->first();
        if ($user && in_array($user->role, self::ROLES, true)) {
            // stored now, mailed the moment the response is sent: the answer takes the same time for every address
            $this->passwordReset->sendOtp($user, true);
        }

        return response()->json(['message' => "If an account exists for that email, we've sent a 6-digit code."]);
    }

    public function verify(Request $request): JsonResponse
    {
        $validated = $request->validate(['email' => 'required|email', 'otp' => 'required|string|size:6']);

        if ($this->passwordReset->tooManyAttempts($validated['email'])) {
            return $this->tooMany();
        }
        if ($this->passwordReset->alreadyVerified($validated['email'], $validated['otp'])) {
            return response()->json(['message' => 'Invalid or expired code.'], 422);
        }
        if (! $this->passwordReset->verifyOtp($validated['email'], $validated['otp'])) {
            return $this->passwordReset->tooManyAttempts($validated['email']) ? $this->tooMany() : response()->json(['message' => 'Invalid or expired code.'], 422);
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

        if ($this->passwordReset->tooManyAttempts($validated['email'])) {
            return $this->tooMany();
        }
        if (! $this->passwordReset->verifyOtp($validated['email'], $validated['otp'])) {
            return response()->json(['message' => 'Invalid or expired code.'], 422);
        }
        $user = User::where('email', $validated['email'])->first();
        if (! $user || ! in_array($user->role, self::ROLES, true)) {
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

    private function tooMany(): JsonResponse
    {
        return response()->json(['message' => 'Too many incorrect codes. Please request a new code.'], 429);
    }
}
