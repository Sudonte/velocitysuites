<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\ApiToken;
use App\Models\Guest;
use App\Models\RegistrationOtp;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Illuminate\Validation\Rules\Password;

class AuthController extends Controller
{
    /**
     * Log in and issue a bearer token (mirrors Auth\LoginController's
     * lockout/suspension checks, but stateless - no session).
     */
    public function login(Request $request): JsonResponse
    {
        $credentials = $request->validate([
            'email' => 'required|email',
            'password' => 'required|string',
            'device_name' => 'nullable|string|max:100',
        ]);

        $user = User::where('email', $credentials['email'])->first();

        if (! $user || $user->status === 'suspended') {
            return response()->json(['message' => 'Account suspended or credentials invalid.'], 401);
        }

        if ($user->failed_login_attempts >= 3) {
            return response()->json(['message' => 'Account locked due to multiple failed login attempts.'], 423);
        }

        if (! Hash::check($credentials['password'], $user->password)) {
            $user->increment('failed_login_attempts');
            return response()->json(['message' => 'Invalid credentials.'], 401);
        }

        $user->update([
            'failed_login_attempts' => 0,
            'last_login_at' => now(),
        ]);

        $plainToken = Str::random(60);

        $user->apiTokens()->create([
            'token' => hash('sha256', $plainToken),
            'device_name' => $credentials['device_name'] ?? $request->userAgent(),
            'last_used_at' => now(),
        ]);

        return response()->json([
            'token' => $plainToken,
            'user' => $this->formatUser($user),
        ]);
    }

    /**
     * Revoke the token used for this request.
     */
    public function logout(Request $request): JsonResponse
    {
        $apiToken = $request->attributes->get('api_token');
        $apiToken?->delete();

        return response()->json(['message' => 'Logged out.']);
    }

    /**
     * Start registration: validate fields (same rules as the web
     * RegisterController) and store a DB-backed OTP, since a mobile
     * client has no server session to hold pending-registration data
     * in between requests the way the web flow does.
     */
    public function register(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'first_name' => 'required|string|max:100',
            'last_name' => 'required|string|max:100',
            'middle_name' => 'nullable|string|max:100',
            'email' => 'required|email|unique:users',
            'password' => ['required', 'confirmed', Password::min(8)],
            'age' => 'required|integer|min:1',
            'gender' => 'required|in:male,female,other',
            'date_of_birth' => 'required|date',
            'mobile_number' => 'required|string',
            'address' => 'required|string',
        ]);

        $otp = $this->issueOtp($validated['email'], $validated);

        return response()->json([
            'message' => 'OTP sent. Please verify to complete registration.',
        ]);
    }

    /**
     * Verify OTP and complete registration, then log the new user in
     * immediately (issues a token) so the app doesn't need a second
     * login round-trip right after signing up.
     */
    public function verifyOtp(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'email' => 'required|email',
            'otp' => 'required|string|size:6',
        ]);

        $pending = RegistrationOtp::where('email', $validated['email'])->first();

        if (! $pending || $pending->otp !== $validated['otp']) {
            return response()->json(['message' => 'Invalid OTP.'], 422);
        }

        if ($pending->expires_at->isPast()) {
            return response()->json(['message' => 'OTP expired. Please register again.'], 422);
        }

        $data = $pending->payload;

        $user = User::create([
            'first_name' => $data['first_name'],
            'last_name' => $data['last_name'],
            'middle_name' => $data['middle_name'] ?? null,
            'email' => $data['email'],
            'password' => Hash::make($data['password']),
            'role' => 'guest',
            'status' => 'active',
            'email_verified_at' => now(),
        ]);

        Guest::create([
            'user_id' => $user->id,
            'age' => $data['age'],
            'gender' => $data['gender'],
            'date_of_birth' => $data['date_of_birth'],
            'mobile_number' => $data['mobile_number'],
            'address' => $data['address'],
        ]);

        $pending->delete();

        $plainToken = Str::random(60);
        $user->apiTokens()->create([
            'token' => hash('sha256', $plainToken),
            'device_name' => $request->userAgent(),
            'last_used_at' => now(),
        ]);

        return response()->json([
            'token' => $plainToken,
            'user' => $this->formatUser($user),
        ], 201);
    }

    /**
     * Resend OTP for a pending registration.
     */
    public function resendOtp(Request $request): JsonResponse
    {
        $validated = $request->validate(['email' => 'required|email']);

        $pending = RegistrationOtp::where('email', $validated['email'])->first();

        if (! $pending) {
            return response()->json(['message' => 'No pending registration for this email. Please register again.'], 404);
        }

        $this->issueOtp($validated['email'], $pending->payload);

        return response()->json(['message' => 'OTP resent.']);
    }

    /**
     * Generate a fresh 6-digit OTP, upsert the pending-registration row,
     * and log it (email delivery is a pre-existing TODO on the web flow
     * too - see Auth\RegisterController - so this is not a regression,
     * but it does mean OTP delivery still needs to be wired up for
     * either surface before this is production-ready).
     */
    private function issueOtp(string $email, array $payload): string
    {
        $otp = str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT);

        RegistrationOtp::updateOrCreate(
            ['email' => $email],
            [
                'otp' => $otp,
                'payload' => $payload,
                'expires_at' => now()->addMinutes(5),
            ]
        );

        Log::info("Registration OTP for {$email}: {$otp}");

        return $otp;
    }

    private function formatUser(User $user): array
    {
        $user->loadMissing('guest');

        return [
            'id' => $user->id,
            'first_name' => $user->first_name,
            'last_name' => $user->last_name,
            'middle_name' => $user->middle_name,
            'full_name' => $user->full_name,
            'email' => $user->email,
            'role' => $user->role,
            'guest' => $user->guest ? [
                'age' => $user->guest->age,
                'gender' => $user->guest->gender,
                'date_of_birth' => optional($user->guest->date_of_birth)->toDateString(),
                'mobile_number' => $user->guest->mobile_number,
                'address' => $user->guest->address,
            ] : null,
        ];
    }
}
