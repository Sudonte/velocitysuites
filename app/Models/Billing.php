<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Billing extends Model
{
    use HasFactory;

    /**
     * Post-conversion, the mobile app's ApiMapper reads a converted
     * transaction's itemized room lines from here (dto.booking.billing.room_lines)
     * rather than directly off Booking - see getRoomLinesAttribute() below
     * and MULTI_ROOM_TRANSACTION_BACKEND_SPEC.md's "BillingDto#rooms" note.
     */
    protected $appends = ['room_lines'];

    protected $fillable = [
        'booking_id',
        'room_charge',
        'additional_guest_fee',
        'amenity_charge',
        'discount',
        'discount_id',
        'discount_verified_by',
        'discount_verified_at',
        'total_amount',
        'billing_status',
    ];

    protected $casts = [
        'room_charge' => 'decimal:2',
        'additional_guest_fee' => 'decimal:2',
        'amenity_charge' => 'decimal:2',
        'discount' => 'decimal:2',
        'total_amount' => 'decimal:2',
        'discount_verified_at' => 'datetime',
    ];

    /**
     * Get the booking associated with the billing.
     */
    public function booking()
    {
        return $this->belongsTo(Booking::class);
    }

    /**
     * The specific authorized Discount (Senior Citizen, PWD, etc.) manually
     * applied by a receptionist after ID verification, if any.
     */
    public function discountApplied()
    {
        return $this->belongsTo(Discount::class, 'discount_id');
    }

    /**
     * The receptionist who verified the guest's ID and applied the discount.
     */
    public function discountVerifier()
    {
        return $this->belongsTo(User::class, 'discount_verified_by');
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
     * A converted transaction's itemized room lines - delegates entirely to
     * the owning Booking's own accessor (property access invokes
     * Booking::getRoomLinesAttribute(), not its rooms() relation - see that
     * method's own doc) so this can never drift from what a genuinely direct
     * Booking's own top-level room_lines field would show for the same data.
     */
    public function getRoomLinesAttribute(): array
    {
        return $this->booking?->room_lines ?? [];
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
