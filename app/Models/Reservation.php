<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Reservation extends Model
{
    use HasFactory;

    protected $fillable = [
        'guest_id',
        'room_type_id',
        'room_id',
        'check_in',
        'check_out',
        'number_of_guests',
        'status',
    ];

    protected $casts = [
        'check_in' => 'datetime',
        'check_out' => 'datetime',
    ];

    /**
     * Get the guest associated with the reservation.
     */
    public function guest()
    {
        return $this->belongsTo(Guest::class);
    }

    /**
     * Get the room type the guest requested. Always set; the specific
     * room (room_id) stays null until a receptionist assigns one at
     * confirmation time.
     */
    public function roomType()
    {
        return $this->belongsTo(RoomType::class);
    }

    /**
     * Get the room associated with the reservation. Null while the
     * reservation is pending (no room assigned yet).
     */
    public function room()
    {
        return $this->belongsTo(Room::class);
    }

    /**
     * Get the booking associated with the reservation.
     */
    public function booking()
    {
        return $this->hasOne(Booking::class);
    }

    /**
     * Get the amenity requests made during this reservation's stay.
     */
    public function amenityRequests()
    {
        return $this->hasMany(AmenityRequest::class);
    }

    /**
     * Calculate the number of nights.
     */
    public function getNumberOfNightsAttribute()
    {
        return abs($this->check_out->diffInDays($this->check_in));
    }
}
