<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class AmenityRequest extends Model
{
    use HasFactory;

    protected $fillable = [
        'guest_id',
        'reservation_id',
        'booking_id',
        'room_id',
        'room_type_id',
        'amenity_id',
        'amenity_name',
        'category',
        'quantity',
        'charge',
        'status',
        'archived_at',
    ];

    protected $casts = [
        'charge' => 'decimal:2',
        'archived_at' => 'datetime',
    ];

    /**
     * Get the guest associated with the amenity request.
     */
    public function guest()
    {
        return $this->belongsTo(Guest::class);
    }

    /**
     * Get the reservation this amenity request was made during - null for
     * a request generated from a direct "New Booking" transaction (see
     * booking() below instead).
     */
    public function reservation()
    {
        return $this->belongsTo(Reservation::class);
    }

    /**
     * Get the direct booking this amenity request was made during - only
     * set for a "New Booking" transaction (reservation_id null on both the
     * booking and this request). Mirrors reservation() above.
     */
    public function booking()
    {
        return $this->belongsTo(Booking::class);
    }

    /**
     * The physical room the request was made for, snapshotted at request
     * time from the reservation's booking - nullable, since a request can
     * be made before a specific room is assigned (this is a "which room
     * was occupied when the guest asked" record, not a live lookup).
     */
    public function room()
    {
        return $this->belongsTo(Room::class);
    }

    /**
     * The room type at request time, snapshotted the same way as room().
     */
    public function roomType()
    {
        return $this->belongsTo(RoomType::class);
    }

    /**
     * The live catalog amenity, for reference only - never used to derive
     * price/name for display, since amenity_name/charge are the frozen-
     * at-request-time values (a later admin rename/price change must not
     * rewrite historical requests).
     */
    public function amenity()
    {
        return $this->belongsTo(Amenity::class);
    }

    /**
     * charge is the historical per-unit price at request time; subtotal is
     * derived rather than stored, so there's only one source of truth for
     * the per-unit price.
     */
    public function getSubtotalAttribute(): float
    {
        return (float) $this->charge * (int) $this->quantity;
    }

    /**
     * Guest name for display, guaranteed non-null - delegates to whichever
     * of reservation()/booking() this request belongs to (their own
     * guest_display_name already handles typed-Representative-Name vs
     * account-holder vs "no account at all" correctly), falling back to
     * guest_id's own account only if somehow neither is set. Every call
     * site that used to read $request->guest->user->full_name directly
     * should use this instead - a walk-in/accountless booking's amenity
     * requests have no Guest account to read at all.
     */
    public function getGuestDisplayNameAttribute(): string
    {
        if ($this->reservation_id) {
            return $this->reservation?->guest_display_name ?? 'Guest';
        }
        if ($this->booking_id) {
            return $this->booking?->guest_display_name ?? 'Guest';
        }

        return $this->guest?->user?->full_name ?? 'Guest';
    }
}
