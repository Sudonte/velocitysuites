<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Http\RedirectResponse;

class BookingIntentController extends Controller
{
    /**
     * Store booking intent before redirecting to login.
     */
    public function store(Request $request): RedirectResponse
    {
        // Validate required data
        $validated = $request->validate([
            'room_type_id' => 'required|integer|exists:room_types,id',
            'check_in' => 'nullable|date',
            'check_out' => 'nullable|date|after:check_in',
            'guests' => 'nullable|integer|min:1',
        ]);

        // Store booking intent in session
        session()->put('booking_intent', [
            'room_type_id' => $validated['room_type_id'],
            'check_in' => $validated['check_in'] ?? null,
            'check_out' => $validated['check_out'] ?? null,
            'guests' => $validated['guests'] ?? 1,
            'created_at' => now(),
        ]);

        // Redirect to login with intended destination
        return redirect()->route('login');
    }

    /**
     * Get stored booking intent (for use after authentication).
     */
    public static function getBookingIntent(): ?array
    {
        return session()->get('booking_intent');
    }

    /**
     * Clear booking intent after it's been used.
     */
    public static function clearBookingIntent(): void
    {
        session()->forget('booking_intent');
    }
}