<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Room;
use App\Models\RoomImage;
use App\Models\RoomType;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class RoomManagementController extends Controller
{
    /**
     * Display list of rooms.
     */
    public function index(Request $request): View
    {
        $query = Room::query();

        // Search
        if ($request->has('search') && $request->search) {
            $query->where('room_name', 'like', '%' . $request->search . '%')
                  ->orWhere('room_number', 'like', '%' . $request->search . '%');
        }

        // Filter by status
        if ($request->has('status') && $request->status) {
            $query->where('status', $request->status);
        }

        // Filter by type
        if ($request->has('room_type_id') && $request->room_type_id) {
            $query->where('room_type_id', $request->room_type_id);
        }

        $rooms = $query->paginate(15);
        $roomTypes = RoomType::orderBy('name')->get();

        return view('admin.rooms.index', compact('rooms', 'roomTypes'));
    }

    /**
     * Show create room form.
     */
    public function create(): View
    {
        $roomTypes = RoomType::where('status', 'active')->orderBy('name')->get();

        return view('admin.rooms.create', compact('roomTypes'));
    }

    /**
     * Store a new room.
     */
    public function store(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'room_number' => 'required|string|unique:rooms',
            'room_name' => 'required|string|max:255',
            'room_type_id' => 'required|exists:room_types,id',
            'description' => 'nullable|string',
            'status' => 'required|in:available,occupied,reserved,maintenance',
            'image' => 'nullable|image|mimes:jpeg,png,jpg,gif|max:2048',
        ]);

        // Handle image upload
        if ($request->hasFile('image')) {
            $imagePath = $request->file('image')->store('rooms', 'public');
            $validated['image'] = $imagePath;
        }

        Room::create($validated);

        return redirect()->route('admin.rooms.index')->with('success', 'Room created successfully!');
    }

    /**
     * Show edit room form.
     */
    public function edit(Room $room): View
    {
        $roomTypes = RoomType::where('status', 'active')->orderBy('name')->get();

        return view('admin.rooms.edit', compact('room', 'roomTypes'));
    }

    /**
     * Update room information.
     */
    public function update(Request $request, Room $room): RedirectResponse
    {
        $validated = $request->validate([
            'room_number' => 'required|string|unique:rooms,room_number,' . $room->id,
            'room_name' => 'required|string|max:255',
            'room_type_id' => 'required|exists:room_types,id',
            'description' => 'nullable|string',
            'status' => 'required|in:available,occupied,reserved,maintenance',
            'image' => 'nullable|image|mimes:jpeg,png,jpg,gif|max:2048',
        ]);

        // Handle image upload
        if ($request->hasFile('image')) {
            $imagePath = $request->file('image')->store('rooms', 'public');
            $validated['image'] = $imagePath;
        }

        $room->update($validated);

        return redirect()->route('admin.rooms.index')->with('success', 'Room updated successfully!');
    }

    /**
     * Upload additional room images.
     */
    public function uploadImages(Request $request, Room $room): RedirectResponse
    {
        $validated = $request->validate([
            'images.*' => 'required|image|mimes:jpeg,png,jpg,gif|max:2048',
        ]);

        if ($request->hasFile('images')) {
            foreach ($request->file('images') as $image) {
                $imagePath = $image->store('room-images', 'public');
                RoomImage::create([
                    'room_id' => $room->id,
                    'image_path' => $imagePath,
                ]);
            }
        }

        return redirect()->route('admin.rooms.edit', $room)->with('success', 'Images uploaded successfully!');
    }

    /**
     * Delete room image.
     */
    public function deleteImage(RoomImage $roomImage): RedirectResponse
    {
        $roomImage->delete();

        return back()->with('success', 'Image deleted successfully!');
    }

    /**
     * Delete room.
     */
    public function destroy(Room $room): RedirectResponse
    {
        $room->delete();

        return redirect()->route('admin.rooms.index')->with('success', 'Room deleted successfully!');
    }
}
