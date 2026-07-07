<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Promotion;
use App\Models\Room;
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

        $promotions = $query->latest()->paginate(15);
        $roomTypes = Room::distinct()->pluck('room_type');

        return view('admin.promotions.index', compact('promotions', 'roomTypes'));
    }

    /**
     * Show create promotion form.
     */
    public function create(): View
    {
        $roomTypes = Room::distinct()->pluck('room_type');

        return view('admin.promotions.create', compact('roomTypes'));
    }

    /**
     * Store a new promotion.
     */
    public function store(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'promo_name' => 'required|string|max:255',
            'discount_type' => 'required|in:percentage,fixed',
            'discount_value' => 'required|numeric|min:0',
            'description' => 'nullable|string',
            'room_type' => 'nullable|string',
            'start_date' => 'required|date',
            'end_date' => 'required|date|after_or_equal:start_date',
            'status' => 'required|in:active,inactive',
        ]);

        Promotion::create($validated);

        return redirect()->route('admin.promotions.index')->with('success', 'Promotion created successfully!');
    }

    /**
     * Show edit promotion form.
     */
    public function edit(Promotion $promotion): View
    {
        $roomTypes = Room::distinct()->pluck('room_type');

        return view('admin.promotions.edit', compact('promotion', 'roomTypes'));
    }

    /**
     * Update promotion information.
     */
    public function update(Request $request, Promotion $promotion): RedirectResponse
    {
        $validated = $request->validate([
            'promo_name' => 'required|string|max:255',
            'discount_type' => 'required|in:percentage,fixed',
            'discount_value' => 'required|numeric|min:0',
            'description' => 'nullable|string',
            'room_type' => 'nullable|string',
            'start_date' => 'required|date',
            'end_date' => 'required|date|after_or_equal:start_date',
            'status' => 'required|in:active,inactive',
        ]);

        $promotion->update($validated);

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
