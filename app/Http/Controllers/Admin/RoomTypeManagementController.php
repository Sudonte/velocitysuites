<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Support\Activity;
use App\Models\Amenity;
use App\Models\Booking;
use App\Models\Room;
use App\Models\RoomImage;
use App\Models\RoomType;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\View\View;

class RoomTypeManagementController extends Controller
{
    /**
     * Display list of room types. Amenities themselves are created/edited
     * in the pre-existing Amenities module (Admin\AmenityManagementController,
     * admin.amenities.*) - this page only assigns amenities from that
     * catalog to room types/rooms, it doesn't manage the catalog itself.
     */
    public function index(Request $request): View
    {
        // Derived from actual CHECKED_IN occupancy, not the raw `status`
        // column (which can drift - see Room::getEffectiveStatusAttribute()'s
        // docblock) - matches DashboardStatsService/AdminReportController's
        // own available-room counts instead of disagreeing with them.
        $query = RoomType::withCount([
            'rooms' => fn ($q) => $q->notArchived(),
            'rooms as available_rooms_count' => fn ($q) => $q->notArchived()->where('status', '!=', 'maintenance')
                ->whereDoesntHave('assignedBookings', fn ($qq) => $qq->where('booking_status', Booking::STATUS_CHECKED_IN)->whereNull('booking_rooms.checked_out_at')),
        ]);

        if ($request->has('search') && $request->search) {
            $query->where('name', 'like', '%' . $request->search . '%');
        }

        if ($request->has('status') && $request->status) {
            $query->where('status', $request->status);
        }

        $roomTypes = $query->latest()->paginate(\App\Support\PerPage::resolve($request))->withQueryString();

        return view('admin.room-types.index', compact('roomTypes'));
    }

    /**
     * Show one room type with all its rooms (the per-type room
     * management workspace: add rooms in bulk, edit, see status, view this
     * type's assigned amenities read-only - actual amenity assignment
     * happens only on the Edit Type form). Accepts the same optional
     * search/status filter as index(), scoped to this type's own rooms.
     */
    public function show(Request $request, RoomType $roomType): View
    {
        $query = $roomType->rooms();

        if ($request->filled('search')) {
            $query->where(function ($q) use ($request) {
                $q->where('room_number', 'like', '%' . $request->search . '%')
                    ->orWhere('room_name', 'like', '%' . $request->search . '%');
            });
        }

        if ($request->get('status') === 'archived') {
            $query->whereNotNull('archived_at');
        } else {
            $query->notArchived();
            if ($request->filled('status')) {
                $query->where('status', $request->status);
            }
        }

        $rooms = $query->latest()->paginate(\App\Support\PerPage::resolve($request))->withQueryString();
        $archivedCount = $roomType->rooms()->whereNotNull('archived_at')->count();

        // Preview of the next numbers the bulk-add would generate.
        $nextNumbers = $roomType->nextRoomNumbers(3);

        // Every photo from every room of this type, labeled by room - see
        // RoomType::getGalleryAttribute().
        $mergedGallery = collect($roomType->gallery);

        return view('admin.room-types.show', compact('roomType', 'rooms', 'nextNumbers', 'mergedGallery', 'archivedCount'));
    }

    /**
     * Show create room type form.
     */
    public function create(): View
    {
        $existingFormats = RoomType::whereNotNull('number_format')->distinct()->pluck('number_format');
        // Only Paid/Additional amenities are chosen per Room Type - every active
        // Free/Included amenity comes with every room automatically (see
        // RoomType::getAmenitiesAttribute()), so there is nothing to assign.
        $amenities = Amenity::where('status', 'active')->where('charge', '>', 0)
            ->orderBy('category')->orderBy('amenity_name')->get();

        return view('admin.room-types.create', compact('existingFormats', 'amenities'));
    }

