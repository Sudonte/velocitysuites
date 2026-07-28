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
     * check-in.
     */
    public function room()
    {
        return $this->belongsTo(Room::class);
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
