<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * One room-type line item within a Reservation's transaction (quantity/
 * price/subtotal for one distinct room type) - see Reservation::roomLines()'s
 * own doc and MULTI_ROOM_TRANSACTION_BACKEND_SPEC.md. Plain read model; rows
 * are already written elsewhere in the reservation-creation flow (this table
 * has real production data going back to 2026-09-15) - this class only adds
 * the missing read-side relation/model so the guest-facing API can finally
 * serialize them.
 */
class ReservationRoomLine extends Model
{
    protected $table = 'reservation_room_lines';

    protected $fillable = [
        'reservation_id',
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

    public function reservation()
    {
        return $this->belongsTo(Reservation::class);
    }
}
