<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\Guest;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
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

        // TODO: Send OTP via email
        // Mail::send(new SendOtpMail($validated['email'], $otp));

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

        // Create user
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

        // Create guest profile
        Guest::create([
            'user_id' => $user->id,
            'age' => $registrationData['age'],
            'gender' => $registrationData['gender'],
            'date_of_birth' => $registrationData['date_of_birth'],
            'mobile_number' => $registrationData['mobile_number'],
            'address' => $registrationData['address'],
        ]);

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

        // TODO: Send OTP via email
        // Mail::send(new SendOtpMail($registrationData['email'], $otp));

        return back()->with('success', 'OTP resent to your email.');
    }
}
