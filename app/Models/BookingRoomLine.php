<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * One room-type line item within a Booking's transaction (quantity/price/
 * subtotal for one distinct room type) - see Booking::roomLines()'s own doc
 * and MULTI_ROOM_TRANSACTION_BACKEND_SPEC.md. Plain read model; rows are
 * already written elsewhere in the booking-creation flow (this table has
 * real production data going back to 2026-09-15) - this class only adds the
 * missing read-side relation/model so the guest-facing API can finally
 * serialize them.
 */
class BookingRoomLine extends Model
{
    protected $table = 'booking_room_lines';

    protected $fillable = [
        'booking_id',
        'room_type_id',
        'room_type_name',
        'quantity',
        'price_per_night',
        'number_of_nights',
        'subtotal',
    ];

    protected $casts = [
        'price_per_night' => 'decimal:2',
        'subtotal' => 'decimal:2',
    ];

    public function booking()
    {
        return $this->belongsTo(Booking::class);
    }
}
