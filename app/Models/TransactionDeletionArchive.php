<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Financial audit trail for a guest-permanently-deleted Booking/Reservation
 * - see the transaction_deletion_archives migration's own docblock for why
 * this exists (payments.booking_id/reservation_id are ON DELETE RESTRICT,
 * and Payment has no guest_id of its own to stay traceable if merely
 * detached instead). Never exposed through any guest-facing endpoint -
 * administrators/staff only, if a lookup tool for this table is ever built.
 */
class TransactionDeletionArchive extends Model
{
    protected $fillable = [
        'guest_id',
        'original_reservation_id',
        'original_booking_id',
        'display_reference',
        'transaction_status',
        'total_amount',
        'snapshot',
        'guest_deleted_at',
    ];

    protected $casts = [
        'snapshot' => 'array',
        'guest_deleted_at' => 'datetime',
        'total_amount' => 'decimal:2',
    ];

    public function guest()
    {
        return $this->belongsTo(Guest::class);
    }
}
