<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class Booking extends Model
{
    use HasFactory, SoftDeletes;

    protected $fillable = [
        'reservation_id',
        'guest_id',
        'guest_first_name',
        'guest_middle_name',
        'guest_last_name',
        'checkin_permanent_address',
        'checkin_current_address',
        'checkin_contact_number',
        'room_type_id',
        'room_id',
        'rooms_requested',
        'check_in',
        'check_out',
        'adults',
        'children',
        'number_of_guests',
        'confirmed_at',
        'booking_status',
        'payment_method',
        'id_card_type',
        'id_card_image_path',
        'additional_guest_details',
        'discount_requested',
        'discount_verification_status',
        'rejection_reason',
        'verified_at',
        'verified_by',
        'hidden_at',
        'viewed_at',
    ];

    protected $casts = [
        'check_in' => 'datetime',
        'check_out' => 'datetime',
        'confirmed_at' => 'datetime',
        'verified_at' => 'datetime',
        'hidden_at' => 'datetime',
        'viewed_at' => 'datetime',
        'checkin_reminder_sent_at' => 'datetime',
        'deleted_at' => 'datetime',
        'discount_requested' => 'boolean',
        'additional_guest_details' => 'array',
    ];

    /**
     * Get the reservation associated with the booking - null for a
     * "New Booking" mobile-app transaction, which is created directly and
     * never derived from a Reservation (see Services\DirectBookingService).
     * Non-null for every booking created the original way (guest reserves,
     * receptionist converts).
     */
    public function reservation()
    {
        return $this->belongsTo(Reservation::class);
    }

    /**
     * The authenticated guest account this booking belongs to - only set
     * directly for a "New Booking" (reservation_id null) transaction; a
     * reservation-derived booking reaches the guest via
     * reservation->guest instead (see accountGuest()/getAccountGuestFullNameAttribute()
     * below, the single accessor every call site should use instead of
     * branching on reservation_id itself).
     */
    public function guest()
    {
        return $this->belongsTo(Guest::class);
    }

    /**
     * Payments made directly against this booking (payments.booking_id) -
     * only ever populated for a direct "New Booking" transaction
     * (reservation_id null). A reservation-derived booking's payments
     * still live on payments.reservation_id via reservation->payments, as
     * they always have - see allPayments()/latestGcashPayment() below for
     * the one accessor that transparently reads whichever applies.
     */
    public function payments()
    {
        return $this->hasMany(Payment::class, 'booking_id');
    }

    /**
     * The right payments collection for this booking regardless of type -
     * reservation->payments for a reservation-derived booking (unchanged
     * from before), payments() directly for a direct "New Booking". Works
     * whether or not the caller eager-loaded anything (falls back to a
     * lazy load), so no existing call site's eager-loading needs to change.
     */
    public function allPayments()
    {
        return $this->reservation_id ? $this->reservation->payments : $this->payments;
    }

    /**
     * Get the room type requested (copied from the reservation at
     * conversion time).
     */
    public function roomType()
    {
        return $this->belongsTo(RoomType::class);
    }

    /**
     * Get the physical room. Null until the receptionist assigns one at
     * check-in. For a multi-room booking this is just the first assigned
     * room (see rooms()) - kept for the many display-only call sites that
     * only need "the room" as a reasonable simplification.
     */
    public function room()
    {
        return $this->belongsTo(Room::class);
    }

    /**
     * All rooms assigned to this booking (set at check-in - may be more
     * than one when rooms_requested > 1). Empty until check-in.
     */
    public function rooms()
    {
        return $this->belongsToMany(Room::class, 'booking_rooms')->withTimestamps();
    }

    /**
     * Get the billing associated with the booking.
     */
    public function billing()
    {
        return $this->hasOne(Billing::class);
    }

    /**
     * Calculate the number of nights.
     */
    public function getNumberOfNightsAttribute()
    {
        return abs($this->check_out->diffInDays($this->check_in));
    }

    /**
     * The true total amount due for this booking (room charge + every
     * amenity actually charged to it), independent of amount_paid - needed
     * now that a direct "New Booking" (Services\DirectBookingService) can
     * be paid partially, so amount_paid is no longer always equal to the
     * total the way it used to be. Deliberately NOT in $appends (this
     * would add an extra query per row to every Booking listing app-wide,
     * e.g. Admin/Manager/Receptionist) - only Api\BookingController's
     * guest-facing responses attach it explicitly where it's needed.
     */
    public function getTotalAmountDueAttribute(): float
    {
        $nights = $this->check_in && $this->check_out
            ? max(1, abs($this->check_out->diffInDays($this->check_in)))
            : 1;
        $roomTotal = (float) ($this->roomType->rate ?? 0) * $nights * max(1, $this->rooms_requested);
        $amenityTotal = (float) AmenityRequest::where('booking_id', $this->id)
            ->selectRaw('COALESCE(SUM(charge * quantity), 0) as total')
            ->value('total');

        return round($roomTotal + $amenityTotal, 2);
    }

    /**
     * The most recent payment made against this booking (the pre-checkout
     * deposit stage - a Billing/final-stage payment doesn't exist yet at
     * this point in the lifecycle). Uses allPayments() so this works
     * identically for a reservation-derived booking and a direct "New
     * Booking" without the caller needing to know which one it has.
     */
    public function latestGcashPayment(): ?Payment
    {
        return $this->allPayments()
            ->sortByDesc('created_at')
            ->first(fn (Payment $payment) => $payment->payment_method === 'gcash');
    }

    /**
     * True when this is a GCash-paid booking whose payment hasn't been
     * verified by a receptionist yet (still pending, or rejected and not
     * yet resubmitted) - the gate that keeps a GCash-paid booking from
     * being marked fully verified before its payment has actually been
     * reviewed. Cash bookings (no GCash payment at all) always return
     * false - this gate only applies to GCash.
     */
    public function gcashPaymentNeedsVerification(): bool
    {
        $payment = $this->latestGcashPayment();

        return $payment !== null && ! $payment->isVerified();
    }

    /**
     * The single accessor every call site should use for "whose booking is
     * this" instead of reaching through ->reservation->guest->user->full_name
     * directly - resolves via the reservation's account holder for a
     * reservation-derived booking (identical to today's behavior), or via
     * this booking's own guest() for a direct "New Booking" transaction.
     */
    public function getAccountGuestFullNameAttribute(): ?string
    {
        return $this->reservation_id
            ? $this->reservation?->guest?->user?->full_name
            : $this->guest?->user?->full_name;
    }

    /**
     * The Guest model behind this booking either way - reservation->guest
     * for a reservation-derived booking, this booking's own guest()
     * otherwise. Mirrors getAccountGuestFullNameAttribute()'s branching so
     * callers that need the Guest record itself (not just the name) have
     * one place to get it too.
     */
    public function getAccountGuestAttribute(): ?Guest
    {
        return $this->reservation_id ? $this->reservation?->guest : $this->guest;
    }

    /**
     * The representative stay guest's name captured at booking time
     * (guest_first_name/middle/last_name) - only ever populated for a
     * direct "New Booking" transaction; mirrors
     * Reservation::getStayGuestFullNameAttribute()'s exact same fallback
     * shape for consistency.
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
     * Guest name for display/logging with a guaranteed non-null result -
     * prefers the typed Representative Name, falls back to the account
     * holder, then a literal fallback so an Activity::log() message never
     * embeds an empty string for a fully accountless booking (Receptionist\
     * BookingController::store()'s "Create Booking" action, or a Walk-in
     * Check-in). Mirrors Reservation::getGuestDisplayNameAttribute().
     */
    public function getGuestDisplayNameAttribute(): string
    {
        return $this->stay_guest_full_name ?? $this->account_guest_full_name ?? 'Guest';
    }

    public function setGuestFirstNameAttribute(?string $value): void
    {
        $this->attributes['guest_first_name'] = $value ? ucwords(strtolower($value)) : $value;
    }

    public function setGuestMiddleNameAttribute(?string $value): void
    {
        $this->attributes['guest_middle_name'] = $value ? ucwords(strtolower($value)) : $value;
    }

    public function setGuestLastNameAttribute(?string $value): void
    {
        $this->attributes['guest_last_name'] = $value ? ucwords(strtolower($value)) : $value;
    }
}
