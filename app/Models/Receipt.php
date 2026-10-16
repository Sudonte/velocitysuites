<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** One permanent, frozen receipt (see the receipts migration). Written once, never updated. */
class Receipt extends Model
{
    public const BOOKING_VERIFICATION = 1;
    public const CHECKIN_PAYMENT = 2;
    public const CHECKOUT_FINAL = 3;

    protected $fillable = ['booking_id', 'receipt_type', 'receipt_no', 'issued_at', 'issued_by', 'total_amount', 'total_paid', 'remaining_balance', 'snapshot'];

    protected $casts = ['issued_at' => 'datetime', 'snapshot' => 'array', 'total_amount' => 'decimal:2', 'total_paid' => 'decimal:2', 'remaining_balance' => 'decimal:2'];

    /** Updating or deleting a stored receipt is a bug, not a feature. */
    protected static function booted(): void
    {
        static::updating(fn () => false);
        static::deleting(fn () => false);
    }

    public function booking()
    {
        return $this->belongsTo(Booking::class);
    }
}
