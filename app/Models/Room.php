<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Room extends Model
{
    use HasFactory;

    protected $fillable = [
        'room_number',
        'room_name',
        'room_type_id',
        'room_capacity',
        'rate_override',
        'description',
        'status',
        'image',
    ];

    protected $casts = [
        'rate_override' => 'decimal:2',
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
     * Get the images for the room.
     */
    public function images()
    {
        return $this->hasMany(RoomImage::class);
    }

    /**
     * Get the reservations for the room.
     */
    public function reservations()
    {
        return $this->hasMany(Reservation::class);
    }
}
