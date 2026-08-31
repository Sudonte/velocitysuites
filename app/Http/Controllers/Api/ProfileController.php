<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Billing;
use App\Models\Booking;
use App\Models\Payment;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;

class ProfileController extends Controller
{
    /**
     * Same shape as Guest\GuestController@profile.
     */
    public function show(): JsonResponse
    {
        $user = auth()->user();

        return response()->json([
            'user' => $user,
            'guest' => $user->guest,
        ]);
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
            'email' => 'required|email|unique:users,email,' . $user->id,
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

        $user->update([
            'first_name' => $validated['first_name'],
            'last_name' => $validated['last_name'],
            'middle_name' => $validated['middle_name'] ?? null,
            'email' => $validated['email'],
        ]);

        if ($guest) {
            $guest->update([
                'mobile_number' => $validated['mobile_number'] ?? $guest->mobile_number,
                'gender' => array_key_exists('gender', $validated) ? $validated['gender'] : $guest->gender,
                'date_of_birth' => array_key_exists('date_of_birth', $validated) ? $validated['date_of_birth'] : $guest->date_of_birth,
                'age' => array_key_exists('age', $validated) ? $validated['age'] : $guest->age,
                'address' => $validated['address'] ?? $guest->address,
                'country' => array_key_exists('country', $validated) ? $validated['country'] : $guest->country,
                'region' => array_key_exists('region', $validated) ? $validated['region'] : $guest->region,
                'province' => array_key_exists('province', $validated) ? $validated['province'] : $guest->province,
                'city' => array_key_exists('city', $validated) ? $validated['city'] : $guest->city,
                'barangay' => array_key_exists('barangay', $validated) ? $validated['barangay'] : $guest->barangay,
                'street' => array_key_exists('street', $validated) ? $validated['street'] : $guest->street,
                'zip_code' => array_key_exists('zip_code', $validated) ? $validated['zip_code'] : $guest->zip_code,
                'timezone' => array_key_exists('timezone', $validated) ? $validated['timezone'] : $guest->timezone,
            ]);
        }

        return response()->json(['user' => $user->fresh(), 'guest' => $guest?->fresh()]);
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
     * Soft-deletes the account into a 30-day restorable state - does not
     * erase any data immediately. Requires the current password as a
     * safety confirmation, same pattern as changePassword(). The guest
     * can still log back in during the window (see
     * AuthController::login(), which still issues a token for a
     * pending-deletion account inside its window) to call restoreAccount()
     * below; once restore_deadline passes, login refuses the account and
     * the scheduled Console\Commands\PurgeExpiredDeletedAccounts command
     * removes it for good.
     */
    public function deleteAccount(Request $request): JsonResponse
    {
        $user = auth()->user();

        $validated = $request->validate([
            'password' => 'required|string',
        ]);

        if (! Hash::check($validated['password'], $user->password)) {
            return response()->json(['message' => 'The password you entered is incorrect.'], 422);
        }

        $user->update([
            'deleted_at' => now(),
            'restore_deadline' => now()->addDays(30),
        ]);
        $user->apiTokens()->delete();

        return response()->json([
            'message' => 'Your account has been deactivated. You can restore it by logging in again within 30 days.',
            'restore_deadline' => $user->restore_deadline->toIso8601String(),
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
