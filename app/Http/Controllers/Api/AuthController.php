<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\ApiToken;
use App\Models\Guest;
use App\Models\RegistrationOtp;
use App\Models\User;
use App\Services\PasswordResetService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Illuminate\Validation\Rules\Password;

class AuthController extends Controller
{
    public function __construct(private PasswordResetService $passwordReset)
    {
    }

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

        // Past its 30-day restore window - treat as gone even if the
        // scheduled purge command (see Console\Commands\PurgeExpiredDeletedAccounts)
        // hasn't physically removed the row yet.
        if ($user->isPendingDeletion() && ! $user->isRestorable()) {
            return response()->json(['message' => 'Account suspended or credentials invalid.'], 401);
        }

        if ($user->failed_login_attempts >= 3) {
            return response()->json(['message' => 'Account locked due to multiple failed login attempts.'], 423);
        }

        if (! Hash::check($credentials['password'], $user->password)) {
            $user->increment('failed_login_attempts');
            return response()->json(['message' => 'Invalid credentials.'], 401);
        }

        // This API/app is guest-facing only - staff (admin/manager/
        // receptionist) have no screens here at all, so a correct
        // password on a staff account should still be refused rather
        // than silently handing out a token nothing in the app can use.
        if ($user->role !== 'guest') {
            return response()->json(['message' => 'This app is for guest accounts only. Staff should use the website.'], 403);
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
        // Mobile number format depends on the guest's selected country -
        // Philippines needs the local 09.../+639... format (also what SMS
        // OTP delivery requires), any other country just needs a
        // plausible international number. Not part of the validated set
        // itself (only 'address' is persisted - see Guest::create() in
        // verifyOtp()), just read here to pick the right mobile rule.
        $country = trim((string) $request->input('country', ''));
        $isPhilippines = strcasecmp($country, 'Philippines') === 0;

        $validated = $request->validate([
            'first_name' => 'required|string|max:100',
            'last_name' => 'required|string|max:100',
            'middle_name' => 'nullable|string|max:100',
            'email' => ['required', 'email', 'unique:users', 'regex:/^[a-zA-Z0-9._%+-]+@gmail\.com$/i'],
            'password' => ['required', 'confirmed', Password::min(8)],
            'age' => 'required|integer|min:18',
            'gender' => 'required|in:male,female',
            'date_of_birth' => 'required|date|before:today',
            'mobile_number' => ['required', 'string', new \App\Rules\ValidPhoneNumber($country), 'unique:guests,mobile_number'],
            'address' => 'required|string',
            'country' => 'required|string|max:100',
            // Region/Province/City/Barangay/Street are only ever collected for Philippine
            // addresses - the Android client doesn't even render those fields for any other
            // country, so they must not be required outside the Philippines.
            'region' => $isPhilippines ? 'required|string|max:100' : 'nullable|string|max:100',
            'province' => 'nullable|string|max:100',
            'city' => $isPhilippines ? 'required|string|max:100' : 'nullable|string|max:100',
            'barangay' => $isPhilippines ? 'required|string|max:100' : 'nullable|string|max:100',
            'street' => $isPhilippines ? 'required|string|max:255' : 'nullable|string|max:255',
            'zip_code' => 'nullable|string|max:20',
            'timezone' => 'nullable|string|max:64',
            'otp_channel' => 'required|in:email,mobile',
        ], [
            'email.regex' => 'Please register using a valid Gmail (@gmail.com) address.',
            'mobile_number.unique' => 'This mobile number is already registered to another account.',
            'email.unique' => 'This email address is already registered. Please login instead.',
            'age.min' => 'You must be at least 18 years old to register.',
        ]);

        // Defense-in-depth: the Android client never renders Region/Province/City/Barangay/
        // Street/ZIP for a non-Philippine country, but a hand-crafted API request could still
        // attach them - discard them server-side regardless of what was submitted, so a
        // tampered request can never persist a Philippine-looking address against a
        // non-Philippine account.
        if (! $isPhilippines) {
            $validated['region'] = null;
            $validated['province'] = null;
            $validated['city'] = null;
            $validated['barangay'] = null;
            $validated['street'] = null;
            $validated['zip_code'] = null;
        }

        $validated['timezone'] = (new \App\Services\TimezoneResolver())->resolve($country, $request->input('timezone'));

        // Same age/DOB cross-check as the web RegisterController - prevents
        // an age that doesn't match the entered birth date.
        $calculatedAge = \Carbon\Carbon::parse($validated['date_of_birth'])->age;
        if ((int) $validated['age'] !== $calculatedAge) {
            return response()->json([
                'message' => "Age ({$validated['age']}) doesn't match the entered date of birth (which makes you {$calculatedAge}).",
                'errors' => ['age' => ["Age ({$validated['age']}) doesn't match the entered date of birth (which makes you {$calculatedAge})."]],
            ], 422);
        }

        // Reject a Region/Province/City/Barangay combination that doesn't actually form a
        // valid PSGC chain - defends against a hand-crafted API request bypassing the
        // Android client's own AddressHierarchyController dependent-option constraint.
        $hierarchyErrors = (new \App\Services\PsgcHierarchyValidator())->validate($validated);
        if (! empty($hierarchyErrors)) {
            return response()->json([
                'message' => reset($hierarchyErrors),
                'errors' => array_map(fn ($msg) => [$msg], $hierarchyErrors),
            ], 422);
        }

        // SMS OTP only reaches Philippine numbers (Semaphore) - the
        // Android client already disables this pairing in the UI, but the
        // server is the real boundary so it's rejected here too.
        if ($validated['otp_channel'] === 'mobile' && ! $isPhilippines) {
            return response()->json([
                'message' => 'SMS verification is only available for Philippine mobile numbers. Please choose email verification instead.',
            ], 422);
        }

        // Mobile OTP delivery needs a configured SMS provider - fail fast
        // with a clear, actionable message instead of silently generating
        // a code the guest has no way to receive.
        if ($validated['otp_channel'] === 'mobile' && ! config('services.semaphore.key')) {
            return response()->json([
                'message' => 'SMS verification is not available right now. Please choose email verification instead.',
            ], 422);
        }

        $otp = $this->issueOtp($validated['email'], $validated);

        return response()->json([
            'message' => $validated['otp_channel'] === 'mobile'
                ? 'OTP sent to your mobile number. Please verify to complete registration.'
                : 'OTP sent to your email. Please verify to complete registration.',
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
            'country' => $data['country'] ?? null,
            'region' => $data['region'] ?? null,
            'province' => $data['province'] ?? null,
            'city' => $data['city'] ?? null,
            'barangay' => $data['barangay'] ?? null,
            'street' => $data['street'] ?? null,
            'zip_code' => $data['zip_code'] ?? null,
            'timezone' => $data['timezone'] ?? null,
        ]);

        $pending->delete();

        // reference_id is a foreign key to reservations - a new account
        // has none yet, so this stays null; the identifying detail lives
        // in the message text instead.
        app(\App\Services\NotificationService::class)->notifyAdmin(
            'New Guest Account',
            "{$user->full_name} ({$user->email}) registered a new guest account (mobile).",
            'account'
        );

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
     * Request a password reset OTP via App\Services\PasswordResetService -
     * the same service Auth\ForgotPasswordController uses on web, so both
     * flows generate/email/expire OTPs identically. Deliberately generic
     * response either way so this can't be used to enumerate registered
     * emails.
     */
    public function forgotPassword(Request $request): JsonResponse
    {
        $validated = $request->validate(['email' => 'required|email']);

        $user = User::where('email', $validated['email'])->first();

        // Same policy as the web's Auth\ForgotPasswordController::sendResetLink() -
        // managers/receptionists never self-reset, only the System Administrator can
        // reset their password (see Admin\UserManagementController::resetPassword()).
        // Enforced here too so the same rule can't be bypassed via a direct API call
        // that the Android UI simply never exposes for staff accounts. The response
        // stays identical either way to avoid leaking whether the email is registered
        // or what role it belongs to.
        if ($user && ! in_array($user->role, ['manager', 'receptionist'], true)) {
            $this->passwordReset->sendOtp($user);
        }

        return response()->json(['message' => 'If that email is registered, a reset code has been sent.']);
    }

    /**
     * Non-destructive OTP check for the mobile OTP-verification screen -
     * lets the app tell the guest immediately whether their code is right,
     * before they've also entered a new password. resetPassword() still
     * re-validates the OTP itself as the real gate (defense in depth).
     */
    public function verifyResetOtp(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'email' => 'required|email',
            'otp' => 'required|string|size:6',
        ]);

