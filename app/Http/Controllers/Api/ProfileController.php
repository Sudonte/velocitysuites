<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Billing;
use App\Models\Booking;
use App\Models\Guest;
use App\Models\Payment;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;

class ProfileController extends Controller
{
    public function __construct(
        private \App\Services\EmailChangeService $emailChange,
    ) {
    }

    /**
     * Same shape as Guest\GuestController@profile.
     */
    public function show(): JsonResponse
    {
        $user = auth()->user();

        return response()->json([
            'user' => $user,
            'guest' => $user->guest,
            'profile_update' => $this->profileUpdateState($user->guest),
        ]);
    }

    /**
     * Computed Personal Information / Contact & Address cooldown state -
     * shared shape for show()/update() so Android never has to re-derive
     * the 30-day window from a raw timestamp itself (device clock isn't
     * trusted for this; the server's own clock always is).
     */
    private function profileUpdateState(?Guest $guest): array
    {
        if (! $guest) {
            return [
                'can_update' => true,
                'last_updated_at' => null,
                'next_update_at' => null,
                'days_remaining' => 0,
            ];
        }

        $nextUpdateAt = $guest->nextProfileUpdateDate();

        // nextProfileUpdateDate() already returns null once the cooldown has
        // lifted, so $nextUpdateAt is guaranteed to be in the future here -
        // rounded up (not truncated) so "1 day remaining" never flashes to
        // "0" hours before the guest is actually eligible again.
        $daysRemaining = $nextUpdateAt ? (int) ceil(now()->diffInHours($nextUpdateAt) / 24) : 0;

        return [
            'can_update' => $guest->canUpdateProfile(),
            'last_updated_at' => $guest->profile_last_updated_at?->toIso8601String(),
            'next_update_at' => $nextUpdateAt?->toIso8601String(),
            'days_remaining' => $daysRemaining,
        ];
    }

