<?php

namespace App\Http\Controllers;

use App\Models\Amenity;
use Illuminate\View\View;

class PublicAmenityController extends Controller
{
    /**
     * Dedicated Amenities page (public access) - fully admin-managed via
     * Admin\AmenityManagementController; this mirrors the exact
     * active-only query Api\CatalogController::amenities() already uses
     * for the mobile app, so the public website and the app never drift.
     */
    public function index(): View
    {
        $amenities = Amenity::where('status', 'active')->orderBy('category')->orderBy('amenity_name')->get();

        return view('public.amenities.index', ['amenities' => $amenities]);
    }
}
