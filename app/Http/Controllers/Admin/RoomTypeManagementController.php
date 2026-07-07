<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\RoomType;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class RoomTypeManagementController extends Controller
{
    /**
     * Display list of room types.
     */
    public function index(Request $request): View
    {
        $query = RoomType::withCount('rooms');

        if ($request->has('search') && $request->search) {
            $query->where('name', 'like', '%' . $request->search . '%');
        }

        if ($request->has('status') && $request->status) {
            $query->where('status', $request->status);
        }

        $roomTypes = $query->orderBy('name')->paginate(15);

        return view('admin.room-types.index', compact('roomTypes'));
    }

    /**
     * Show create room type form.
     */
    public function create(): View
    {
        return view('admin.room-types.create');
    }

    /**
     * Store a new room type.
     */
    public function store(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'name' => 'required|string|max:255|unique:room_types,name',
            'rate' => 'required|numeric|min:0',
            'capacity' => 'required|integer|min:1',
            'status' => 'required|in:active,inactive',
        ]);

        RoomType::create($validated);

        return redirect()->route('admin.room-types.index')->with('success', 'Room type created successfully!');
    }

    /**
     * Show edit room type form.
     */
    public function edit(RoomType $roomType): View
    {
        return view('admin.room-types.edit', compact('roomType'));
    }

    /**
     * Update room type information.
     */
    public function update(Request $request, RoomType $roomType): RedirectResponse
    {
        $validated = $request->validate([
            'name' => 'required|string|max:255|unique:room_types,name,' . $roomType->id,
            'rate' => 'required|numeric|min:0',
            'capacity' => 'required|integer|min:1',
            'status' => 'required|in:active,inactive',
        ]);

        $roomType->update($validated);

        return redirect()->route('admin.room-types.index')->with('success', 'Room type updated successfully!');
    }

    /**
     * Delete room type (only when no rooms are attached to it).
     */
    public function destroy(RoomType $roomType): RedirectResponse
    {
        if ($roomType->rooms()->exists()) {
            return back()->with('error', 'Cannot delete a room type that still has rooms. Reassign or delete those rooms first.');
        }

        if ($roomType->reservations()->whereIn('status', ['pending', 'confirmed', 'checked_in'])->exists()) {
            return back()->with('error', 'Cannot delete a room type with active reservations.');
        }

        $roomType->delete();

        return redirect()->route('admin.room-types.index')->with('success', 'Room type deleted successfully!');
    }
}
