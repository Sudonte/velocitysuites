<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Booking extends Model
{
    use HasFactory;

    protected $fillable = [
        'reservation_id',
        'room_type_id',
        'room_id',
        'rooms_requested',
        'check_in',
        'check_out',
        'adults',
        'children',
        'number_of_guests',
        'confirmed_at',
        'booking_status',
    ];

    protected $casts = [
        'check_in' => 'datetime',
        'check_out' => 'datetime',
        'confirmed_at' => 'datetime',
    ];

    /**
     * Get the reservation associated with the booking.
     */
    public function reservation()
    {
        return $this->belongsTo(Reservation::class);
    }

    /**
     * Get the room type requested (copied from the reservation at
     * conversion time).
     */
    public function roomType()
    {
        return $this->belongsTo(RoomType::class);
    }

    /**
     * Get the physical room. Null until the receptionist assigns one at
     * check-in. For a multi-room booking this is just the first assigned
     * room (see rooms()) - kept for the many display-only call sites that
     * only need "the room" as a reasonable simplification.
     */
    public function room()
    {
        return $this->belongsTo(Room::class);
    }

    /**
     * All rooms assigned to this booking (set at check-in - may be more
     * than one when rooms_requested > 1). Empty until check-in.
     */
    public function rooms()
    {
        return $this->belongsToMany(Room::class, 'booking_rooms')->withTimestamps();
    }

    /**
     * Get the billing associated with the booking.
     */
    public function billing()
    {
        return $this->hasOne(Billing::class);
    }

    /**
     * Calculate the number of nights.
     */
    public function getNumberOfNightsAttribute()
    {
        return abs($this->check_out->diffInDays($this->check_in));
    }
}
