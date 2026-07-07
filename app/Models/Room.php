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
        'description',
        'status',
        'image',
    ];

    /**
     * Room type is always needed alongside a room (rate/capacity/name
     * live there), and the table is tiny - eager load it by default.
     */
    protected $with = ['roomType'];

    /**
     * Get the room's type (rate, capacity, and type name live there).
     */
    public function roomType()
    {
        return $this->belongsTo(RoomType::class);
    }

    /**
     * A room's effective rate is its type's rate. Kept as an accessor so
     * pricing/billing code reads the same as before the columns moved to
     * room_types. (No equivalent accessor for room_type: it would collide
     * with the roomType() relationship in attribute resolution - type name
     * reads use $room->roomType->name.)
     */
    public function getRoomRateAttribute()
    {
        return $this->roomType?->rate;
    }

    public function getRoomCapacityAttribute()
    {
        return $this->roomType?->capacity;
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
