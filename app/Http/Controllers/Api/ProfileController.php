<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Billing;
use App\Models\Booking;
use App\Models\Payment;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

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
