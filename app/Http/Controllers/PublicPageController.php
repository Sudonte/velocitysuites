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
        return view('public.about');
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
