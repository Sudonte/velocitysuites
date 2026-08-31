<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Amenity;
use App\Models\Room;
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
        $query = RoomType::withCount([
            'rooms',
            'rooms as available_rooms_count' => fn ($q) => $q->where('status', 'available'),
        ]);

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
     * management workspace: add rooms in bulk, edit, see status, view this
     * type's assigned amenities read-only - actual amenity assignment
     * happens only on the Edit Type form). Accepts the same optional
     * search/status filter as index(), scoped to this type's own rooms.
     */
    public function show(Request $request, RoomType $roomType): View
    {
        $query = $roomType->rooms()->with('images');

        if ($request->filled('search')) {
            $query->where(function ($q) use ($request) {
                $q->where('room_number', 'like', '%' . $request->search . '%')
                    ->orWhere('room_name', 'like', '%' . $request->search . '%');
            });
        }

        if ($request->filled('status')) {
            $query->where('status', $request->status);
        }

        $rooms = $query->orderBy('room_number')->paginate(20)->withQueryString();

        // Preview of the next numbers the bulk-add would generate.
        $nextNumbers = $roomType->nextRoomNumbers(3);

        // Every photo from every room of this type, labeled by room - see
        // RoomType::getGalleryAttribute().
        $mergedGallery = collect($roomType->gallery);

        return view('admin.room-types.show', compact('roomType', 'rooms', 'nextNumbers', 'mergedGallery'));
    }

    /**
     * Show create room type form.
     */
    public function create(): View
    {
        $existingFormats = RoomType::whereNotNull('number_format')->distinct()->pluck('number_format');
        // Only Free/Included amenities are ever assignable to a Room Type -
        // Paid/Additional amenities belong exclusively to the guest booking
        // add-on catalog, never to a room's standard amenity list.
        $amenities = Amenity::where('status', 'active')->where('charge', 0)
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
            'capacity' => 'required|integer|min:1',
            'bed_type' => 'nullable|string|max:255',
            'description' => 'nullable|string|max:2000',
            'number_format' => ['required', 'string', 'max:20', 'regex:/^[A-Za-z0-9\-]*#+[A-Za-z0-9\-]*$/'],
            'status' => 'required|in:active,inactive',
            'amenities' => 'nullable|array',
            'amenities.*' => ['exists:amenities,id', $this->freeAmenityRule()],
        ], [
            'number_format.regex' => 'The numbering format must contain a run of # placeholders (e.g. 1## for 101, 102... or D-## for D-01, D-02...).',
        ]);

        $validated['image'] = $this->storeImage($request);
        $amenityIds = $validated['amenities'] ?? [];
        unset($validated['amenities']);

        $roomType = RoomType::create($validated);
        $roomType->assignedAmenities()->sync($amenityIds);

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
     * Builds the amenity picker list for an assignment form: every active,
     * Free/Included amenity (selectable for new assignment - Paid/
     * Additional amenities never appear here, they belong only to the
     * guest booking add-on catalog) plus any amenity already assigned via
     * $currentAssignment even if it's since been deactivated or is paid
     * (still shown, checked, and visually marked - a later Amenities
     * module change must not silently hide it from a type it's already
     * on). Returns [$pickerList, $selectedIds].
     */
    private function amenityPickerData($currentAssignment): array
    {
        $selectedIds = $currentAssignment->pluck('amenities.id')->all();

        $amenities = Amenity::where(function ($q) {
                $q->where('status', 'active')->where('charge', 0);
            })
            ->orWhereIn('id', $selectedIds)
            ->orderBy('category')
            ->orderBy('amenity_name')
            ->get();

        return [$amenities, $selectedIds];
    }

    /**
     * Rejects any submitted amenity id that isn't a currently active,
     * Free/Included amenity - real backend enforcement (not just hiding
     * paid amenities from the picker's UI) of "only free amenities are
     * assignable to a Room Type."
     */
    private function freeAmenityRule(): \Closure
    {
        return function (string $attribute, mixed $value, \Closure $fail): void {
            $amenity = Amenity::find($value);
            if ($amenity && $amenity->isPaid()) {
                $fail("\"{$amenity->amenity_name}\" is a Paid/Additional amenity and cannot be assigned to a Room Type - only Free/Included amenities are assignable here.");
            }
        };
    }

    /**
     * Bulk-add rooms to this type. Numbers are generated from the type's
     * numbering format. Room Name, Capacity, and Description are inherited
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
                'room_capacity' => $roomType->capacity,
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

        [$amenities, $selectedAmenityIds] = $this->amenityPickerData($roomType->assignedAmenities());

        return view('admin.room-types.edit', compact('roomType', 'existingFormats', 'amenities', 'selectedAmenityIds'));
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
            'bed_type' => 'nullable|string|max:255',
            'description' => 'nullable|string|max:2000',
            'number_format' => ['required', 'string', 'max:20', 'regex:/^[A-Za-z0-9\-]*#+[A-Za-z0-9\-]*$/'],
            'status' => 'required|in:active,inactive',
            'amenities' => 'nullable|array',
            'amenities.*' => ['exists:amenities,id', $this->freeAmenityRule()],
        ], [
            'number_format.regex' => 'The numbering format must contain a run of # placeholders (e.g. 1## for 101, 102... or D-## for D-01, D-02...).',
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

        $amenityIds = $validated['amenities'] ?? [];
        unset($validated['amenities']);

        $roomType->update($validated);
        $roomType->assignedAmenities()->sync($amenityIds);

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
