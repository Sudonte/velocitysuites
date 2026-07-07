<?php

namespace App\Http\Controllers;

use App\Models\Room;
use Illuminate\Http\RedirectResponse;
use Illuminate\View\View;

class LandingPageController extends Controller
{
    /**
     * Show the landing page.
     * If user is authenticated, redirect to their appropriate dashboard.
     */
    public function index(): View|RedirectResponse
    {
        // If user is authenticated, redirect to their role-based dashboard
        if (auth()->check()) {
            $role = auth()->user()->role;

            return match ($role) {
                'admin' => redirect()->route('admin.dashboard'),
                'manager' => redirect()->route('manager.dashboard'),
                'receptionist' => redirect()->route('receptionist.dashboard'),
                'guest' => redirect()->route('guest.dashboard'),
                default => redirect()->route('home'),
            };
        }

        $featuredRooms = Room::where('status', 'available')
            ->latest()
            ->take(6)
            ->get();

        return view('welcome', compact('featuredRooms'));
    }
}