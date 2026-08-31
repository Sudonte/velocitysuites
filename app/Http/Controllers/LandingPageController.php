<?php

namespace App\Http\Controllers;

use App\Models\Amenity;
use App\Models\Announcement;
use App\Models\Discount;
use App\Models\Promotion;
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
            ->get();

        // Same active-only query as Api\CatalogController::amenities() / the
        // dedicated public.amenities.index page - top 6 so the Home page
        // section stays glanceable, "View Amenities" links to the full list.
        $amenities = Amenity::where('status', 'active')->orderBy('amenity_name')->take(6)->get();

        // Same active-only + date-range filters as Api\CatalogController -
        // only promotions genuinely running right now, never a stale one
        // whose date range already lapsed.
        $promotions = Promotion::with(['roomType', 'amenities'])
            ->where('status', 'active')
            ->whereDate('start_date', '<=', now())
            ->whereDate('end_date', '>=', now())
            ->orderBy('start_date')
            ->get();

        $discounts = Discount::where('status', 'active')->orderBy('name')->get();

        // Public-audience announcements only, newest first, capped so the
        // Home page can't be overrun by a long publishing history - "Read
        // More" surfaces the rest of a long one via a per-card modal.
        $announcements = Announcement::visibleTo('public')->take(4)->get();

        return view('welcome', [
            'featuredRoomTypes' => $featuredRoomTypes,
            'amenities' => $amenities,
            'promotions' => $promotions,
            'discounts' => $discounts,
            'announcements' => $announcements,
        ]);
    }
}
