<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Booking extends Model
{
    use HasFactory;

    protected $fillable = [
        'reservation_id',
        'booking_date',
        'booking_status',
    ];

    protected $casts = [
        'booking_date' => 'date',
    ];

    /**
     * Get the reservation associated with the booking.
     */
    public function reservation()
    {
        return $this->belongsTo(Reservation::class);
    }

    /**
     * Get the billing associated with the booking.
     */
    public function billing()
    {
        return $this->hasOne(Billing::class);
    }
}
