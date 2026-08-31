<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * A historical snapshot of one Paid/Additional amenity a guest selected
 * during booking - amenity_name/charge are captured at creation time and
 * never updated afterward, so a later admin price/name change never
 * rewrites what this reservation actually shows or was charged. This is
 * also the sole source of truth for AmenityRequest eligibility: a guest
 * may only submit a post-booking request for an amenity_id that already
 * has a row here on the same reservation (see Api\AmenityRequestController).
 */
class ReservationAmenity extends Model
{
    protected $fillable = [
        'reservation_id',
        'amenity_id',
        'amenity_name',
        'category',
        'charge',
        'quantity',
        'subtotal',
    ];

    protected $casts = [
        'charge' => 'decimal:2',
        'subtotal' => 'decimal:2',
    ];

    public function reservation()
    {
        return $this->belongsTo(Reservation::class);
    }

    /**
     * The live catalog amenity, for reference only - never used to derive
     * price/name for display here, since amenity_name/charge above are
     * the frozen-at-booking-time values.
     */
    public function amenity()
    {
        return $this->belongsTo(Amenity::class);
    }
}
