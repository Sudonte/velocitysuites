<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** Several stays booked together: each stay is its own booking or reservation (see the booking_groups migration; a group is only a link, never a bill). */
class BookingGroup extends Model
{
    protected $fillable = ['guest_id'];

    public function bookings()
    {
        return $this->hasMany(Booking::class, 'group_id')->orderBy('check_in')->orderBy('id');
    }

    public function reservations()
    {
        return $this->hasMany(Reservation::class, 'group_id')->orderBy('check_in')->orderBy('id');
    }
}
