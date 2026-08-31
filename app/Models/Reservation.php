<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Reservation extends Model
{
    use HasFactory;

    protected $fillable = [
        'guest_id',
        'guest_first_name',
        'guest_middle_name',
        'guest_last_name',
        'room_type_id',
        'room_id',
        'rooms_requested',
        'check_in',
        'check_out',
        'number_of_guests',
        'adults',
        'children',
        'status',
        'payment_preference',
        'payment_method',
        'payment_method_locked_at',
        'payment_reminder_sent_at',
        'discount_requested',
        'id_document_path',
        'discount_verification_status',
        'rejection_reason',
        'id_card_type',
        'id_card_image_path',
        'additional_guest_details',
        'verified_at',
        'verified_by',
        'hidden_at',
    ];

    protected $casts = [
        'check_in' => 'datetime',
        'check_out' => 'datetime',
        'discount_requested' => 'boolean',
        'additional_guest_details' => 'array',
        'verified_at' => 'datetime',
        'hidden_at' => 'datetime',
        'payment_method_locked_at' => 'datetime',
        'payment_reminder_sent_at' => 'datetime',
    ];

    /**
     * discount_preview is a read-only computed field (see
     * getDiscountPreviewAttribute()) so the mobile app's pre-payment Bill
     * Summary screen can show an accurate room charge/discount/total
     * before a Billing row exists - it's never stored, always derived.
     * payment_deadline is the 48-hour Pay Later/Pay Now cutoff (see
     * getPaymentDeadlineAttribute()) - also always derived, never stored.
     */
    protected $appends = ['discount_preview', 'payment_deadline'];

    /**
     * Get the guest associated with the reservation.
     */
    public function guest()
    {
        return $this->belongsTo(Guest::class);
    }

    /**
     * Get the room type the guest requested. Always set; the specific
     * room (room_id) stays null until a receptionist assigns one at
     * confirmation time.
     */
    public function roomType()
    {
        return $this->belongsTo(RoomType::class);
    }

    /**
     * Get the room associated with the reservation. Deprecated going
     * forward - room assignment now happens at check-in, against the
     * Booking, not the Reservation. Kept for historical/pre-redesign
     * records; new code should not write to reservations.room_id.
     */
    public function room()
    {
        return $this->belongsTo(Room::class);
    }

    /**
     * Get the booking associated with the reservation. Only exists once
     * the reservation has been converted (status = converted).
     */
    public function booking()
    {
        return $this->hasOne(Booking::class);
    }

    /**
     * Get deposit payments made against this reservation before a Billing
     * exists (payment_stage = 'deposit', billing_id null until re-parented
     * at checkout).
     */
    public function payments()
    {
        return $this->hasMany(Payment::class);
    }

    /**
     * Paid/Additional amenities the guest selected at booking time, with
     * a historical snapshot of the amenity's name/price at that moment
     * (see ReservationAmenity) - later changes to the live Amenities
     * catalog never rewrite what this reservation actually shows. This is
     * also the sole source of truth for which amenities a guest is
     * allowed to submit a post-booking AmenityRequest for (see
     * Api\AmenityRequestController) - never anything outside this list.
     */
    public function bookingAmenities()
    {
        return $this->hasMany(ReservationAmenity::class);
    }

    /**
     * Get the amenity requests made during this reservation's stay.
     */
    public function amenityRequests()
    {
        return $this->hasMany(AmenityRequest::class);
    }

    /**
     * Calculate the number of nights.
     */
    public function getNumberOfNightsAttribute()
    {
        return abs($this->check_out->diffInDays($this->check_in));
    }

    /**
     * The name of the person actually staying, as provided at
     * reservation/booking time - may differ from the account holder's
     * name (e.g. booking made on a friend's account).
     */
    public function getStayGuestFullNameAttribute(): ?string
    {
        if (! $this->guest_first_name && ! $this->guest_last_name) {
            return null;
        }
        if ($this->guest_middle_name) {
            return trim("{$this->guest_first_name} {$this->guest_middle_name} {$this->guest_last_name}");
        }
        return trim("{$this->guest_first_name} {$this->guest_last_name}");
    }

    /**
     * Title-case the stay guest's first name, matching User's convention.
     */
    public function setGuestFirstNameAttribute(?string $value): void
    {
        $this->attributes['guest_first_name'] = $value ? ucwords(strtolower($value)) : $value;
    }

    /**
     * Title-case the stay guest's middle name, matching User's convention.
     */
    public function setGuestMiddleNameAttribute(?string $value): void
    {
        $this->attributes['guest_middle_name'] = $value ? ucwords(strtolower($value)) : null;
    }

    /**
     * Title-case the stay guest's last name, matching User's convention.
     */
    public function setGuestLastNameAttribute(?string $value): void
    {
        $this->attributes['guest_last_name'] = $value ? ucwords(strtolower($value)) : $value;
    }

    /**
     * Room charge/discount/total preview: once converted, returns the
     * already-locked figures from Booking\Billing (the real, final values -
     * see BookingService::ensureBilling(), which never overwrites these
     * after first creation); before conversion, computes a live quote via
     * BookingService::quoteRoomCharge() so a guest reviewing an unpaid
     * Reservation's bill (before any Booking/Billing row exists) sees an
     * accurate preview rather than nothing. Null only if the room type
     * relation itself is missing (shouldn't happen for a real reservation).
     */
    public function getDiscountPreviewAttribute(): ?array
    {
        if ($this->booking && $this->booking->billing) {
            return [
                'room_charge' => (float) $this->booking->billing->room_charge,
                'discount' => (float) $this->booking->billing->discount,
                'total' => (float) $this->booking->billing->total_amount,
            ];
        }

        if (! $this->roomType) {
            return null;
        }

        return app(\App\Services\BookingService::class)->quoteRoomCharge($this);
    }

    /**
     * The 48-hour (config('hotel.payment_deadline_hours')) Pay Later/Pay
     * Now payment cutoff, or null when the rule doesn't apply:
     * - already converted/rejected/cancelled (nothing left to pay for), or
     * - a completed payment already exists, or
     * - check_in is less than 2 days after created_at (the "reservation for
     *   tomorrow" exemption - there isn't enough time left for a real
     *   48-hour window, so the guest simply pays walk-in/at the hotel with
     *   no deadline pressure).
     * Computed here, once, so both the mobile app's countdown display and
     * ReservationWorkflowService::expireUnpaid()'s enforcement always agree -
     * see the accompanying config('hotel.payment_deadline_hours') doc block
     * for the enforcement side.
     */
    public function getPaymentDeadlineAttribute(): ?string
    {
        if (! in_array($this->status, ['pending_review', 'ready_for_booking'], true)) {
            return null;
        }

        if ($this->payments()->where('payment_status', 'completed')->exists()) {
            return null;
        }

        if ($this->check_in->lt($this->created_at->copy()->addDays(2))) {
            return null;
        }

        return $this->created_at->copy()
            ->addHours((int) config('hotel.payment_deadline_hours', 48))
            ->toIso8601String();
    }
}
