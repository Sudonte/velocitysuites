<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Storage;

class RoomType extends Model
{
    use HasFactory;

    protected $fillable = [
        'name',
        'rate',
        'capacity',
        'bed_type',
        'description',
        'image',
        'image_changed_at',
        'number_format',
        'status',
    ];

    protected $casts = [
        'rate' => 'decimal:2',
        'image_changed_at' => 'datetime',
    ];

    protected $appends = [
        'image_url',
        'amenities',
        'gallery',
    ];

    /**
     * Defensive default: if some future call site eager-loads `rooms` onto
     * a RoomType that then gets JSON-serialized to a guest-facing
     * response, individual room data (room_number etc.) shouldn't leak -
     * guests only ever see the grouped type. Blade views that need real
     * per-room listings (e.g. admin/room-types/show) pass rooms as their
     * own separate variable, not via this relation, so hiding it here
     * doesn't affect them.
     */
    protected $hidden = [
        'rooms',
    ];

    /**
     * Get the physical rooms of this type.
     */
    public function rooms()
    {
        return $this->hasMany(Room::class);
    }

    /**
     * Amenities assigned to this room type - the single source of truth for
     * every room of this type (System Administrator requirement: amenities
     * are managed only at the Room Type level, never per individual room -
     * see Room::getAmenitiesAttribute(), a pure passthrough to this list).
     * Managed from this type's own Edit/Rooms pages, within the Rooms
     * module.
     */
    public function assignedAmenities()
    {
        return $this->belongsToMany(Amenity::class, 'room_type_amenity')->withTimestamps();
    }

    /**
     * The room type's amenities for guest display - active-only. Shape:
     * ['name','category','description','pricing_type','fee']; empty array
     * drives "No Available Amenities" everywhere it's shown.
     */
    public function getAmenitiesAttribute(): array
    {
        return $this->assignedAmenities()
            ->where('status', 'active')
            ->get()
            ->map(function (Amenity $amenity) {
                return [
                    'name' => $amenity->amenity_name,
                    'category' => $amenity->category,
                    'description' => $amenity->description,
                    'pricing_type' => $amenity->isPaid() ? 'paid' : 'complimentary',
                    'fee' => $amenity->isPaid() ? (string) $amenity->charge : null,
                    'quantity' => $amenity->quantity,
                ];
            })->values()->all();
    }

    /**
     * The room type's main image - a real, authoritative field (see the
     * add_image_to_room_types_table migration), managed only via
     * Admin\RoomTypeManagementController's Edit/Create Type forms. Every
     * individual room of this type displays this same image throughout
     * the system (guest browsing, booking/reservation interfaces, the
     * Rooms module, mobile app, etc.). Falls back to the Velocity Suites
     * logo when no image has ever been uploaded or one was removed - this
     * is a universal branding default (unlike the per-room gallery, which
     * must never show the logo as if it were a real guest-facing photo),
     * so image_url is never null and every consumer can just render it.
     */
    public function getImageUrlAttribute(): string
    {
        return $this->image ? Storage::disk('public')->url($this->image) : asset('images/logo.jpg');
    }

    /**
     * This room type's photo gallery: every individual room of this type
     * owns its own 4-5 photo gallery (Room::images()); this pools all of
     * them together, each photo labeled with the room it came from, for
     * every audience that browses by type - guest web (Landing Page, Rooms
     * Page), the mobile app, and the System Administrator/Receptionist
     * Rooms module (Admin\RoomTypeManagementController::show(),
     * Receptionist\ReceptionistController::roomsShow()). Ordered by
     * room_number so photos naturally group by room. Only ever maps real
     * RoomImage rows - never synthesizes a logo/placeholder entry, so a
     * room with zero photos simply contributes nothing here (the gallery
     * component's own empty state handles that, never the logo). Shape:
     * [{'id','url','room_label'}, ...].
     */
    public function getGalleryAttribute(): array
    {
        return $this->rooms()->with('images')->orderBy('room_number')->get()
            ->flatMap(function (Room $room) {
                return $room->images->map(function (RoomImage $image) use ($room) {
                    return [
                        'id' => $image->id,
                        'url' => Storage::disk('public')->url($image->image_path),
                        'room_label' => 'Room ' . $room->room_number,
                    ];
                });
            })->values()->all();
    }

    /**
     * Once-per-24-hours cooldown on the type's main image - mirrors
     * User::canChangeProfilePicture(), just with a 1-day window instead
     * of 1-month. Only gates replacing/removing an image that already
     * exists; the very first upload (no image yet) is never gated.
     */
    public function canChangeImage(): bool
    {
        return $this->image_changed_at === null
            || $this->image_changed_at->addDay()->isPast();
    }

    /** Null once changeable again; otherwise the date the one-day cooldown lifts. */
    public function nextImageChangeDate(): ?\Carbon\Carbon
    {
        if ($this->canChangeImage()) {
            return null;
        }

        return $this->image_changed_at->copy()->addDay();
    }

    /**
     * Get the converted bookings for this type - Booking carries its own
     * room_type_id (set at reservation time), independent of whichever
     * physical room ends up assigned at check-in, so this is the correct
     * relation for "which room type gets booked the most" reporting.
     */
    public function bookings()
    {
        return $this->hasMany(Booking::class);
    }

    /**
     * Get the reservations requesting this type.
     */
    public function reservations()
    {
        return $this->hasMany(Reservation::class);
    }

    /**
     * Generate the next $count room numbers from this type's number_format.
     * The format's run of '#' is a zero-padded sequence counter, e.g.
     * "1##" -> 101, 102... "D-##" -> D-01, D-02...
     * Continues after the highest existing number matching the format and
     * skips any number already taken by another room (numbers are globally
     * unique). Returns fewer than $count only if the format's digit width
     * runs out of room (e.g. "1##" caps at 199).
     */
    public function nextRoomNumbers(int $count): array
    {
        $format = $this->number_format ?: '###';

        if (!preg_match('/^(.*?)(#+)(.*)$/', $format, $m)) {
            // No '#' placeholder: treat the whole format as a prefix with
            // a two-digit counter appended.
            [$prefix, $width, $suffix] = [$format, 2, ''];
        } else {
            [$prefix, $width, $suffix] = [$m[1], strlen($m[2]), $m[3]];
        }

        $pattern = '/^' . preg_quote($prefix, '/') . '(\d{' . $width . '})' . preg_quote($suffix, '/') . '$/';
        $start = 0;
        foreach (Room::pluck('room_number') as $existing) {
            if (preg_match($pattern, $existing, $hit)) {
                $start = max($start, (int) $hit[1]);
            }
        }

        $taken = Room::pluck('room_number')->flip();
        $numbers = [];
        $seq = $start;
        $max = (10 ** $width) - 1;

        while (count($numbers) < $count && $seq < $max) {
            $seq++;
            $candidate = $prefix . str_pad((string) $seq, $width, '0', STR_PAD_LEFT) . $suffix;
            if (!isset($taken[$candidate])) {
                $numbers[] = $candidate;
            }
        }

        return $numbers;
    }
}
