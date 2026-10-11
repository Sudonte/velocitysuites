<?php

namespace App\Http\Controllers;

use Illuminate\View\View;

class PublicPageController extends Controller
{
    /**
     * Dedicated About Us page (public access) - previously just the #about
     * anchor section on the single landing page (welcome.blade.php).
     */
    public function about(): View
    {
        // The real active room types (names, capacity ranges, room counts), not a hard-coded list.
        $roomTypes = \App\Models\RoomType::where('status', 'active')
            ->withCount(['rooms' => fn ($q) => $q->whereNull('archived_at')])
            ->orderBy('rate')->get()
            ->filter(fn ($type) => $type->rooms_count > 0)->values(); // types with no rooms aren't bookable

        return view('public.about', compact('roomTypes'));
    }

    /**
     * Dedicated Contact Us page (public access) - the map + contact-info
     * grid previously lived only inside welcome.blade.php's #contact
     * section; that markup now lives here, and the Home page shows a
     * condensed teaser linking to this page instead of duplicating it.
     */
    public function contact(): View
    {
        return view('public.contact');
    }
}
