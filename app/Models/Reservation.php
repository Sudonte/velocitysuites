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
        'adults',
        'children',
        'status',
        'payment_preference',
        'payment_method',
        'discount_requested',
        'id_document_path',
        'discount_verification_status',
        'rejection_reason',
    ];

    protected $casts = [
        'check_in' => 'datetime',
        'check_out' => 'datetime',
        'discount_requested' => 'boolean',
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
     * Get the room associated with the reservation. Deprecated going
     * forward - room assignment now happens at check-in, against the
     * Booking, not the Reservation. Kept for historical/pre-redesign
     * records; new code should not write to reservations.room_id.
     */
    public function room()
    {
        return $this->belongsTo(Room::class);
    }

    /**
     * Get the booking associated with the reservation. Only exists once
     * the reservation has been converted (status = converted).
     */
    public function booking()
    {
        return $this->hasOne(Booking::class);
    }

    /**
     * Get deposit payments made against this reservation before a Billing
     * exists (payment_stage = 'deposit', billing_id null until re-parented
     * at checkout).
     */
    public function payments()
    {
        return $this->hasMany(Payment::class);
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
