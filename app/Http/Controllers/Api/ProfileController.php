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
use Illuminate\Validation\Rules\Password;

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
            'mobile_number' => 'nullable|string|max:20',
            'address' => 'nullable|string|max:500',
        ]);

        $user->update([
            'first_name' => $validated['first_name'],
            'last_name' => $validated['last_name'],
            'middle_name' => $validated['middle_name'] ?? null,
            'email' => $validated['email'],
        ]);

        if ($guest) {
            $guest->update([
                'mobile_number' => $validated['mobile_number'] ?? $guest->mobile_number,
                'address' => $validated['address'] ?? $guest->address,
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
     * Same check as the web ProfileController@changePassword - was
     * entirely mock on the Android side before (compared against a
     * hardcoded local string, never the real password).
     */
    public function changePassword(Request $request): JsonResponse
    {
        $user = auth()->user();

        $validated = $request->validate([
            'current_password' => 'required|string',
            'new_password' => ['required', 'confirmed', Password::min(8)],
        ]);

        if (! Hash::check($validated['current_password'], $user->password)) {
            return response()->json(['message' => 'The current password is incorrect.'], 422);
        }

        $user->update(['password' => Hash::make($validated['new_password'])]);
        $user->apiTokens()->where('id', '!=', optional($request->attributes->get('api_token'))->id)->delete();

        return response()->json(['message' => 'Password changed successfully.']);
    }

    /**
     * Same query chain as Guest\GuestController@payments.
     */
    public function payments(Request $request): JsonResponse
    {
        $guest = auth()->user()->guest;
        $reservationIds = $guest->reservations()->pluck('id');
        $bookingIds = Booking::whereIn('reservation_id', $reservationIds)->pluck('id');
        $billingIds = Billing::whereIn('booking_id', $bookingIds)->pluck('id');

        $query = Payment::with(['billing.booking.reservation.room'])
            ->whereIn('billing_id', $billingIds);

        if ($request->filled('status')) {
            $query->where('payment_status', $request->status);
        }

        $payments = $query->latest('payment_date')->paginate(15);

        $pendingBills = Billing::with('booking.reservation.room')
            ->whereIn('booking_id', $bookingIds)
            ->whereIn('billing_status', ['pending', 'partial'])
            ->get();

        return response()->json([
            'payments' => $payments,
            'pending_bills' => $pendingBills,
        ]);
    }
}