        if (! $this->passwordReset->verifyOtp($validated['email'], $validated['otp'])) {
            return response()->json(['message' => 'Invalid or expired code.'], 422);
        }

        return response()->json(['message' => 'OTP verified.']);
    }

    /**
     * Verify the reset OTP and set a new password, then issue a fresh
     * bearer token (mirrors login()'s exact token-issuance pattern) so the
     * app can sign the guest straight in and navigate to the dashboard
     * instead of sending them back to a manual login.
     */
    public function resetPassword(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'email' => 'required|email',
            'otp' => 'required|string|size:6',
            'password' => ['required', 'confirmed', Password::min(8)],
        ]);

        if (! $this->passwordReset->verifyOtp($validated['email'], $validated['otp'])) {
            return response()->json(['message' => 'Invalid or expired code.'], 422);
        }

        $user = User::where('email', $validated['email'])->first();
        if (! $user) {
            return response()->json(['message' => 'Invalid or expired code.'], 422);
        }

        $this->passwordReset->resetPassword($user, $validated['password']);

        $user->apiTokens()->delete();

        $plainToken = Str::random(60);
        $user->apiTokens()->create([
            'token' => hash('sha256', $plainToken),
            'device_name' => $request->input('device_name') ?? $request->userAgent(),
            'last_used_at' => now(),
        ]);

        return response()->json([
            'message' => 'Password reset successfully.',
            'token' => $plainToken,
            'user' => $this->formatUser($user),
        ]);
    }

    /**
     * Generate a fresh 6-digit OTP, upsert the pending-registration row,
     * and deliver it via whichever channel the guest picked at
     * registration (payload['otp_channel'], defaulting to email for
     * forgot-password/older payloads that predate the channel choice).
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

        $channel = $payload['otp_channel'] ?? 'email';

        if ($channel === 'mobile' && ! empty($payload['mobile_number'])) {
            $this->sendOtpSms($payload['mobile_number'], $otp);
        } else {
            $this->sendOtpEmail($email, $otp);
        }

        return $otp;
    }

    private function sendOtpEmail(string $email, string $otp): void
    {
        try {
            $body = "Hi,\n\n"
                . "Your VelocitySuites verification code is: {$otp}\n\n"
                . "Enter this code in the app to finish creating your account. This code expires in 5 minutes.\n\n"
                . "If you didn't request this, you can safely ignore this email.\n\n"
                . "- VelocitySuites";
            Mail::raw($body, function ($message) use ($email, $otp) {
                $message->to($email)->subject("Your VelocitySuites verification code: {$otp}");
            });
        } catch (\Throwable $e) {
            Log::error("Failed to email OTP to {$email}: " . $e->getMessage());
        }
    }

    /**
     * Sends the OTP via Semaphore (api.semaphore.co) - a Philippine SMS
     * gateway, matching the app's PH-only address/mobile-number format.
     * Silently no-ops (besides the log line already written by
     * issueOtp()) if SEMAPHORE_API_KEY isn't configured - register()
     * already refuses mobile-channel signups before reaching here in
     * that case, so this path only runs with a real key.
     */
    private function sendOtpSms(string $mobileNumber, string $otp): void
    {
        $apiKey = config('services.semaphore.key');
        if (! $apiKey) {
            return;
        }

        try {
            $response = Http::asForm()->post('https://api.semaphore.co/api/v4/messages', array_filter([
                'apikey' => $apiKey,
                'number' => $this->toPhilippineMsisdn($mobileNumber),
                'message' => "Your VelocitySuites verification code is: {$otp}. This code expires in 5 minutes.",
                'sendername' => config('services.semaphore.sender_name'),
            ]));

            if (! $response->successful()) {
                Log::error("Semaphore SMS failed for {$mobileNumber}: " . $response->body());
            }
        } catch (\Throwable $e) {
            Log::error("Semaphore SMS exception for {$mobileNumber}: " . $e->getMessage());
        }
    }

    /** Normalizes a PH mobile number (09XXXXXXXXX / +639XXXXXXXXX / 639XXXXXXXXX) to Semaphore's expected 63XXXXXXXXXX form. */
    private function toPhilippineMsisdn(string $mobile): string
    {
        $digits = preg_replace('/\D/', '', $mobile);
        if (str_starts_with($digits, '0')) {
            $digits = '63' . substr($digits, 1);
        } elseif (! str_starts_with($digits, '63')) {
            $digits = '63' . $digits;
        }
        return $digits;
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
            // 'pending_deletion' means the account is still inside its
            // 30-day restore window (see ProfileController::deleteAccount/
            // restoreAccount) - the client should show a restore prompt
            // instead of the normal dashboard when it sees this.
            'account_status' => $user->isPendingDeletion() ? 'pending_deletion' : 'active',
            'restore_deadline' => $user->isPendingDeletion() ? optional($user->restore_deadline)->toIso8601String() : null,
            'guest' => $user->guest ? [
                'age' => $user->guest->age,
                'gender' => $user->guest->gender,
                'date_of_birth' => optional($user->guest->date_of_birth)->toDateString(),
                'mobile_number' => $user->guest->mobile_number,
                'address' => $user->guest->address,
                'country' => $user->guest->country,
                'region' => $user->guest->region,
                'province' => $user->guest->province,
                'city' => $user->guest->city,
                'barangay' => $user->guest->barangay,
                'street' => $user->guest->street,
                'zip_code' => $user->guest->zip_code,
                'profile_picture_url' => $user->guest->profile_picture_url,
            ] : null,
        ];
    }
}
