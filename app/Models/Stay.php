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
    public const NO_SHOW = 'no_show';

    protected $fillable = ['booking_id', 'reservation_id', 'position', 'check_in', 'check_out', 'checked_in_at', 'checked_out_at', 'no_show_at', 'no_show_reason'];

    protected $casts = [
        'check_in' => 'datetime',
        'check_out' => 'datetime',
        'checked_in_at' => 'datetime',
        'checked_out_at' => 'datetime',
        'no_show_at' => 'datetime',
        'checkin_alerted_at' => 'datetime',
        'checkout_alerted_at' => 'datetime',
        'extended_at' => 'datetime',
    ];

    /** Checked in, a room still in the house, and past 12 NN on its check-out day. */
    public function scopeCheckOutDue($query)
    {
        $beforeDay = \App\Support\HotelClock::lastDueCheckOutDay()->addDay()->toDateString();

        return $query->whereNull('stays.checked_out_at')->whereNull('stays.no_show_at')
            ->where('stays.check_out', '<', $beforeDay)
            ->whereHas('booking', fn ($b) => $b->where('booking_status', Booking::STATUS_CHECKED_IN))
            ->where(fn ($q) => $q->whereNotNull('stays.checked_in_at')
                ->orWhereRaw('(SELECT COUNT(*) FROM stays s2 WHERE s2.booking_id = stays.booking_id) = 1'))
            ->whereExists(fn ($r) => $r->select(\Illuminate\Support\Facades\DB::raw(1))->from('booking_rooms as br')
                ->whereColumn('br.booking_id', 'stays.booking_id')->whereNull('br.checked_out_at')
                ->where(fn ($w) => $w->whereColumn('br.stay_id', 'stays.id')->orWhereNull('br.stay_id')));
    }

    /** Still to arrive on a booking the front desk has verified, and past 2 PM on its check-in day. */
    public function scopeCheckInDue($query)
    {
        $beforeDay = \App\Support\HotelClock::lastDueCheckInDay()->addDay()->toDateString();

        return $query->whereNull('stays.checked_in_at')->whereNull('stays.checked_out_at')->whereNull('stays.no_show_at')
            ->where('stays.check_in', '<', $beforeDay)
            ->whereHas('booking', fn ($b) => $b->awaitingCheckIn()->whereNotNull('verified_at')->whereNull('hidden_at'));
    }

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
        if ($this->no_show_at !== null && $this->checked_in_at === null) {
            return self::NO_SHOW;
        }
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

    /** The whole duration of these stays, first check-in to last check-out: "Nov 22 – Nov 28, 2026" (the year on both sides when they differ). */
    public static function durationLabel(\Illuminate\Support\Collection $stays): string
    {
        $from = $stays->min('check_in');
        $to = $stays->max('check_out');

        return $from->format($from->year === $to->year ? 'M d' : 'M d, Y') . ' – ' . $to->format('M d, Y');
    }

    /** The same dates as another stay (both the check-in AND the check-out day)? */
    public function sameDatesAs(self $other): bool
    {
        return $this->check_in->format('Y-m-d') === $other->check_in->format('Y-m-d')
            && $this->check_out->format('Y-m-d') === $other->check_out->format('Y-m-d');
    }
}
