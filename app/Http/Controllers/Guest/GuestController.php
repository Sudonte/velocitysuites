<?php

namespace App\Http\Controllers\Guest;

use App\Http\Controllers\Controller;
use App\Models\Billing;
use App\Models\Payment;
use Illuminate\Http\Request;
use Illuminate\View\View;

class GuestController extends Controller
{
    /**
     * Display the guest's reservations (bookings list).
     * This is the main reservations page for guests.
     */
    public function bookings(Request $request): View
    {
        $guest = auth()->user()->guest;
        $query = $guest->reservations()->with(['room', 'booking.billing']);

        if ($request->filled('status')) {
            $query->where('status', $request->status);
        }

        $reservations = $query->latest('check_in')->paginate(15);

        return view('guest.reservations.index', compact('reservations'));
    }

    /**
     * Display guest payments and billing history.
     */
    public function payments(Request $request): View
    {
        $guest = auth()->user()->guest;
        $reservationIds = $guest->reservations()->pluck('id');
        $bookingIds = \App\Models\Booking::whereIn('reservation_id', $reservationIds)->pluck('id');
        $billingIds = Billing::whereIn('booking_id', $bookingIds)->pluck('id');

        $query = Payment::with(['billing.booking.reservation.room'])
            ->whereIn('billing_id', $billingIds);

        if ($request->filled('status')) {
            $query->where('payment_status', $request->status);
        }

        $payments = $query->latest('payment_date')->paginate(15);

        // Pending bills summary
        $pendingBills = Billing::with('booking.reservation.room')
            ->whereIn('booking_id', $bookingIds)
            ->whereIn('billing_status', ['pending', 'partial'])
            ->get();

        return view('guest.payments.index', compact('payments', 'pendingBills'));
    }

    /**
     * Display the guest profile / edit page.
     */
    public function profile(): View
    {
        return view('guest.profile.show', [
            'user' => auth()->user(),
            'guest' => auth()->user()->guest,
        ]);
    }

    /**
     * Update the guest profile.
     */
    public function updateProfile(Request $request)
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

        return back()->with('success', 'Profile updated successfully!');
    }
}