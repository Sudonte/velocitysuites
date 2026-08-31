<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Amenity;
use App\Models\Announcement;
use App\Models\Discount;
use App\Models\Promotion;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

/**
 * Read-only, active-only catalogs the mobile app sources instead of
 * hardcoding its own copies - all three are entirely admin-managed
 * (Admin\AmenityManagementController / PromotionManagementController /
 * DiscountManagementController), the app just mirrors whatever's
 * currently active.
 */
class CatalogController extends Controller
{
    /**
     * Optional ?pricing_type=paid|free so the same catalog serves both the
     * landing page's Amenities section (no filter - shows everything) and
     * the booking screen's paid add-on picker (?pricing_type=paid - Free/
     * Included amenities must never appear there as if they were
     * chargeable add-ons).
     */
    public function amenities(Request $request): JsonResponse
    {
        $query = Amenity::where('status', 'active');

        if ($request->query('pricing_type') === 'paid') {
            $query->where('charge', '>', 0);
        } elseif ($request->query('pricing_type') === 'free') {
            $query->where('charge', 0);
        }

        return response()->json(
            $query->orderBy('category')->orderBy('amenity_name')
                ->get(['id', 'amenity_name', 'category', 'description', 'charge', 'quantity'])
        );
    }

    public function promotions(): JsonResponse
    {
        return response()->json(
            Promotion::with(['amenities', 'roomType'])
                ->where('status', 'active')
                ->whereDate('start_date', '<=', now())
                ->whereDate('end_date', '>=', now())
                ->orderBy('start_date')
                ->get()
        );
    }

    public function discounts(): JsonResponse
    {
        return response()->json(
            Discount::where('status', 'active')->orderBy('name')->get(['id', 'name', 'discount_type', 'value', 'description'])
        );
    }

    /**
     * Guest-audience announcements - the exact same App\Models\Announcement::visibleTo()
     * scope the public Home page, every role dashboard's Notifications, and
     * NotificationService::notifyAnnouncement() all use, so the mobile app's
     * landing.xml Announcements section is never a second/duplicate source of
     * truth - it's the same centralized rows, just rendered as a browsable
     * list instead of (or alongside) a notification. Absolute image URLs
     * (the app can't resolve a bare storage-relative path the way a Blade
     * view can), and target_audience passed through as-is so the app can
     * render the same "who this is for" badge the web side shows.
     */
    public function announcements(): JsonResponse
    {
        $announcements = Announcement::visibleTo('guest')->get()->map(function (Announcement $announcement) {
            return [
                'id' => $announcement->id,
                'title' => $announcement->title,
                'content' => $announcement->content,
                'published_at' => optional($announcement->published_at)->toDateString(),
                'target_audience' => $announcement->target_audience,
                'images' => collect($announcement->images ?? [])
                    ->map(fn ($path) => Storage::disk('public')->url($path))
                    ->values(),
            ];
        });

        return response()->json($announcements);
    }
}