    /**
     * Same validation/update logic as Guest\GuestController@updateProfile.
     */
    public function update(Request $request): JsonResponse
    {
        $user = auth()->user();
        $guest = $user->guest;

        $validated = $request->validate([
            'first_name' => 'required|string|max:100',
            'last_name' => 'required|string|max:100',
            'middle_name' => 'nullable|string|max:100',
            'gender' => 'nullable|in:male,female',
            'date_of_birth' => 'nullable|date|before:today',
            'mobile_number' => [
                'nullable', 'string', 'max:20',
                new \App\Rules\ValidPhoneNumber($request->input('country', $guest->country ?? '')),
                Rule::unique('guests', 'mobile_number')->ignore($guest->id ?? null),
            ],
            'address' => 'nullable|string|max:500',
            'country' => 'nullable|string|max:100',
            'region' => 'nullable|string|max:100',
            'province' => 'nullable|string|max:100',
            'city' => 'nullable|string|max:100',
            'barangay' => 'nullable|string|max:100',
            'street' => 'nullable|string|max:255',
            'zip_code' => 'nullable|string|max:20',
            'timezone' => 'nullable|string|max:64',
        ], [
            'mobile_number.unique' => 'This mobile number is already registered to another account.',
            'date_of_birth.before' => 'Date of Birth cannot be in the future.',
        ]);

        // Age is never trusted from the client (it's read-only on the Android side anyway) -
        // whenever Date of Birth is touched, recompute it here so guests.age can never drift
        // out of sync with guests.date_of_birth.
        if (array_key_exists('date_of_birth', $validated) && $validated['date_of_birth']) {
            $validated['age'] = \Carbon\Carbon::parse($validated['date_of_birth'])->age;
        }

        // Defense-in-depth: the Android client never renders Region/Province/City/Barangay/
        // Street/ZIP for a non-Philippine country - discard them server-side regardless of
        // what was submitted whenever the submitted country isn't the Philippines, so a
        // tampered request (or a country switch) can never leave a stale Philippine address
        // behind via the fallback-to-existing-value merge below.
        $submittedCountry = array_key_exists('country', $validated) ? $validated['country'] : ($guest->country ?? null);
        $isPhilippines = strcasecmp((string) $submittedCountry, 'Philippines') === 0;
        if (array_key_exists('country', $validated) && ! $isPhilippines) {
            $validated['region'] = null;
            $validated['province'] = null;
            $validated['city'] = null;
            $validated['barangay'] = null;
            $validated['street'] = null;
            $validated['zip_code'] = null;
        }
        $validated['timezone'] = (new \App\Services\TimezoneResolver())->resolve((string) $submittedCountry, $request->input('timezone'));

        // Only re-validate the hierarchy when the request actually touches address fields -
        // this endpoint also handles plain name/email/mobile edits, and shouldn't reject
        // those over an address combination that predates this validation existing.
        $addressFieldsTouched = array_intersect(['country', 'region', 'province', 'city', 'barangay'], array_keys($validated));
        if ($guest && ! empty($addressFieldsTouched)) {
            $effectiveAddress = [
                'country' => array_key_exists('country', $validated) ? $validated['country'] : $guest->country,
                'region' => array_key_exists('region', $validated) ? $validated['region'] : $guest->region,
                'province' => array_key_exists('province', $validated) ? $validated['province'] : $guest->province,
                'city' => array_key_exists('city', $validated) ? $validated['city'] : $guest->city,
                'barangay' => array_key_exists('barangay', $validated) ? $validated['barangay'] : $guest->barangay,
            ];
            $hierarchyErrors = (new \App\Services\PsgcHierarchyValidator())->validate($effectiveAddress);
            if (! empty($hierarchyErrors)) {
                return response()->json([
                    'message' => reset($hierarchyErrors),
                    'errors' => array_map(fn ($msg) => [$msg], $hierarchyErrors),
                ], 422);
            }
        }

        // 30-day Personal Information / Contact & Address cooldown - the
        // server is the sole source of truth (a modified/replayed request
        // must never bypass this just because the Android UI's own Edit
        // button happened to be disabled or re-enabled). Re-checked again
        // inside the transaction below under a row lock, so two
        // near-simultaneous requests can't both pass this same check
        // before either one's write commits.
        if ($guest && ! $guest->canUpdateProfile()) {
            return response()->json([
                'message' => 'You can update your profile again on ' . $guest->nextProfileUpdateDate()->format('F j, Y') . '.',
                'profile_update' => $this->profileUpdateState($guest),
            ], 422);
        }

        $freshUser = null;
        $freshGuest = null;

        DB::transaction(function () use ($user, $guest, $validated, &$freshUser, &$freshGuest) {
            $lockedGuest = $guest ? Guest::whereKey($guest->id)->lockForUpdate()->first() : null;
            if ($lockedGuest && ! $lockedGuest->canUpdateProfile()) {
                abort(422, 'You can update your profile again on ' . $lockedGuest->nextProfileUpdateDate()->format('F j, Y') . '.');
            }

            $user->update([
                'first_name' => $validated['first_name'],
                'last_name' => $validated['last_name'],
                'middle_name' => $validated['middle_name'] ?? null,
            ]);

            if ($lockedGuest) {
                $lockedGuest->update([
                    'mobile_number' => $validated['mobile_number'] ?? $lockedGuest->mobile_number,
                    'gender' => array_key_exists('gender', $validated) ? $validated['gender'] : $lockedGuest->gender,
                    'date_of_birth' => array_key_exists('date_of_birth', $validated) ? $validated['date_of_birth'] : $lockedGuest->date_of_birth,
                    'age' => array_key_exists('age', $validated) ? $validated['age'] : $lockedGuest->age,
                    'address' => $validated['address'] ?? $lockedGuest->address,
                    'country' => array_key_exists('country', $validated) ? $validated['country'] : $lockedGuest->country,
                    'region' => array_key_exists('region', $validated) ? $validated['region'] : $lockedGuest->region,
                    'province' => array_key_exists('province', $validated) ? $validated['province'] : $lockedGuest->province,
                    'city' => array_key_exists('city', $validated) ? $validated['city'] : $lockedGuest->city,
                    'barangay' => array_key_exists('barangay', $validated) ? $validated['barangay'] : $lockedGuest->barangay,
                    'street' => array_key_exists('street', $validated) ? $validated['street'] : $lockedGuest->street,
                    'zip_code' => array_key_exists('zip_code', $validated) ? $validated['zip_code'] : $lockedGuest->zip_code,
                    'timezone' => array_key_exists('timezone', $validated) ? $validated['timezone'] : $lockedGuest->timezone,
                    // Only set once the rest of this same update has
                    // actually committed - never on load, cancel, a
                    // validation failure, or a failed/rolled-back request.
                    'profile_last_updated_at' => now(),
                ]);
            }

            $freshUser = $user->fresh();
            $freshGuest = $lockedGuest?->fresh();
        });

        return response()->json([
            'user' => $freshUser,
            'guest' => $freshGuest,
            'profile_update' => $this->profileUpdateState($freshGuest),
        ]);
    }

    /**
     * Step 1 of the OTP-gated email change: re-verify the current password
     * (defense against a stolen/leaked bearer token trying to hijack the
     * account via a silent email swap - a live session alone is no longer
     * enough), then send a 6-digit code to the PROPOSED new address. Never
     * writes the new email until confirmEmailChange() verifies that code.
     */
    public function requestEmailChange(Request $request): JsonResponse
    {
        $user = auth()->user();

        $validated = $request->validate([
            'new_email' => 'required|email|unique:users,email',
            'current_password' => 'required|string',
        ]);

        if (! Hash::check($validated['current_password'], $user->password)) {
            return response()->json(['message' => 'Incorrect password.'], 422);
        }

        if (strcasecmp($validated['new_email'], $user->email) === 0) {
            return response()->json(['message' => 'That is already your registered email address.'], 422);
        }

        if (! $this->emailChange->requestChange($user, $validated['new_email'])) {
            return response()->json(['message' => "We couldn't send the verification code right now. Please try again in a few minutes."], 422);
        }

        return response()->json(['message' => 'Verification code sent to your new email address.']);
    }

