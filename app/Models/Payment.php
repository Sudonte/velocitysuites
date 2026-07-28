<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Payment extends Model
{
    use HasFactory;

    protected $fillable = [
        'billing_id',
        'reservation_id',
        'payment_method',
        'reference_number',
        'receipt_path',
        'amount_paid',
        'payment_status',
        'payment_stage',
        'verified_by',
        'verified_at',
        'payment_date',
    ];

    protected $casts = [
        'payment_date' => 'datetime',
        'verified_at' => 'datetime',
        'amount_paid' => 'decimal:2',
    ];

    /**
     * Get the billing associated with the payment. Null for a deposit
     * payment made at reservation time, before a Billing exists - it gets
     * re-parented onto one at checkout.
     */
    public function billing()
    {
        return $this->belongsTo(Billing::class);
    }

    /**
     * Get the reservation this deposit payment was made against. Only set
     * for payment_stage = 'deposit'.
     */
    public function reservation()
    {
        return $this->belongsTo(Reservation::class);
    }

    /**
     * Get the staff member who verified this payment (GCash receipt
     * verification, etc.).
     */
    public function verifier()
    {
        return $this->belongsTo(User::class, 'verified_by');
    }
}
