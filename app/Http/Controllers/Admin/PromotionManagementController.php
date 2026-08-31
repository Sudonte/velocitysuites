<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Promotion;
use App\Models\RoomType;
use App\Support\Activity;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\View\View;

class PromotionManagementController extends Controller
{
    /**
     * Display list of promotions.
     */
    public function index(Request $request): View
    {
        $query = Promotion::query();

        // Search - grouped so a later ->where('status', ...) ANDs against the
        // whole name-or-description match, not just the last OR branch (an
        // ungrouped orWhere() lets SQL's AND-before-OR precedence silently
        // leak rows past the status filter).
        if ($request->has('search') && $request->search) {
            $search = $request->search;
            $query->where(function ($q) use ($search) {
                $q->where('promo_name', 'like', "%{$search}%")
                  ->orWhere('description', 'like', "%{$search}%");
            });
        }

        // Filter by status
        if ($request->has('status') && $request->status) {
            $query->where('status', $request->status);
        }

        $promotions = $query->with('amenities')->latest()->paginate(15);
        $roomTypes = RoomType::orderBy('name')->get();

        return view('admin.promotions.index', compact('promotions', 'roomTypes'));
    }

    /**
     * Show create promotion form.
     */
    public function create(): View
    {
        $roomTypes = RoomType::orderBy('name')->get();
        $amenities = \App\Models\Amenity::where('status', 'active')->orderBy('amenity_name')->get();

        return view('admin.promotions.create', compact('roomTypes', 'amenities'));
    }

    /**
     * Validate a promotion request. Promotions are package/amenity-only -
     * authorized discounts (Senior Citizen, PWD, etc.) live in the
     * separate Discount module now, applied manually by the receptionist
     * at billing. Requires at least one included amenity (submitted as
     * amenities[<id>] = quantity, 0/blank meaning excluded).
     */
    private function validatePromotion(Request $request): array
    {
        $validated = $request->validate([
            'promo_name' => 'required|string|max:255',
            'description' => 'nullable|string',
            'room_type_id' => 'nullable|exists:room_types,id',
            'start_date' => 'required|date',
            'end_date' => 'required|date|after_or_equal:start_date',
            'status' => 'required|in:active,inactive',
            'amenities' => 'array',
            'amenities.*' => 'nullable|integer|min:0|max:99',
        ]);

        $included = collect($validated['amenities'] ?? [])->filter(fn ($qty) => (int) $qty > 0);
        if ($included->isEmpty()) {
            throw \Illuminate\Validation\ValidationException::withMessages([
                'amenities' => 'A promotion must include at least one amenity (set a quantity above 0).',
            ]);
        }

        $validated['promo_type'] = 'amenity';

        return $validated;
    }

    /**
     * Sync the included-amenities pivot from the validated payload.
     */
    private function syncAmenities(Promotion $promotion, array $validated): void
    {
        $sync = collect($validated['amenities'] ?? [])
            ->filter(fn ($qty) => (int) $qty > 0)
            ->map(fn ($qty) => ['quantity' => (int) $qty])
            ->all();
        $promotion->amenities()->sync($sync);
    }

    /**
     * Stores the uploaded promo image (same validation convention as
     * Admin\AnnouncementManagementController::storeImages()) and returns
     * the stored path, or null if none was uploaded this request.
     */
    private function storeImage(Request $request): ?string
    {
        if (! $request->hasFile('image')) {
            return null;
        }

        $request->validate([
            'image' => 'image|mimes:jpeg,png,jpg|max:5120',
        ]);

        return $request->file('image')->store('promotion-images', 'public');
    }

    /**
     * Store a new promotion.
     */
    public function store(Request $request): RedirectResponse
    {
        $validated = $this->validatePromotion($request);
        $validated['image'] = $this->storeImage($request);

        $promotion = Promotion::create($validated);
        $this->syncAmenities($promotion, $validated);

        Activity::log('Created promotion', $promotion->promo_name, $promotion);

        return redirect()->route('admin.promotions.index')->with('success', 'Promotion created successfully!');
    }

    /**
     * Show edit promotion form.
     */
    public function edit(Promotion $promotion): View
    {
        $roomTypes = RoomType::orderBy('name')->get();
        $amenities = \App\Models\Amenity::where('status', 'active')->orderBy('amenity_name')->get();
        $promotion->load('amenities');

        return view('admin.promotions.edit', compact('promotion', 'roomTypes', 'amenities'));
    }

    /**
     * Update promotion information.
     */
    public function update(Request $request, Promotion $promotion): RedirectResponse
    {
        $validated = $this->validatePromotion($request);

        $newImage = $this->storeImage($request);
        if ($newImage !== null) {
            if ($promotion->image) {
                Storage::disk('public')->delete($promotion->image);
            }
            $validated['image'] = $newImage;
        }

        $promotion->update($validated);
        $this->syncAmenities($promotion, $validated);

        Activity::log('Updated promotion', $promotion->promo_name, $promotion);

        return redirect()->route('admin.promotions.index')->with('success', 'Promotion updated successfully!');
    }

    /**
     * Toggle promotion status between active and inactive.
     */
    public function toggle(Promotion $promotion): RedirectResponse
    {
        $newStatus = $promotion->status === 'active' ? 'inactive' : 'active';
        $promotion->update(['status' => $newStatus]);

        Activity::log("Set promotion to {$newStatus}", $promotion->promo_name, $promotion);

        return redirect()->route('admin.promotions.index')
            ->with('success', "Promotion {$newStatus}d successfully!");
    }

    /**
     * Delete promotion.
     */
    public function destroy(Promotion $promotion): RedirectResponse
    {
        $promotion->delete();

        return redirect()->route('admin.promotions.index')->with('success', 'Promotion deleted successfully!');
    }
}
