<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Room;
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
     * Show one room type with all its rooms (the per-type room
     * management workspace: add rooms in bulk, edit, see status).
     */
    public function show(RoomType $roomType): View
    {
        $rooms = $roomType->rooms()->orderBy('room_number')->paginate(20);

        // Preview of the next numbers the bulk-add would generate.
        $nextNumbers = $roomType->nextRoomNumbers(3);

        return view('admin.room-types.show', compact('roomType', 'rooms', 'nextNumbers'));
    }

    /**
     * Show create room type form.
     */
    public function create(): View
    {
        $existingFormats = RoomType::whereNotNull('number_format')->distinct()->pluck('number_format');

        return view('admin.room-types.create', compact('existingFormats'));
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
            'description' => 'nullable|string|max:2000',
            'number_format' => ['required', 'string', 'max:20', 'regex:/^[A-Za-z0-9\-]*#+[A-Za-z0-9\-]*$/'],
            'status' => 'required|in:active,inactive',
        ], [
            'number_format.regex' => 'The numbering format must contain a run of # placeholders (e.g. 1## for 101, 102... or D-## for D-01, D-02...).',
        ]);

        $roomType = RoomType::create($validated);

        return redirect()->route('admin.room-types.show', $roomType)->with('success', 'Room type created! You can now add its rooms below.');
    }

    /**
     * Bulk-add rooms to this type. Numbers are generated from the type's
     * numbering format; all rooms in the batch share name/status/description.
     */
    public function storeRooms(Request $request, RoomType $roomType): RedirectResponse
    {
        $validated = $request->validate([
            'quantity' => 'required|integer|min:1|max:50',
            'room_name' => 'required|string|max:255',
            'room_capacity' => 'required|integer|min:1',
            'rate_override' => 'nullable|numeric|min:0',
            'status' => 'required|in:available,maintenance',
            'description' => 'nullable|string|max:2000',
        ]);

        $numbers = $roomType->nextRoomNumbers($validated['quantity']);

        if (count($numbers) < $validated['quantity']) {
            return back()->with('error',
                'The numbering format "' . $roomType->number_format . '" only has ' . count($numbers) .
                ' free number(s) left. Widen the format (more # digits) or reduce the quantity.');
        }

        foreach ($numbers as $number) {
            Room::create([
                'room_number' => $number,
                'room_name' => $validated['room_name'],
                'room_type_id' => $roomType->id,
                'room_capacity' => $validated['room_capacity'],
                'rate_override' => $validated['rate_override'] ?? null,
                'description' => $validated['description'] ?? null,
                'status' => $validated['status'],
            ]);
        }

        return redirect()->route('admin.room-types.show', $roomType)
            ->with('success', count($numbers) . ' room(s) added: ' . implode(', ', $numbers));
    }

    /**
     * Show edit room type form.
     */
    public function edit(RoomType $roomType): View
    {
        $existingFormats = RoomType::whereNotNull('number_format')
            ->where('id', '!=', $roomType->id)
            ->distinct()
            ->pluck('number_format');

        return view('admin.room-types.edit', compact('roomType', 'existingFormats'));
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
            'description' => 'nullable|string|max:2000',
            'number_format' => ['required', 'string', 'max:20', 'regex:/^[A-Za-z0-9\-]*#+[A-Za-z0-9\-]*$/'],
            'status' => 'required|in:active,inactive',
        ], [
            'number_format.regex' => 'The numbering format must contain a run of # placeholders (e.g. 1## for 101, 102... or D-## for D-01, D-02...).',
        ]);

        $roomType->update($validated);

        return redirect()->route('admin.room-types.index')->with('success', 'Room type updated successfully!');
    }

    /**
     * Deactivate a room type instead of deleting it - rooms.room_type_id,
     * reservations.room_type_id and bookings.room_type_id all have no
     * cascade/nullOnDelete, so a real delete is blocked (raw FK error) the
     * moment any room or reservation of this type has ever existed, which
     * is true for any type actually in use.
     */
    public function deactivate(RoomType $roomType): RedirectResponse
    {
        $roomType->update(['status' => 'inactive']);

        return redirect()->route('admin.room-types.index')->with('success', 'Room type deactivated.');
    }

    /**
     * Reactivate a deactivated room type.
     */
    public function reactivate(RoomType $roomType): RedirectResponse
    {
        $roomType->update(['status' => 'active']);

        return redirect()->route('admin.room-types.index')->with('success', 'Room type reactivated.');
    }
}
