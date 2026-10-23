<?php

namespace App\Models;

use Carbon\Carbon;
use Illuminate\Database\Eloquent\Model;

/**
 * One stay of a booking or reservation: its own check-in / check-out dates, its own rooms (room lines + the physical rooms assigned
 * at check-in) and its own amenities. A transaction (one booking or reservation, one ID) holds one or more of them.
 *
 * A transaction that has no stay row yet (a record created by older code or a test fixture) is read as ONE virtual stay built from its
 * own check_in / check_out - see Concerns\HasStays::stayList() - so every reader can rely on at least one stay existing.
 */
class Stay extends Model
{
    public const UPCOMING = 'upcoming';
    public const IN_HOUSE = 'in_house';
    public const CHECKED_OUT = 'checked_out';

    protected $fillable = ['booking_id', 'reservation_id', 'position', 'check_in', 'check_out', 'checked_in_at', 'checked_out_at'];

    protected $casts = [
        'check_in' => 'datetime',
        'check_out' => 'datetime',
        'checked_in_at' => 'datetime',
        'checked_out_at' => 'datetime',
    ];

    public function booking()
    {
        return $this->belongsTo(Booking::class);
    }

    public function reservation()
    {
        return $this->belongsTo(Reservation::class);
    }

    public function bookingLines()
    {
        return $this->hasMany(BookingRoomLine::class);
    }

    public function reservationLines()
    {
        return $this->hasMany(ReservationRoomLine::class);
    }

    /** True for the stand-in built from a transaction's own dates (nothing saved yet). */
    public function isVirtual(): bool
    {
        return ! $this->exists;
    }

    /** Booked nights: calendar days between the two dates, at least 1 (the same rule as StayBill). */
    public function nights(): int
    {
        return max(1, (int) Carbon::parse($this->check_in->format('Y-m-d'))->diffInDays(Carbon::parse($this->check_out->format('Y-m-d')), false));
    }

    /** upcoming (no room yet) | in_house | checked_out - read from the two moments the front desk records. */
    public function state(): string
    {
        if ($this->checked_out_at !== null) {
            return self::CHECKED_OUT;
        }

        return $this->checked_in_at !== null ? self::IN_HOUSE : self::UPCOMING;
    }

    /** "Oct 25 → Oct 26" style label for messages: "Oct 25 - Oct 26". */
    public function rangeLabel(): string
    {
        return $this->check_in->format('M j') . ' - ' . $this->check_out->format('M j');
    }

    /** The same dates as another stay (both the check-in AND the check-out day)? */
    public function sameDatesAs(self $other): bool
    {
        return $this->check_in->format('Y-m-d') === $other->check_in->format('Y-m-d')
            && $this->check_out->format('Y-m-d') === $other->check_out->format('Y-m-d');
    }
}
