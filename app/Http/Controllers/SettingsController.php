<?php

namespace App\Http\Controllers;

use App\Models\Booking;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * Shared Settings page (every authenticated role, same as Profile/
 * Notifications) - currently just Appearance (light/dark) and, for a
 * receptionist, the entry point into Archived Bookings (moved here from
 * its own tab on Receptionist\BookingController::index() - the listing
 * itself still lives there, this only relocates how it's reached).
 */
class SettingsController extends Controller
{
    public function index(): View
    {
        $user = auth()->user();

        $archivedBookingsCount = $user->role === 'receptionist'
            ? Booking::whereNotNull('hidden_at')->count()
            : null;

        return view('settings.index', compact('user', 'archivedBookingsCount'));
    }

    public function updateTheme(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'theme' => 'required|in:light,dark',
        ]);

        auth()->user()->update(['theme' => $validated['theme']]);

        return back()->with('success', 'Appearance updated.');
    }
}