    /**
     * Step 2: verify the code and actually apply the change.
     */
    public function confirmEmailChange(Request $request): JsonResponse
    {
        $user = auth()->user();

        $validated = $request->validate([
            'otp' => 'required|string|size:6',
        ]);

        $result = $this->emailChange->confirmChange($user, $validated['otp']);

        if (! $result['success']) {
            return response()->json(['message' => $result['message']], 422);
        }

        return response()->json([
            'message' => $result['message'],
            'user' => $user->fresh(),
        ]);
    }

    /**
     * Upload/replace the guest's profile picture. Unlike the ID card scan
     * (see Api\ReservationController::uploadIdCard), an avatar is fine on
     * the public disk - it's meant to be shown around the app, not kept
     * private.
     */
    public function updatePicture(Request $request): JsonResponse
    {
        $request->validate([
            'profile_picture' => 'required|image|max:5120',
        ]);

        $guest = auth()->user()->guest;
        if (! $guest) {
            return response()->json(['message' => 'No guest profile found for this account.'], 422);
        }

        if ($guest->profile_picture) {
            Storage::disk('public')->delete($guest->profile_picture);
        }

        $path = $request->file('profile_picture')->store('profile-pictures', 'public');
        $guest->update(['profile_picture' => $path]);

        return response()->json(['guest' => $guest->fresh()]);
    }

    /**
     * Voluntary self-deactivation - sets users.status = 'deactivated' and
     * revokes every API token, but never touches guest/booking/reservation/
     * payment/notification data and never sets deleted_at/restore_deadline
     * (that's a separate, unrelated legacy mechanism - see
     * 2026_08_10_120000_add_account_deletion_fields_to_users_table.php -
     * left untouched). Reactivation has no expiry and is entirely guest-
     * controlled: signing back in with the correct password triggers an
     * OTP reactivation challenge (see AuthController::login()/
     * reactivateVerify()). Requires the current password as a safety
     * confirmation against an unattended/unlocked device, same pattern the
     * old deleteAccount() used.
     */
    public function deactivateAccount(Request $request): JsonResponse
    {
        $user = auth()->user();

        $validated = $request->validate([
            'password' => 'required|string',
        ]);

        if (! Hash::check($validated['password'], $user->password)) {
            return response()->json(['message' => 'The password you entered is incorrect.'], 422);
        }

        if ($user->status === 'deactivated') {
            return response()->json(['message' => 'Your account is already deactivated.'], 422);
        }

        $user->update([
            'status' => 'deactivated',
            'deactivated_at' => now(),
        ]);
        $user->apiTokens()->delete();

        return response()->json([
            'message' => 'Your account has been deactivated. Sign in again anytime to reactivate it.',
        ]);
    }

    /**
     * Reactivates a still-restorable account. Only reachable with a token
     * from a pending-deletion login (see AuthController::login()) - the
     * client is expected to show a restore prompt using that token rather
     * than the normal dashboard.
     */
    public function restoreAccount(): JsonResponse
    {
        $user = auth()->user();

        if (! $user->isPendingDeletion()) {
            return response()->json(['message' => 'This account is not pending deletion.'], 422);
        }

        if (! $user->isRestorable()) {
            return response()->json(['message' => 'The 30-day restore window has expired.'], 422);
        }

        $user->update([
            'deleted_at' => null,
            'restore_deadline' => null,
        ]);

        return response()->json(['message' => 'Your account has been restored.', 'user' => $user->fresh()]);
    }

    /**
     * Same query chain as Guest\GuestController@payments - must also catch
     * deposit payments, which are created with billing_id null (and
     * reservation_id set instead) before the reservation is converted to a
     * booking; a billing_id-only query would hide them from the guest's
     * own payment history until conversion.
     */
    public function payments(Request $request): JsonResponse
    {
        $guest = auth()->user()->guest;
        $reservationIds = $guest->reservations()->pluck('id');
        $directBookingIds = Booking::where('guest_id', $guest->id)->whereNull('reservation_id')->pluck('id');
        $bookingIds = Booking::whereIn('reservation_id', $reservationIds)->pluck('id')->merge($directBookingIds);
        $billingIds = Billing::whereIn('booking_id', $bookingIds)->pluck('id');

        $query = Payment::with(['billing.booking.roomType', 'billing.booking.room', 'reservation.roomType', 'booking.roomType'])
            ->where(function ($q) use ($billingIds, $reservationIds, $directBookingIds) {
                $q->whereIn('billing_id', $billingIds)
                  ->orWhereIn('reservation_id', $reservationIds)
                  ->orWhereIn('booking_id', $directBookingIds);
            });

        if ($request->filled('status')) {
            $query->where('payment_status', $request->status);
        }

        $payments = $query->latest('payment_date')->paginate(15);

        $pendingBills = Billing::with('booking.roomType', 'booking.room')
            ->whereIn('booking_id', $bookingIds)
            ->whereIn('billing_status', ['pending', 'partial'])
            ->get();

        return response()->json([
            'payments' => $payments,
            'pending_bills' => $pendingBills,
        ]);
    }
}
