<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Promotion;
use App\Models\RoomType;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class PromotionManagementController extends Controller
{
    /**
     * Display list of promotions.
     */
    public function index(Request $request): View
    {
        $query = Promotion::query();

        // Search
        if ($request->has('search') && $request->search) {
            $query->where('promo_name', 'like', '%' . $request->search . '%')
                  ->orWhere('description', 'like', '%' . $request->search . '%');
        }

        // Filter by status
        if ($request->has('status') && $request->status) {
            $query->where('status', $request->status);
        }

        // Filter by discount type
        if ($request->has('discount_type') && $request->discount_type) {
            $query->where('discount_type', $request->discount_type);
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
     * Validate a promotion request. Discount promos require the discount
     * fields; amenity promos instead require at least one included amenity
     * (submitted as amenities[<id>] = quantity, 0/blank meaning excluded).
     */
    private function validatePromotion(Request $request): array
    {
        $validated = $request->validate([
            'promo_name' => 'required|string|max:255',
            'promo_type' => 'required|in:discount,amenity',
            'discount_type' => 'required_if:promo_type,discount|nullable|in:percentage,fixed',
            'discount_value' => 'required_if:promo_type,discount|nullable|numeric|min:0',
            'description' => 'nullable|string',
            'room_type_id' => 'nullable|exists:room_types,id',
            'start_date' => 'required|date',
            'end_date' => 'required|date|after_or_equal:start_date',
            'status' => 'required|in:active,inactive',
            'amenities' => 'array',
            'amenities.*' => 'nullable|integer|min:0|max:99',
        ]);

        if ($validated['promo_type'] === 'amenity') {
            $included = collect($validated['amenities'] ?? [])->filter(fn ($qty) => (int) $qty > 0);
            if ($included->isEmpty()) {
                throw \Illuminate\Validation\ValidationException::withMessages([
                    'amenities' => 'An amenity promotion must include at least one amenity (set a quantity above 0).',
                ]);
            }
            // Amenity promos carry no discount.
            $validated['discount_type'] = null;
            $validated['discount_value'] = null;
        }

        return $validated;
    }

    /**
     * Sync the included-amenities pivot from the validated payload.
     */
    private function syncAmenities(Promotion $promotion, array $validated): void
    {
        if ($validated['promo_type'] === 'amenity') {
            $sync = collect($validated['amenities'] ?? [])
                ->filter(fn ($qty) => (int) $qty > 0)
                ->map(fn ($qty) => ['quantity' => (int) $qty])
                ->all();
            $promotion->amenities()->sync($sync);
        } else {
            $promotion->amenities()->detach();
        }
    }

    /**
     * Store a new promotion.
     */
    public function store(Request $request): RedirectResponse
    {
        $validated = $this->validatePromotion($request);

        $promotion = Promotion::create($validated);
        $this->syncAmenities($promotion, $validated);

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

        $promotion->update($validated);
        $this->syncAmenities($promotion, $validated);

        return redirect()->route('admin.promotions.index')->with('success', 'Promotion updated successfully!');
    }

    /**
     * Toggle promotion status between active and inactive.
     */
    public function toggle(Promotion $promotion): RedirectResponse
    {
        $newStatus = $promotion->status === 'active' ? 'inactive' : 'active';
        $promotion->update(['status' => $newStatus]);

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
