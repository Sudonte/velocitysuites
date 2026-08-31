<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\Guest;
use App\Models\User;
use App\Services\NotificationService;
use Illuminate\Database\QueryException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rules\Password;

class RegisterController extends Controller
{
    /**
     * Show the registration form.
     */
    public function showRegistrationForm()
    {
        return view('auth.register');
    }

    /**
     * Handle registration request.
     */
    public function register(Request $request)
    {
        $isPhilippines = strcasecmp((string) $request->input('country'), 'Philippines') === 0;

        $validated = $request->validate([
            'first_name' => 'required|string|max:100',
            'last_name' => 'required|string|max:100',
            'middle_name' => 'nullable|string|max:100',
            'email' => ['required', 'email', 'unique:users', 'regex:/^[a-zA-Z0-9._%+-]+@gmail\.com$/i'],
            'password' => ['required', 'confirmed', Password::min(8)],
            'age' => 'required|integer|min:18',
            'gender' => 'required|in:male,female',
            'date_of_birth' => 'required|date|before:today',
            'mobile_number' => ['required', 'string', new \App\Rules\ValidPhoneNumber($request->input('country')), 'unique:guests,mobile_number'],
            'country' => 'required|string|max:100',
            // Region/Province/City/Barangay/Street are only ever collected for Philippine
            // addresses - the registration form doesn't even render those fields for any
            // other country, so they must not be required outside the Philippines.
            'region' => $isPhilippines ? 'required|string|max:100' : 'nullable|string|max:100',
            // Provinceless PH regions (e.g. NCR) legitimately have no province -
            // the cascading picker skips straight to City in that case.
            'province' => 'nullable|string|max:100',
            'city' => $isPhilippines ? 'required|string|max:100' : 'nullable|string|max:100',
            'barangay' => $isPhilippines ? 'required|string|max:100' : 'nullable|string|max:100',
            'street' => $isPhilippines ? 'required|string|max:255' : 'nullable|string|max:255',
            'zip_code' => 'nullable|string|max:20',
            'timezone' => 'nullable|string|max:64',
            'profile_picture' => 'nullable|image|mimes:jpeg,png,jpg|max:5120',
        ], [
            'mobile_number.unique' => 'This mobile number is already registered to another account.',
            'email.unique' => 'This email address is already registered. Please login instead.',
            'email.regex' => 'Please register using a valid Gmail (@gmail.com) address.',
            'age.min' => 'You must be at least 18 years old to register.',
        ]);

        // Same age/DOB cross-check as the mobile app's registration form -
        // prevents an age that doesn't match the entered birth date.
        $calculatedAge = \Carbon\Carbon::parse($validated['date_of_birth'])->age;
        if ((int) $validated['age'] !== $calculatedAge) {
            return back()->withInput()->withErrors([
                'age' => "Age ({$validated['age']}) doesn't match the entered date of birth (which makes you {$calculatedAge}).",
            ]);
        }

        // Defense-in-depth: the form never renders Region/Province/City/Barangay/Street/ZIP
        // for a non-Philippine country, but a hand-crafted POST could still attach them
        // (e.g. country=Japan with a leftover Philippine address) - discard them server-side
        // regardless of what was submitted, so a tampered request can never persist a
        // Philippine-looking address against a non-Philippine account.
        if (! $isPhilippines) {
            $validated['region'] = null;
            $validated['province'] = null;
            $validated['city'] = null;
            $validated['barangay'] = null;
            $validated['street'] = null;
            $validated['zip_code'] = null;
        }

        $validated['timezone'] = (new \App\Services\TimezoneResolver())->resolve((string) $validated['country'], $request->input('timezone'));

        // Reject a Region/Province/City/Barangay combination that doesn't actually form a
        // valid PSGC chain - defends against a hand-crafted POST bypassing the cascading
        // picker's own dependent-option constraint (which a normal browser session can't).
        $hierarchyErrors = (new \App\Services\PsgcHierarchyValidator())->validate($validated);
        if (! empty($hierarchyErrors)) {
            return back()->withInput()->withErrors($hierarchyErrors);
        }

        // guests.address is a single text column plus the individual structured
        // columns (country/region/province/city/barangay/street/zip_code) - compose
        // the structured fields into one string for the legacy display column too,
        // same convention the mobile app's getComposedAddress() already uses.
        $validated['address'] = implode(', ', array_filter([
            $validated['street'], $validated['barangay'] ?? null, $validated['city'],
            $validated['province'] ?? null, $validated['region'], $validated['country'],
        ]));

        // Profile picture is entirely optional. Stored to disk now (rather than
        // waiting for verifyOtp()) because the session can only carry a string
        // path, not the UploadedFile itself - its underlying PHP temp file
        // wouldn't survive until the OTP-verification request. A user who
        // never completes verification leaves an orphaned file behind, an
        // accepted minor tradeoff for an optional field on a flow that's
        // already OTP-gated (registration can't be spammed at scale anyway).
        $validated['profile_picture'] = $request->hasFile('profile_picture')
            ? $request->file('profile_picture')->store('profile-pictures', 'public')
            : null;

        // Generate OTP
        $otp = str_pad(random_int(0, 999999), 6, '0', STR_PAD_LEFT);

        // Get booking intent if exists
        $bookingIntent = $request->session()->get('booking_intent');

        // Store OTP in session temporarily
        $request->session()->put('registration_data', array_merge($validated, [
            'otp' => $otp,
            'otp_created_at' => now(),
            'booking_intent' => $bookingIntent, // Preserve booking intent through registration
        ]));

        $this->sendOtpEmail($validated['email'], $otp);

        return redirect()->route('verify-otp')->with('info', 'OTP sent to your email. Please verify to complete registration.');
    }

