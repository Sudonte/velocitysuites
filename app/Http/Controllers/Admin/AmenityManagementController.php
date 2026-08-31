<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Amenity;
use App\Rules\MeaningfulDescription;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class AmenityManagementController extends Controller
{
    /**
     * The fixed category list the System Administrator picks from when
     * creating/editing an amenity - shared by create()/edit() (for the
     * dropdown) and store()/update() (for validation), so the two can
     * never drift out of sync.
     */
    public const CATEGORIES = [
        'Room Amenities',
        'Food & Beverage',
        'Internet & Technology',
        'Bathroom & Toiletries',
        'Comfort & Bedding',
        'Parking & Transportation',
        'Housekeeping & Laundry',
        'Guest Services',
        'Recreation & Facilities',
        'Additional Services',
    ];
    /**
     * Display list of amenities.
     */
    public function index(Request $request): View
    {
        $query = Amenity::query();

        // Search - grouped so a later ->where('status', ...) ANDs against the
        // whole name-or-description match, not just the last OR branch (an
        // ungrouped orWhere() lets SQL's AND-before-OR precedence silently
        // leak rows past the status filter).
        if ($request->has('search') && $request->search) {
            $search = $request->search;
            $query->where(function ($q) use ($search) {
                $q->where('amenity_name', 'like', "%{$search}%")
                  ->orWhere('description', 'like', "%{$search}%")
                  ->orWhere('category', 'like', "%{$search}%");
            });
        }

        // Filter by status
        if ($request->has('status') && $request->status) {
            $query->where('status', $request->status);
        }

        if ($request->filled('category')) {
            $query->where('category', $request->category);
        }

        // Filter by pricing type - derived from charge (isPaid()), not a
        // stored column, so filtered in PHP rather than SQL after paginating
        // would break pagination counts; instead filter via a charge
        // comparison that matches Amenity::isPaid()'s own definition.
        if ($request->filled('pricing_type')) {
            if ($request->pricing_type === 'paid') {
                $query->where('charge', '>', 0);
            } elseif ($request->pricing_type === 'free') {
                $query->where('charge', '<=', 0);
            }
        }

        $amenities = $query->withCount('amenityRequests')->latest()->paginate(15);
        $categories = self::CATEGORIES;

        return view('admin.amenities.index', compact('amenities', 'categories'));
    }

    /**
     * Show create amenity form.
     */
    public function create(): View
    {
        $categories = self::CATEGORIES;

        return view('admin.amenities.create', compact('categories'));
    }

    /**
     * Store a new amenity. Description must be a real 2-3 sentence
     * explanation (see MeaningfulDescription) - an amenity can never be
     * saved with empty, one-word, or placeholder text describing it.
     */
    public function store(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'amenity_name' => 'required|string|max:255',
            'description' => ['required', 'string', new MeaningfulDescription()],
            'category' => 'required|string|in:' . implode(',', self::CATEGORIES),
            'quantity' => 'required|integer|min:0',
            'charge' => 'required|numeric|min:0',
            'status' => 'required|in:active,inactive',
        ]);

        Amenity::create($validated);

        return redirect()->route('admin.amenities.index')->with('success', 'Amenity created successfully!');
    }

    /**
     * Show edit amenity form.
     */
    public function edit(Amenity $amenity): View
    {
        $amenity->load('amenityRequests');
        $categories = self::CATEGORIES;

        return view('admin.amenities.edit', compact('amenity', 'categories'));
    }

    /**
     * Update amenity information. Same description requirement as store().
     */
    public function update(Request $request, Amenity $amenity): RedirectResponse
    {
        $validated = $request->validate([
            'amenity_name' => 'required|string|max:255',
            'description' => ['required', 'string', new MeaningfulDescription()],
            'category' => 'required|string|in:' . implode(',', self::CATEGORIES),
            'quantity' => 'required|integer|min:0',
            'charge' => 'required|numeric|min:0',
            'status' => 'required|in:active,inactive',
        ]);

        $amenity->update($validated);

        return redirect()->route('admin.amenities.index')->with('success', 'Amenity updated successfully!');
    }

    /**
     * Toggle amenity status between active and inactive.
     */
    public function toggle(Amenity $amenity): RedirectResponse
    {
        $newStatus = $amenity->status === 'active' ? 'inactive' : 'active';
        $amenity->update(['status' => $newStatus]);

        return redirect()->route('admin.amenities.index')
            ->with('success', "Amenity {$newStatus}d successfully!");
    }

    /**
     * Remove an amenity from the catalog - soft-delete only (Amenity uses
     * SoftDeletes), never a hard delete. Any Room Type assignment
     * (room_type_amenity) is safely cascade-removed like any other
     * unassignment - a room type just loses that one amenity, nothing
     * breaks. Historical reservation_amenities/amenity_requests rows are
     * untouched and keep displaying correctly from their own independently
     * snapshotted name/category/charge, regardless of this amenity's
     * live/deleted state.
     */
    public function destroy(Amenity $amenity): RedirectResponse
    {
        $name = $amenity->amenity_name;
        $amenity->delete();

        return redirect()->route('admin.amenities.index')->with('success', "\"{$name}\" was deleted successfully.");
    }
}
