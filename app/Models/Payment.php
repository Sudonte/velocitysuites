<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Payment extends Model
{
    use HasFactory;

    protected $fillable = [
        'billing_id',
        'payment_method',
        'reference_number',
        'amount_paid',
        'payment_status',
        'payment_date',
    ];

    protected $casts = [
        'payment_date' => 'datetime',
        'amount_paid' => 'decimal:2',
    ];

    /**
     * Get the billing associated with the payment.
     */
    public function billing()
    {
        return $this->belongsTo(Billing::class);
    }
}
