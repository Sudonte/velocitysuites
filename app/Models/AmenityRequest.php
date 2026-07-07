<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class AmenityRequest extends Model
{
    use HasFactory;

    protected $fillable = [
        'guest_id',
        'reservation_id',
        'amenity_id',
        'quantity',
        'charge',
        'status',
    ];

    protected $casts = [
        'charge' => 'decimal:2',
    ];

    /**
     * Get the guest associated with the amenity request.
     */
    public function guest()
    {
        return $this->belongsTo(Guest::class);
    }

    /**
     * Get the reservation this amenity request was made during.
     */
    public function reservation()
    {
        return $this->belongsTo(Reservation::class);
    }

    /**
     * Get the amenity associated with the request.
     */
    public function amenity()
    {
        return $this->belongsTo(Amenity::class);
    }
}
