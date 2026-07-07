<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Billing extends Model
{
    use HasFactory;

    protected $fillable = [
        'booking_id',
        'room_charge',
        'additional_guest_fee',
        'amenity_charge',
        'discount',
        'total_amount',
        'billing_status',
    ];

    protected $casts = [
        'room_charge' => 'decimal:2',
        'additional_guest_fee' => 'decimal:2',
        'amenity_charge' => 'decimal:2',
        'discount' => 'decimal:2',
        'total_amount' => 'decimal:2',
    ];

    /**
     * Get the booking associated with the billing.
     */
    public function booking()
    {
        return $this->belongsTo(Booking::class);
    }

    /**
     * Get the payments associated with the billing.
     */
    public function payments()
    {
        return $this->hasMany(Payment::class);
    }

    /**
     * Get the additional charges associated with the billing.
     */
    public function additionalCharges()
    {
        return $this->hasMany(AdditionalCharge::class);
    }

    /**
     * Calculate the total additional charges.
     */
    public function getAdditionalChargesTotalAttribute(): float
    {
        return (float) $this->additionalCharges()->sum('amount');
    }

    /**
     * Calculate the running total (room charge + additional charges - discounts).
     */
    public function getRunningTotalAttribute(): float
    {
        $baseTotal = (float) $this->room_charge
            + (float) $this->additional_guest_fee
            + (float) $this->amenity_charge
            + $this->additional_charges_total;

        return max(0, $baseTotal - (float) $this->discount);
    }

    /**
     * Calculate the balance due.
     */
    public function getBalanceAttribute(): float
    {
        $paid = (float) $this->payments()
            ->where('payment_status', 'completed')
            ->sum('amount_paid');

        return max(0, $this->running_total - $paid);
    }

    /**
     * Recalculate and update the total amount.
     */
    public function recalculateTotal(): void
    {
        $baseTotal = (float) $this->room_charge
            + (float) $this->additional_guest_fee
            + (float) $this->amenity_charge
            + $this->additional_charges_total;

        $this->total_amount = max(0, $baseTotal - (float) $this->discount);
        $this->save();
    }
}