    /**
     * Store a new room type.
     */
    public function store(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'name' => 'required|string|max:255|unique:room_types,name',
            'rate' => 'required|numeric|min:0',
            'capacity' => 'required|integer|min:1|max:50',
            'min_capacity' => 'required|integer|min:1|lte:capacity',
            'bed_type' => 'nullable|string|max:255',
            'description' => 'nullable|string',
            'number_format' => ['required', 'string', 'max:20', 'regex:/^[A-Za-z0-9\-]*#+[A-Za-z0-9\-]*$/'],
            'status' => 'required|in:active,inactive',
            'amenities' => 'nullable|array',
            'amenities.*' => ['exists:amenities,id', $this->paidAmenityRule()],
            // how many of each included paid amenity a room gets free
            'amenity_qty' => 'nullable|array',
            'amenity_qty.*' => 'nullable|integer|min:1|max:20',
        ], [
            'number_format.regex' => 'The numbering format must contain a run of # placeholders (e.g. 1## for 101, 102... or D-## for D-01, D-02...).',
            'min_capacity.lte' => 'Minimum guests cannot be more than the maximum guests.',
        ]);

        $validated['image'] = $this->storeImage($request);
        $amenityIds = $this->amenitySync($validated);
        unset($validated['amenities'], $validated['amenity_qty']);

        $roomType = RoomType::create($validated);
        $roomType->assignedAmenities()->sync($amenityIds);

        Activity::log('Created room type', "{$roomType->name} ({$roomType->capacity_label}, ₱" . number_format($roomType->rate, 2) . '/night)', $roomType);

        return redirect()->route('admin.room-types.show', $roomType)->with('success', 'Room type created! You can now add its rooms below.');
    }

    /**
     * Stores the uploaded room type main image (same validation convention
     * as Admin\PromotionManagementController::storeImage()) and returns
     * the stored path, or null if none was uploaded this request. This is
     * the single image every individual room of this type displays
     * throughout the system - there is no per-room "main image" upload
     * anymore.
     */
    private function storeImage(Request $request): ?string
    {
        if (! $request->hasFile('image')) {
            return null;
        }

        $request->validate([
            'image' => 'image|mimes:jpeg,png,jpg|max:5120',
        ]);

        return $request->file('image')->store('room-type-images', 'public');
    }

    /**
     * Builds the amenity picker list for an assignment form: every active
     * Paid/Additional amenity (Free/Included ones are on every room already
     * and never appear here) plus any paid amenity already assigned via
     * $currentAssignment even if it's since been deactivated (still shown,
     * checked, and marked Inactive). Returns [$pickerList, $selectedIds, $includedQty (id => quantity)].
     */
    private function amenityPickerData($currentAssignment): array
    {
        $assigned = $currentAssignment->where('charge', '>', 0)->get();
        $selectedIds = $assigned->pluck('id')->all();
        $includedQty = $assigned->mapWithKeys(fn (Amenity $a) => [$a->id => RoomType::includedQuantity($a)])->all();

        $amenities = Amenity::where('charge', '>', 0)
            ->where(fn ($q) => $q->where('status', 'active')->orWhereIn('id', $selectedIds))
            ->orderBy('category')
            ->orderBy('amenity_name')
            ->get();

        return [$amenities, $selectedIds, $includedQty];
    }

    /** [amenity id => ['quantity' => n]] for the pivot: each chosen paid amenity with how many a room gets free (default 1). */
    private function amenitySync(array $validated): array
    {
        $qty = $validated['amenity_qty'] ?? [];

        return collect($validated['amenities'] ?? [])
            ->mapWithKeys(fn ($id) => [(int) $id => ['quantity' => max(1, (int) ($qty[$id] ?? 1))]])
            ->all();
    }

    /**
     * Rejects a Free/Included amenity - those are on every room already, so only
     * Paid/Additional amenities are chosen per Room Type (backend enforcement, not
     * just the picker hiding them).
     */
    private function paidAmenityRule(): \Closure
    {
        return function (string $attribute, mixed $value, \Closure $fail): void {
            $amenity = Amenity::find($value);
            if ($amenity && ! $amenity->isPaid()) {
                $fail("\"{$amenity->amenity_name}\" is free and already included with every room - only paid amenities are chosen per room type.");
            }
        };
    }

    /**
     * Bulk-add rooms to this type. Numbers are generated from the type's
     * numbering format. Room Name and Description are inherited
     * directly from the room type (never taken from the request, even if
     * present - the Add Rooms form only shows them as read-only preview
     * text) so a room can never end up with values other than its type's;
     * rate_override is intentionally left null (uses the type's base rate)
     * rather than duplicating the type's rate onto every room, which would
     * incorrectly flip Room::has_rate_override to true. The admin only
     * ever controls quantity and initial status.
     */
    public function storeRooms(Request $request, RoomType $roomType): RedirectResponse
    {
        $validated = $request->validate([
            'quantity' => 'required|integer|min:1|max:50',
            'status' => 'required|in:available,maintenance',
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
                'room_name' => $roomType->name . ' Room',
                'room_type_id' => $roomType->id,
                'status' => $validated['status'],
            ]);
        }

        Activity::log('Added rooms', "{$roomType->name}: " . implode(', ', $numbers), $roomType);

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

        [$amenities, $selectedAmenityIds, $includedQty] = $this->amenityPickerData($roomType->assignedAmenities());

        return view('admin.room-types.edit', compact('roomType', 'existingFormats', 'amenities', 'selectedAmenityIds', 'includedQty'));
    }

    /**
     * Update room type information.
     */
    public function update(Request $request, RoomType $roomType): RedirectResponse
    {
        $validated = $request->validate([
            'name' => 'required|string|max:255|unique:room_types,name,' . $roomType->id,
            'rate' => 'required|numeric|min:0',
            'capacity' => 'required|integer|min:1|max:50',
            'min_capacity' => 'required|integer|min:1|lte:capacity',
            'bed_type' => 'nullable|string|max:255',
            'description' => 'nullable|string',
            'number_format' => ['required', 'string', 'max:20', 'regex:/^[A-Za-z0-9\-]*#+[A-Za-z0-9\-]*$/'],
            'status' => 'required|in:active,inactive',
            'amenities' => 'nullable|array',
            'amenities.*' => ['exists:amenities,id', $this->paidAmenityRule()],
            // how many of each included paid amenity a room gets free
            'amenity_qty' => 'nullable|array',
            'amenity_qty.*' => 'nullable|integer|min:1|max:20',
        ], [
            'number_format.regex' => 'The numbering format must contain a run of # placeholders (e.g. 1## for 101, 102... or D-## for D-01, D-02...).',
            'min_capacity.lte' => 'Minimum guests cannot be more than the maximum guests.',
        ]);

        if ($request->hasFile('image')) {
            if ($roomType->image && ! $roomType->canChangeImage()) {
                return back()->withErrors([
                    'image' => 'This room type\'s main image was changed recently. It can be changed again on '
                        . $roomType->nextImageChangeDate()->format('M d, Y g:i A') . '.',
                ])->withInput();
            }

            $newImage = $this->storeImage($request);
            if ($roomType->image) {
                Storage::disk('public')->delete($roomType->image);
            }
            $validated['image'] = $newImage;
            $validated['image_changed_at'] = now();
        }

        $amenityIds = $this->amenitySync($validated);
        unset($validated['amenities'], $validated['amenity_qty']);

        $roomType->update($validated);
        $roomType->assignedAmenities()->sync($amenityIds);
        if ($roomType->wasChanged('capacity')) {
            // Keep the legacy per-room mirror (API only) in step with the type.
            Room::where('room_type_id', $roomType->id)->update(['room_capacity' => $roomType->capacity]);
        }

        Activity::log('Updated room type', "{$roomType->name} ({$roomType->capacity_label}, ₱" . number_format($roomType->rate, 2) . '/night, ' . $roomType->status . ')', $roomType);

        return redirect()->route('admin.room-types.index')->with('success', 'Room type updated successfully!');
    }

    /**
     * Remove this room type's main profile image - deletes the stored
     * file and clears the column, so the type falls back to the standard
     * "no image" placeholder everywhere it's displayed until a new one is
     * uploaded. Mirrors the admin/guest profile-picture removal pattern
     * already used elsewhere in this app.
     */
    public function removeImage(RoomType $roomType): RedirectResponse
    {
        if (! $roomType->image) {
            return back()->with('error', 'This room type has no main image to remove.');
        }

        if (! $roomType->canChangeImage()) {
            return back()->with('error',
                'This room type\'s main image was changed recently. It can be changed again on '
                    . $roomType->nextImageChangeDate()->format('M d, Y g:i A') . '.');
        }

        Storage::disk('public')->delete($roomType->image);
        $roomType->update(['image' => null, 'image_changed_at' => now()]);

        return back()->with('success', 'Room type image removed - now showing the default Velocity Suites logo.');
    }

    /** A room type's gallery: delete is blocked below the minimum (replace/upload are not), upload above the maximum. */
    private const GALLERY_MIN = 4;

    private const GALLERY_MAX = 5;

    /**
     * Add photos to the room type's gallery (shown for every room of the type).
     * The 5-photo maximum is enforced here, not just in the view. No cooldown -
     * only the main image has the 24-hour rule.
     */
    public function uploadImages(Request $request, RoomType $roomType): RedirectResponse
    {
        if (! $request->hasFile('images')) {
            return back()->with('error', 'Please choose at least one image to upload.');
        }

        $request->validate([
            'images' => 'required|array',
            'images.*' => 'required|image|mimes:jpeg,png,jpg|max:5120',
        ]);

        $incoming = count($request->file('images'));
        $existing = $roomType->images()->count();
        if ($existing + $incoming > self::GALLERY_MAX) {
            return back()->with('error',
                'A room type can have at most ' . self::GALLERY_MAX . ' gallery images. It currently has ' . $existing .
                " - uploading {$incoming} more would exceed the limit.");
        }

        $nextSortOrder = ($roomType->images()->max('sort_order') ?? -1) + 1;
        foreach ($request->file('images') as $image) {
            RoomImage::create([
                'room_type_id' => $roomType->id,
                'image_path' => $image->store('room-images', 'public'),
                'sort_order' => $nextSortOrder++,
            ]);
        }
        Activity::log('Updated room type gallery', "{$roomType->name}: added {$incoming} photo(s)", $roomType);

        return redirect()->route('admin.room-types.edit', $roomType)->with('success', 'Gallery images uploaded successfully!');
    }

    /** Replace one gallery photo in place - same row, so its position in the gallery is kept. */
    public function replaceImage(Request $request, RoomImage $roomImage): RedirectResponse
    {
        $roomType = $roomImage->roomType;
        abort_unless($roomType, 404);

        $request->validate([
            'image' => 'required|image|mimes:jpeg,png,jpg|max:5120',
        ]);

        $oldPath = $roomImage->image_path;
        $roomImage->update(['image_path' => $request->file('image')->store('room-images', 'public')]);
        Storage::disk('public')->delete($oldPath);
        Activity::log('Updated room type gallery', "{$roomType->name}: replaced a photo", $roomType);

        return back()->with('success', 'Gallery image replaced successfully!');
    }

    /** Delete a gallery photo - blocked once the type would drop below the 4-photo minimum. */
    public function deleteImage(RoomImage $roomImage): RedirectResponse
    {
        $roomType = $roomImage->roomType;
        abort_unless($roomType, 404);

        if ($roomType->images()->count() <= self::GALLERY_MIN) {
            return back()->with('error',
                'A room type must keep at least ' . self::GALLERY_MIN . ' gallery images. ' .
                'Upload a new photo before deleting this one, or use Replace instead.');
        }

        Storage::disk('public')->delete($roomImage->image_path);
        $roomImage->delete();
        Activity::log('Updated room type gallery', "{$roomType->name}: deleted a photo", $roomType);

        return back()->with('success', 'Gallery image deleted successfully!');
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

        Activity::log('Deactivated room type', $roomType->name, $roomType);

        return redirect()->route('admin.room-types.index')->with('success', 'Room type deactivated.');
    }

    /**
     * Reactivate a deactivated room type.
     */
    public function reactivate(RoomType $roomType): RedirectResponse
    {
        $roomType->update(['status' => 'active']);

        Activity::log('Reactivated room type', $roomType->name, $roomType);

        return redirect()->route('admin.room-types.index')->with('success', 'Room type reactivated.');
    }
}