    /**
     * Show OTP verification form.
     */
    public function showOtpForm()
    {
        if (! session()->has('registration_data')) {
            return redirect()->route('register')->with('error', 'Session expired. Please register again.');
        }

        return view('auth.verify-otp');
    }

    /**
     * Verify OTP and complete registration.
     */
    public function verifyOtp(Request $request)
    {
        $request->validate([
            'otp' => 'required|string|size:6',
        ]);

        $registrationData = session()->get('registration_data');

        if (! $registrationData || $registrationData['otp'] !== $request->otp) {
            return back()->with('error', 'Invalid OTP.');
        }

        // Check OTP expiration (5 minutes)
        if ($registrationData['otp_created_at']->addMinutes(5) < now()) {
            return back()->with('error', 'OTP expired. Please register again.');
        }

        // Wrapped in a transaction + caught below: unique:users/unique:guests
        // were only checked once, at the initial form submit (register()),
        // not again here - two people can race the same email/mobile number
        // within the ~5-minute OTP window and both pass that first check,
        // so the second one to actually reach this point would otherwise hit
        // an uncaught DB integrity-constraint violation (a raw 500) instead
        // of a friendly message. The transaction also stops a User row from
        // ever being left orphaned (no Guest profile) if the Guest insert is
        // what fails.
        try {
            $user = DB::transaction(function () use ($registrationData) {
                $user = User::create([
                    'first_name' => $registrationData['first_name'],
                    'last_name' => $registrationData['last_name'],
                    'middle_name' => $registrationData['middle_name'] ?? null,
                    'email' => $registrationData['email'],
                    'password' => Hash::make($registrationData['password']),
                    'role' => 'guest',
                    'status' => 'active',
                    'email_verified_at' => now(),
                ]);

                // Create guest profile - structured address fields are saved
                // alongside the composed `address` string so the mobile app's
                // Api\ProfileController::show() (which reads guest.region/province/
                // city/barangay/street/zip_code directly) sees the same address a
                // guest entered here, and vice versa.
                Guest::create([
                    'user_id' => $user->id,
                    'age' => $registrationData['age'],
                    'gender' => $registrationData['gender'],
                    'date_of_birth' => $registrationData['date_of_birth'],
                    'mobile_number' => $registrationData['mobile_number'],
                    'address' => $registrationData['address'],
                    'country' => $registrationData['country'] ?? null,
                    'region' => $registrationData['region'] ?? null,
                    'province' => $registrationData['province'] ?? null,
                    'city' => $registrationData['city'] ?? null,
                    'barangay' => $registrationData['barangay'] ?? null,
                    'street' => $registrationData['street'] ?? null,
                    'zip_code' => $registrationData['zip_code'] ?? null,
                    'timezone' => $registrationData['timezone'] ?? null,
                    'profile_picture' => $registrationData['profile_picture'] ?? null,
                ]);

                return $user;
            });
        } catch (QueryException $e) {
            if ((int) $e->getCode() === 23000) {
                return back()->with('error', 'This email or mobile number was just registered by someone else. Please register again.');
            }
            throw $e;
        }

        // reference_id is a foreign key to reservations (see migration
        // 2026_08_10_150000_add_reference_id_to_notifications_table.php) -
        // a new account has no reservation yet, so this must stay null;
        // the identifying detail lives in the message text instead.
        app(NotificationService::class)->notifyAdmin(
            'New Guest Account',
            "{$user->full_name} ({$user->email}) registered a new guest account.",
            'account'
        );

        // Restore booking intent if it existed
        if (isset($registrationData['booking_intent'])) {
            $request->session()->put('booking_intent', $registrationData['booking_intent']);
        }

        // Clear session data
        $request->session()->forget('registration_data');

        return redirect()->route('login')->with('success', 'Registration successful! Please login to continue your booking.');
    }

    /**
     * Resend OTP.
     */
    public function resendOtp(Request $request)
    {
        $registrationData = session()->get('registration_data');

        if (! $registrationData) {
            return redirect()->route('register')->with('error', 'Session expired. Please register again.');
        }

        $otp = str_pad(random_int(0, 999999), 6, '0', STR_PAD_LEFT);

        $request->session()->put('registration_data', array_merge($registrationData, [
            'otp' => $otp,
            'otp_created_at' => now(),
        ]));

        $this->sendOtpEmail($registrationData['email'], $otp);

        return back()->with('success', 'OTP resent to your email.');
    }

    /**
     * Emails the registration OTP - same Mail::raw() pattern already
     * working in Api\AuthController::sendOtpEmail() and
     * Auth\ForgotPasswordController::sendResetLink(). Previously this was
     * a dead TODO (Mail::send(...) commented out) so the web registration
     * flow generated a code but never actually delivered it anywhere.
     */
    private function sendOtpEmail(string $email, string $otp): void
    {
        try {
            $body = "Hi,\n\nYour VelocitySuites verification code is: {$otp}\n\n"
                . "Enter this code to finish creating your account. This code expires in 5 minutes.\n\n"
                . "If you didn't request this, you can safely ignore this email.\n\n- VelocitySuites";
            Mail::raw($body, function ($message) use ($email, $otp) {
                $message->to($email)->subject("Your VelocitySuites verification code: {$otp}");
            });
        } catch (\Throwable $e) {
            Log::error("Failed to email registration OTP to {$email}: " . $e->getMessage());
        }
    }
}
