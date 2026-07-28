<?php

namespace App\Http\Controllers;

use App\Models\Room;
use App\Models\RoomType;
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

        $featuredRoomTypes = RoomType::where('status', 'active')
            ->latest()
            ->take(6)
            ->get()
            ->each(function (RoomType $roomType) {
                $roomType->representative_image = Room::where('room_type_id', $roomType->id)
                    ->whereNotNull('image')->value('image');
            });

        return view('welcome', ['featuredRoomTypes' => $featuredRoomTypes]);
    }
}