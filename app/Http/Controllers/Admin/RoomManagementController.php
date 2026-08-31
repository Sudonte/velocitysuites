<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Room;
use App\Models\RoomImage;
use App\Models\RoomType;
use App\Services\NotificationService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\View\View;

class RoomManagementController extends Controller
{
    /** A room's gallery can never have fewer than this many images - delete is blocked below this, replace/upload are not. */
    private const GALLERY_MIN = 4;

    /** A room's gallery can never exceed this many images. */
    private const GALLERY_MAX = 5;

    /**
     * Rooms are now managed per-type: the entry point is the room type
     * card grid, and each type's page lists its individual rooms.
     */
    public function index(Request $request): RedirectResponse
    {
        return redirect()->route('admin.room-types.index');
    }

    /**
     * Rooms are only ever added in bulk under a room type now (see
     * RoomTypeManagementController::storeRooms(), the "Add Room" modal on
     * room-types.show), so Room Name/Capacity/Rate Override/Description
     * are always inherited and never free-typed. This standalone
     * create/store pair predates that redesign and let an admin manually
     * type those inherited fields with no lock at all - redirect instead
     * of rendering it, the same way index() already does.
     */
    public function create(): RedirectResponse
    {
        return redirect()->route('admin.room-types.index');
    }

    /**
     * See create() above - same rationale, kept as a no-op in case this
     * URL is still bookmarked/POSTed to directly.
     */
    public function store(Request $request): RedirectResponse
    {
        return redirect()->route('admin.room-types.index');
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
            'room_capacity' => 'required|integer|min:1',
            'rate_override' => 'nullable|numeric|min:0',
            // No "reserved" option - RoomAvailabilityService only ever treats
            // "maintenance" as a hard block on booking/check-in assignment,
            // so a room manually set to "reserved" here would look blocked
            // but remain fully bookable - a trap for whoever picks it.
            'status' => 'required|in:available,occupied,maintenance',
        ]);

        $wasMaintenance = $room->status === 'maintenance';
        $room->update($validated);

        // reference_id on notifications is a foreign key to reservations,
        // not rooms - the room number/name goes in the message text instead.
        if (! $wasMaintenance && $room->status === 'maintenance') {
            app(NotificationService::class)->notifyAdmin(
                'Room Under Maintenance',
                "Room {$room->room_number} ({$room->room_name}) was marked under maintenance.",
                'room'
            );
        }

        return redirect()->route('admin.rooms.edit', $room)->with('success', 'Room updated successfully!');
    }

    /**
     * Upload additional gallery images for this room. Enforced server-side
     * (not just in the view) so the 5-image maximum can't be bypassed by
     * posting directly. No cooldown - only the Room Type's main image has
     * the 24h rule; individual room gallery photos can be managed freely.
     */
    public function uploadImages(Request $request, Room $room): RedirectResponse
    {
        if (! $request->hasFile('images')) {
            return back()->with('error', 'Please choose at least one image to upload.');
        }

        $request->validate([
            'images.*' => 'required|image|mimes:jpeg,png,jpg|max:5120',
        ]);

        $incoming = count($request->file('images'));
        $existing = $room->images()->count();

        if ($existing + $incoming > self::GALLERY_MAX) {
            return back()->with('error',
                'This room can have at most ' . self::GALLERY_MAX . ' gallery images. It currently has ' . $existing .
                " - uploading {$incoming} more would exceed the limit.");
        }

        $nextSortOrder = ($room->images()->max('sort_order') ?? -1) + 1;
        foreach ($request->file('images') as $image) {
            RoomImage::create([
                'room_id' => $room->id,
                'image_path' => $image->store('room-images', 'public'),
                'sort_order' => $nextSortOrder++,
            ]);
        }

        return redirect()->route('admin.rooms.edit', $room)->with('success', 'Gallery images uploaded successfully!');
    }

    /**
     * Replace a single gallery image in place - same row (id/sort_order
     * unchanged), so its position in the gallery order is preserved
     * exactly. No cooldown.
     */
    public function replaceImage(Request $request, RoomImage $roomImage): RedirectResponse
    {
        $request->validate([
            'image' => 'required|image|mimes:jpeg,png,jpg|max:5120',
        ]);

        $oldPath = $roomImage->image_path;
        $roomImage->update([
            'image_path' => $request->file('image')->store('room-images', 'public'),
        ]);
        Storage::disk('public')->delete($oldPath);

        return back()->with('success', 'Gallery image replaced successfully!');
    }

    /**
     * Delete a gallery image. Blocked once the room would drop below the
     * required 4-image minimum - upload a replacement or a new photo
     * first. Replacing an image (fixed count) is never blocked this way.
     */
    public function deleteImage(RoomImage $roomImage): RedirectResponse
    {
        $room = $roomImage->room;

        if ($room->images()->count() <= self::GALLERY_MIN) {
            return back()->with('error',
                'This room must keep at least ' . self::GALLERY_MIN . ' gallery images. ' .
                'Upload a new photo before deleting this one, or use Replace instead.');
        }

        Storage::disk('public')->delete($roomImage->image_path);
        $roomImage->delete();

        return back()->with('success', 'Gallery image deleted successfully!');
    }

    /**
     * Deactivate a room (status -> maintenance) instead of deleting it -
     * bookings.room_id has no cascade/nullOnDelete, so a real delete would
     * either be blocked by a raw FK error (if the room has any booking) or
     * silently cascade-delete old reservations pointed at it directly
     * (the pre-redesign reservations.room_id, which does cascade). Setting
     * it to maintenance removes it from availability without touching any
     * historical record.
     */
    public function deactivate(Room $room): RedirectResponse
    {
        $room->update(['status' => 'maintenance']);

        return redirect()->route('admin.rooms.index')->with('success', 'Room deactivated (set to maintenance).');
    }

    /**
     * Reactivate a deactivated room back to available.
     */
    public function reactivate(Room $room): RedirectResponse
    {
        $room->update(['status' => 'available']);

        return redirect()->route('admin.rooms.index')->with('success', 'Room reactivated.');
    }
}
