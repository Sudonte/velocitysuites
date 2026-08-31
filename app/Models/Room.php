<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Storage;

class Room extends Model
{
    use HasFactory;

    protected $fillable = [
        'room_number',
        'room_name',
        'room_type_id',
        'room_capacity',
        'rate_override',
        'status',
    ];

    protected $casts = [
        'rate_override' => 'decimal:2',
    ];

    protected $appends = [
        'amenities',
    ];

    /**
     * Room type is always needed alongside a room (base rate/type name
     * live there), and the table is tiny - eager load it by default.
     */
    protected $with = ['roomType'];

    /**
     * Get the room's type (base rate and type name live there; the type's
     * capacity is only the default for newly added rooms).
     */
    public function roomType()
    {
        return $this->belongsTo(RoomType::class);
    }

    /**
     * This room's own gallery photos (4-5, per the System Administrator's
     * Rooms module), sort_order-ordered.
     */
    public function images()
    {
        return $this->hasMany(RoomImage::class)->orderBy('sort_order');
    }

    /**
     * This room's own photo gallery for display - not appended to $appends
     * since Room is eager-loaded broadly (protected $with = ['roomType'],
     * and Room shows up throughout booking/check-in/billing flows), so
     * auto-serializing a photo gallery into every one of those payloads
     * would be wasteful. Callers (Admin\RoomManagementController's Edit
     * page, Receptionist\ReceptionistController::roomDetails()) reference
     * $room->gallery directly - accessors resolve regardless of $appends.
     * Shape: [{'id','url'}, ...].
     */
    public function getGalleryAttribute(): array
    {
        return $this->images->map(function (RoomImage $image) {
            return [
                'id' => $image->id,
                'url' => Storage::disk('public')->url($image->image_path),
            ];
        })->values()->all();
    }

    /**
     * A room's description is always its Room Type's description - never
     * independently stored or editable per-room (System Administrator
     * requirement: editing the type's description must automatically
     * update every one of its rooms with no extra step). roomType is
     * always eager-loaded ($with above), so this is safe with no null check.
     */
    public function getDescriptionAttribute(): ?string
    {
        return $this->roomType->description;
    }

    /**
     * Effective nightly rate: the room's own override when set, otherwise
     * the type's base rate. All pricing/billing code reads room_rate, so
     * overrides flow into quotes and bills automatically.
     * (No accessor for room_type: it would collide with the roomType()
     * relationship in attribute resolution - type name reads use
     * $room->roomType->name. room_capacity is a real column now.)
     */
    public function getRoomRateAttribute()
    {
        return $this->rate_override ?? $this->roomType?->rate;
    }

    /**
     * Whether this room charges differently from its type's base rate.
     */
    public function getHasRateOverrideAttribute(): bool
    {
        return $this->rate_override !== null;
    }

    /**
     * Get the reservations for the room. Deprecated going forward -
     * reservations.room_id is no longer written to (room assignment now
     * happens at check-in, against a Booking, not a Reservation); kept for
     * historical/pre-redesign records.
     */
    public function reservations()
    {
        return $this->hasMany(Reservation::class);
    }

    /**
     * Get the bookings assigned to this physical room via the legacy
     * single-room column (set at check-in). Vestigial now that
     * assignedBookings() (the booking_rooms pivot) is the real multi-room
     * assignment list, but harmless to leave since room_id is kept in sync
     * as the first assigned room.
     */
    public function bookings()
    {
        return $this->hasMany(Booking::class);
    }

    /**
     * Bookings this room is assigned to via the booking_rooms pivot - the
     * real multi-room-aware assignment list, used by
     * RoomAvailabilityService to check whether a room is already claimed
     * by another overlapping checked-in booking.
     */
    public function assignedBookings()
    {
        return $this->belongsToMany(Booking::class, 'booking_rooms')->withTimestamps();
    }

    /**
     * Amenities are managed only at the Room Type level (System
     * Administrator requirement: an individual room can never carry its own
     * independent amenity assignment - it always reflects exactly what its
     * type has, live). A room's own direct room_amenity pivot was removed
     * (2026_08_22_130000_merge_room_amenity_into_room_type_amenity.php,
     * after backfilling any room-only assignments into the room type first
     * so nothing a room used to show silently disappeared) - this is now a
     * pure passthrough, not an independent computation, so editing the
     * type's amenities is immediately reflected on every one of its rooms
     * with no extra step and no room-level override is possible.
     */
    public function getAmenitiesAttribute(): array
    {
        return $this->roomType->amenities;
    }
}
