<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Support\Activity;
use App\Models\Amenity;
use App\Rules\MeaningfulDescription;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
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

        $amenities = $query->withCount('amenityRequests')->latest()->paginate(\App\Support\PerPage::resolve($request))->withQueryString();
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
     * amenity_name is trimmed then checked for uniqueness among non-deleted
     * amenities (whereNull('deleted_at') - Amenity uses SoftDeletes, and the
     * `unique` rule queries the raw table directly, so without that clause
     * a previously soft-deleted amenity's name would wrongly block reuse).
     * The table's default utf8mb4_unicode_ci collation already makes this
     * comparison case-insensitive at the DB level, so "Towel" and "TOWEL"
     * are correctly treated as the same name without extra normalization.
     */
    public function store(Request $request): RedirectResponse
    {
        $request->merge(['amenity_name' => trim((string) $request->input('amenity_name'))]);

        $validated = $request->validate([
            'amenity_name' => [
                'required', 'string', 'max:255',
                Rule::unique('amenities', 'amenity_name')->whereNull('deleted_at'),
            ],
            'description' => ['required', 'string', new MeaningfulDescription()],
            'category' => 'required|string|in:' . implode(',', self::CATEGORIES),
            'quantity_mode' => 'required|in:limited,unlimited',
            'quantity' => 'required_if:quantity_mode,limited|nullable|integer|min:0',
            'charge' => 'required|numeric|min:0',
            'status' => 'required|in:active,inactive',
        ], [
            'amenity_name.unique' => 'An amenity with this name already exists.',
        ]);

        Amenity::create($this->withQuantityMode($validated));

        Activity::log('Created amenity', $request->input('amenity_name'));

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
     * Update amenity information. Same description requirement and
     * duplicate-name check as store(), ignoring this amenity's own row.
     */
    public function update(Request $request, Amenity $amenity): RedirectResponse
    {
        $request->merge(['amenity_name' => trim((string) $request->input('amenity_name'))]);

        $validated = $request->validate([
            'amenity_name' => [
                'required', 'string', 'max:255',
                Rule::unique('amenities', 'amenity_name')->ignore($amenity->id)->whereNull('deleted_at'),
            ],
            'description' => ['required', 'string', new MeaningfulDescription()],
            'category' => 'required|string|in:' . implode(',', self::CATEGORIES),
            'quantity_mode' => 'required|in:limited,unlimited',
            'quantity' => 'required_if:quantity_mode,limited|nullable|integer|min:0',
            'charge' => 'required|numeric|min:0',
            'status' => 'required|in:active,inactive',
        ], [
            'amenity_name.unique' => 'An amenity with this name already exists.',
        ]);

        $amenity->update($this->withQuantityMode($validated, $amenity));

        Activity::log('Updated amenity', $amenity->amenity_name . ' (' . ($amenity->is_unlimited ? 'unlimited' : $amenity->quantity . ' in stock') . ', ' . $amenity->status . ')', $amenity);

        return redirect()->route('admin.amenities.index')->with('success', 'Amenity updated successfully!');
    }

    /**
     * Maps the form's quantity mode onto the model: Unlimited sets
     * is_unlimited and keeps the last stock number (so switching back to
     * Limited doesn't lose it); Limited uses the entered stock.
     */
    private function withQuantityMode(array $validated, ?Amenity $amenity = null): array
    {
        $unlimited = $validated['quantity_mode'] === 'unlimited';
        unset($validated['quantity_mode']);

        $validated['is_unlimited'] = $unlimited;
        $validated['quantity'] = $unlimited
            ? (int) ($validated['quantity'] ?? $amenity?->quantity ?? 0)
            : (int) $validated['quantity'];

        return $validated;
    }

    /**
     * Toggle amenity status between active and inactive.
     */
    public function toggle(Amenity $amenity): RedirectResponse
    {
        $newStatus = $amenity->status === 'active' ? 'inactive' : 'active';
        $amenity->update(['status' => $newStatus]);

        Activity::log(($amenity->status === 'active' ? 'Activated' : 'Deactivated') . ' amenity', $amenity->amenity_name, $amenity);

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

        Activity::log('Deleted amenity', $name);

        return redirect()->route('admin.amenities.index')->with('success', "\"{$name}\" was deleted successfully.");
    }
}
