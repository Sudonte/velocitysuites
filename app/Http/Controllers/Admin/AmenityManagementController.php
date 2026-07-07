<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Amenity;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class AmenityManagementController extends Controller
{
    /**
     * Display list of amenities.
     */
    public function index(Request $request): View
    {
        $query = Amenity::query();

        // Search
        if ($request->has('search') && $request->search) {
            $query->where('amenity_name', 'like', '%' . $request->search . '%')
                  ->orWhere('description', 'like', '%' . $request->search . '%');
        }

        // Filter by status
        if ($request->has('status') && $request->status) {
            $query->where('status', $request->status);
        }

        $amenities = $query->withCount('amenityRequests')->latest()->paginate(15);

        return view('admin.amenities.index', compact('amenities'));
    }

    /**
     * Show create amenity form.
     */
    public function create(): View
    {
        return view('admin.amenities.create');
    }

    /**
     * Store a new amenity.
     */
    public function store(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'amenity_name' => 'required|string|max:255',
            'description' => 'nullable|string',
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

        return view('admin.amenities.edit', compact('amenity'));
    }

    /**
     * Update amenity information.
     */
    public function update(Request $request, Amenity $amenity): RedirectResponse
    {
        $validated = $request->validate([
            'amenity_name' => 'required|string|max:255',
            'description' => 'nullable|string',
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
     * Delete amenity.
     */
    public function destroy(Amenity $amenity): RedirectResponse
    {
        $activeRequests = $amenity->amenityRequests()
            ->whereIn('status', ['pending', 'approved'])
            ->count();

        if ($activeRequests > 0) {
            return redirect()->route('admin.amenities.index')
                ->with('error', "Cannot delete amenity with {$activeRequests} active request(s). Resolve them first.");
        }

        $amenity->delete();

        return redirect()->route('admin.amenities.index')->with('success', 'Amenity deleted successfully!');
    }
}
