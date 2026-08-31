<?php

namespace App\Http\Controllers;

use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;

class ProfileController extends Controller
{
    /**
     * Show the user's profile. Full details for everyone (admin,
     * manager, receptionist) - editing is gated separately below. Guests'
     * age/gender/DOB/mobile/address live on the linked `guests` row (the
     * same one the mobile API reads/writes via Api\ProfileController), not
     * on `users` - the view reads from $guest for guest role, $user for
     * everyone else.
     */
    public function show(): \Illuminate\View\View|RedirectResponse
    {
        $user = auth()->user();

        // Guests have their own dedicated profile page (Guest\GuestController::profile(),
        // route guest.profile.show) with guest-appropriate (nullable/optional) validation -
        // redirect here instead of rendering a second, divergent copy of the same data.
        if ($user->role === 'guest') {
            return redirect()->route('guest.profile.show');
        }

        return view('profile.show', [
            'user' => $user,
            'guest' => null,
        ]);
    }

    /**
     * Update profile info - admin and guest only (managers/receptionists
     * view their full details here but never edit them; the System
     * Administrator manages their account info from User Management).
     * Email is deliberately excluded from the validated/updated set -
     * it's the account's fixed identity and is never editable from here,
     * shown read-only in the view instead.
     *
     * Admin accounts have no linked `guests` row, so their personal/address
     * fields live directly on `users` (see the profile-fields migration).
     * Guest accounts DO have a `guests` row, and it's the one the mobile
     * app's Api\ProfileController reads/writes - so for a guest, personal
     * and address fields must be saved there instead, or web edits would be
     * invisible to the mobile app (and vice versa).
     */
    public function update(Request $request): RedirectResponse
    {
        $user = auth()->user();

        abort_if(in_array($user->role, ['manager', 'receptionist', 'guest'], true), 403,
            $user->role === 'guest'
                ? 'Please use your Profile page to update your account information.'
                : 'Please contact the System Administrator to update your account information.');

        $isPhilippines = strcasecmp((string) $request->input('country'), 'Philippines') === 0;

        // mobile_number lands on a different table depending on role (guests vs users -
        // see the save branch below), so the uniqueness check has to target the same
        // table, ignoring this account's own existing row.
        $mobileUniqueRule = $user->role === 'guest'
            ? Rule::unique('guests', 'mobile_number')->ignore($user->guest->id ?? null)
            : Rule::unique('users', 'mobile_number')->ignore($user->id);

        $validated = $request->validate([
            'first_name' => 'required|string|max:100',
            'last_name' => 'required|string|max:100',
            'middle_name' => 'nullable|string|max:100',
            'age' => 'required|integer|min:18|max:120',
            'gender' => 'required|in:male,female',
            'date_of_birth' => 'required|date|before:today',
            'mobile_number' => ['required', 'string', 'regex:/^(09|\+639|639)\d{9}$/', $mobileUniqueRule],
            'country' => 'required|string|max:100',
            // Region/Province/City/Barangay/Street are only ever collected for Philippine
            // addresses - the profile form doesn't even render those fields for any other
            // country, so they must not be required outside the Philippines.
            'region' => $isPhilippines ? 'required|string|max:100' : 'nullable|string|max:100',
            'province' => 'nullable|string|max:100',
            'city' => $isPhilippines ? 'required|string|max:100' : 'nullable|string|max:100',
            'barangay' => $isPhilippines ? 'required|string|max:100' : 'nullable|string|max:100',
            'street' => $isPhilippines ? 'required|string|max:255' : 'nullable|string|max:255',
            'zip_code' => 'nullable|string|max:20',
            'timezone' => 'nullable|string|max:64',
        ], [
            'mobile_number.regex' => 'Enter a valid Philippine mobile number (e.g. 09171234567 or +639171234567).',
            'mobile_number.unique' => 'This mobile number is already registered to another account.',
            'age.min' => 'You must be at least 18 years old.',
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
        // for a non-Philippine country, but a hand-crafted POST could still attach them -
        // discard them server-side regardless of what was submitted, so a tampered request
        // can never persist a Philippine-looking address against a non-Philippine account.
        if (! $isPhilippines) {
            $validated['region'] = null;
            $validated['province'] = null;
            $validated['city'] = null;
            $validated['barangay'] = null;
            $validated['street'] = null;
            $validated['zip_code'] = null;
        }

        $validated['timezone'] = (new \App\Services\TimezoneResolver())->resolve((string) $validated['country'], $request->input('timezone'));

        $hierarchyErrors = (new \App\Services\PsgcHierarchyValidator())->validate($validated);
        if (! empty($hierarchyErrors)) {
            return back()->withInput()->withErrors($hierarchyErrors);
        }

        if ($user->role === 'guest') {
            $user->update([
                'first_name' => $validated['first_name'],
                'last_name' => $validated['last_name'],
                'middle_name' => $validated['middle_name'] ?? null,
            ]);

            $address = implode(', ', array_filter([
                $validated['street'], $validated['barangay'] ?? null, $validated['city'],
                $validated['province'] ?? null, $validated['region'], $validated['country'],
            ]));

            $user->guest()->updateOrCreate(['user_id' => $user->id], [
                'age' => $validated['age'],
                'gender' => $validated['gender'],
                'date_of_birth' => $validated['date_of_birth'],
                'mobile_number' => $validated['mobile_number'],
                'address' => $address,
                'country' => $validated['country'],
                'region' => $validated['region'],
                'province' => $validated['province'] ?? null,
                'city' => $validated['city'],
                'barangay' => $validated['barangay'] ?? null,
                'street' => $validated['street'],
                'zip_code' => $validated['zip_code'] ?? null,
            ]);
        } else {
            $user->update($validated);
        }

        return back()->with('success', 'Profile updated successfully!');
    }

    /**
     * Upload/replace the admin's profile picture - admin only, same guard as
     * update()/changePassword(). Limited to once per month per the System
     * Administrator profile spec; validation mirrors the existing GCash
     * receipt upload convention (Api\PaymentController) exactly.
     */
    public function updateProfilePicture(Request $request): RedirectResponse
    {
        $user = auth()->user();

        abort_if(in_array($user->role, ['manager', 'receptionist', 'guest'], true), 403,
            $user->role === 'guest'
                ? 'Please use your Profile page to update your account information.'
                : 'Please contact the System Administrator to update your account information.');

        if (! $user->canChangeProfilePicture()) {
            return back()->withErrors([
                'profile_picture' => 'You can only change your profile picture once a month. Next available: '
                    . $user->nextProfilePictureChangeDate()->format('M d, Y') . '.',
            ]);
        }

        $request->validate([
            'profile_picture' => 'required|image|mimes:jpeg,png,jpg|max:5120',
        ]);

        $oldPicture = $user->profile_picture;

        $path = $request->file('profile_picture')->store('profile-pictures', 'public');

        $user->update([
            'profile_picture' => $path,
            'profile_picture_changed_at' => now(),
        ]);

        if ($oldPicture) {
            Storage::disk('public')->delete($oldPicture);
        }

        return back()->with('success', 'Profile picture updated successfully!');
    }

    /**
     * Remove the admin's profile picture entirely - reverts the account back to the
     * dynamically-generated initials placeholder everywhere it's shown. Same guard as
     * update()/changePassword(). Deliberately doesn't touch the once-a-month cooldown -
     * removing isn't a "change" in the sense that restriction exists for.
     */
    public function removeProfilePicture(): RedirectResponse
    {
        $user = auth()->user();

        abort_if(in_array($user->role, ['manager', 'receptionist', 'guest'], true), 403,
            $user->role === 'guest'
                ? 'Please use your Profile page to update your account information.'
                : 'Please contact the System Administrator to update your account information.');

        if ($user->profile_picture) {
            Storage::disk('public')->delete($user->profile_picture);
            $user->update(['profile_picture' => null]);
        }

        return back()->with('success', 'Profile picture removed.');
    }

    /**
     * Sends a 6-digit OTP to the admin's own (fixed, non-editable) email -
     * same password_reset_tokens table and 15-minute window as
     * Auth\ForgotPasswordController, so the two flows behave identically.
     * Admin only, same guard as update()/changePassword().
     */
    public function requestPasswordOtp(Request $request): RedirectResponse
    {
        $user = auth()->user();

        abort_if(in_array($user->role, ['manager', 'receptionist', 'guest'], true), 403,
            $user->role === 'guest'
                ? 'Please use your Profile page to change your password.'
                : 'Please contact the System Administrator to reset your password.');

        $otp = str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT);

        DB::table('password_reset_tokens')->updateOrInsert(
            ['email' => $user->email],
            ['token' => Hash::make($otp), 'created_at' => now()]
        );

        Log::info("Profile password-change OTP for {$user->email}: {$otp}");

        try {
            $body = "Hi,\n\nYour VelocitySuites password change verification code is: {$otp}\n\n"
                . "Enter this code on your Profile page to confirm your new password. This code expires in 15 minutes.\n\n"
                . "If you didn't request this, you can safely ignore this email.\n\n- VelocitySuites";
            Mail::raw($body, function ($message) use ($user, $otp) {
                $message->to($user->email)->subject("Your VelocitySuites verification code: {$otp}");
            });
        } catch (\Throwable $e) {
            Log::error("Failed to email profile password-change OTP to {$user->email}: " . $e->getMessage());
        }

        return back()->with('success', "A verification code has been sent to {$user->email}.");
    }

    /**
     * Change password - admin only, OTP-gated instead of current-password-
     * gated (see requestPasswordOtp() above) per the System Administrator
     * profile spec: verifying the emailed code proves identity instead of
     * re-typing the current password.
     */
    public function changePassword(Request $request): RedirectResponse
    {
        $user = auth()->user();

        abort_if(in_array($user->role, ['manager', 'receptionist', 'guest'], true), 403,
            $user->role === 'guest'
                ? 'Please use your Profile page to change your password.'
                : 'Please contact the System Administrator to reset your password.');

        $validated = $request->validate([
            'otp' => 'required|string|size:6',
            'new_password' => 'required|string|min:8|confirmed',
        ]);

        $row = DB::table('password_reset_tokens')->where('email', $user->email)->first();

        if (! $row || ! Hash::check($validated['otp'], $row->token)) {
            return back()->withErrors(['otp' => 'Invalid or expired verification code.']);
        }

        if (now()->diffInMinutes($row->created_at) > 15) {
            DB::table('password_reset_tokens')->where('email', $user->email)->delete();
            return back()->withErrors(['otp' => 'Invalid or expired verification code.']);
        }

        $user->update(['password' => Hash::make($validated['new_password'])]);
        DB::table('password_reset_tokens')->where('email', $user->email)->delete();

        return back()->with('success', 'Password changed successfully!');
    }
}
