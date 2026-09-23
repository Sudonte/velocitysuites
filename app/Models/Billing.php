<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

class Billing extends Model
{
    use HasFactory;

    /**
     * Post-conversion, the mobile app's ApiMapper reads a converted
     * transaction's itemized room lines from here (dto.booking.billing.room_lines)
     * rather than directly off Booking - see getRoomLinesAttribute() below
     * and MULTI_ROOM_TRANSACTION_BACKEND_SPEC.md's "BillingDto#rooms" note.
     * Same for its itemized amenity lines (dto.booking.billing.amenities) -
     * see getAmenitiesAttribute() below, previously missing entirely (see
     * that accessor's own doc for the guest-facing symptom this caused).
     */
    protected $appends = ['room_lines', 'amenities'];

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
        // receipt_number is deliberately NOT fillable - see
        // Payment::$fillable's identical note; it's system-generated,
        // lazily assigned by ensureOfficialReceiptNumber() below.
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
     * A converted transaction's itemized paid amenities - delegates
     * entirely to the owning Booking's own accessor (Booking::
     * getAmenitiesAttribute()), same convention as getRoomLinesAttribute()
     * above, so this can never drift from what the booking's own top-level
     * amenities field would show. Previously this accessor didn't exist at
     * all, so the mobile app's ApiMapper (which already reads
     * dto.booking.billing.amenities and trusts totalAmount as already
     * amenities-inclusive for a converted transaction) always got a
     * null/empty list here regardless of what the guest actually paid for -
     * see BookingAmenityDto's own doc, which explicitly called this out as
     * "not returned by the live API today."
     */
    public function getAmenitiesAttribute(): array
    {
        return $this->booking?->amenities ?? [];
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

    /**
     * True once checkout has ACTUALLY completed for this billing - the
     * one authoritative rule for "the Official Payment Receipt is
     * available" (PAYMENT_RECEIPT_HISTORY_BACKEND_SPEC.md §20).
     *
     * Deliberately checks BOTH billing_status === 'paid' AND
     * booking.booking_status === Booking::STATUS_COMPLETED, not
     * billing_status alone - a real, confirmed gap was found in the first
     * draft of this predicate: Receptionist\CheckOutController::
     * refreshStayCharges() (run every time the Billing Panel is merely
     * OPENED, at checkout-start, and again at generateBilling()'s initial
     * creation - see that method's own doc) independently recomputes
     * billing_status straight from the paid-vs-total sum:
     *
     *     $billing->update(['billing_status' => $paid <= 0 ? 'pending'
     *         : ($paid >= (float) $billing->total_amount ? 'paid' : 'partial')]);
     *
     * So a booking that already had a 100%-covering payment BEFORE
     * checkout (Scenario C - a guest-submitted, receptionist-verified
     * full GCash payment) gets billing_status flipped to 'paid' the
     * MOMENT the receptionist merely opens the checkout Billing Panel -
     * before the Payment Panel's recordPayment() ever runs, before
     * checkout has actually completed, and before booking_status has
     * moved off Booking::STATUS_CHECKED_IN. Only recordPayment()'s own
     * $completed branch ever moves booking_status to
     * Booking::STATUS_COMPLETED, and it does so in the very same DB
     * transaction as the billing_status='paid' write that settles the
     * balance - the two are only ever genuinely synchronized there. This
     * second condition is what actually distinguishes "the sums happen to
     * balance" from "the receptionist actually finished checkout".
     */
    public function isOfficialReceiptAvailable(): bool
    {
        return $this->billing_status === 'paid'
            && $this->booking?->booking_status === Booking::STATUS_COMPLETED;
    }

    /**
     * Lazily assigns (once) and returns this billing's Official Receipt
     * number, or null if checkout hasn't fully settled yet. Same
     * collision-free-by-construction, lock-and-recheck idempotency as
     * Payment::ensureReceiptNumber() - see that method's own doc.
     */
    public function ensureOfficialReceiptNumber(): ?string
    {
        if ($this->receipt_number) {
            return $this->receipt_number;
        }

        if (!$this->isOfficialReceiptAvailable()) {
            return null;
        }

        return DB::transaction(function () {
            $locked = static::whereKey($this->id)->lockForUpdate()->first();
            if (!$locked) {
                return null;
            }

            if ($locked->receipt_number) {
                $this->receipt_number = $locked->receipt_number;

                return $locked->receipt_number;
            }

            $number = static::formatReceiptNumber($locked->id, now());
            $locked->forceFill(['receipt_number' => $number])->save();
            $this->receipt_number = $number;

            return $number;
        });
    }

    /**
     * Pure formatting - OR-{issued date}-{zero-padded billing id}. See
     * Payment::formatReceiptNumber()'s identical doc/rationale.
     */
    public static function formatReceiptNumber(int $id, $date): string
    {
        $date = $date instanceof \DateTimeInterface ? $date : now();

        return 'OR-' . $date->format('Ymd') . '-' . str_pad((string) $id, 6, '0', STR_PAD_LEFT);
    }
}
