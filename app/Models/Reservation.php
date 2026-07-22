<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Reservation extends Model
{
    use HasFactory;

    protected $fillable = [
        'guest_id',
        'guest_first_name',
        'guest_last_name',
        'room_type_id',
        'room_id',
        'check_in',
        'check_out',
        'number_of_guests',
        'adults',
        'children',
        'status',
        'id_card_type',
        'id_card_image_path',
        'additional_guest_details',
    ];

    protected $casts = [
        'check_in' => 'datetime',
        'check_out' => 'datetime',
        'additional_guest_details' => 'array',
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

    /**
     * The name of the person actually staying, as provided at
     * reservation/booking time - may differ from the account holder's
     * name (e.g. booking made on a friend's account).
     */
    public function getStayGuestFullNameAttribute(): ?string
    {
        if (! $this->guest_first_name && ! $this->guest_last_name) {
            return null;
        }
        return trim("{$this->guest_first_name} {$this->guest_last_name}");
    }

    /**
     * Title-case the stay guest's first name, matching User's convention.
     */
    public function setGuestFirstNameAttribute(?string $value): void
    {
        $this->attributes['guest_first_name'] = $value ? ucwords(strtolower($value)) : $value;
    }

    /**
     * Title-case the stay guest's last name, matching User's convention.
     */
    public function setGuestLastNameAttribute(?string $value): void
    {
        $this->attributes['guest_last_name'] = $value ? ucwords(strtolower($value)) : $value;
    }
}
