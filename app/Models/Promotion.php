<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Storage;

class Promotion extends Model
{
    use \App\Models\Concerns\Archivable;

    /** Archive switches it off; restore brings it back off for the admin to re-enable. */
    public const ARCHIVED_STATUS = 'inactive';
    public const RESTORED_STATUS = 'inactive';

    use HasFactory;

    protected $fillable = [
        'promo_name',
        'promo_type',
        'discount_type',
        'discount_value',
        'description',
        'image',
        'room_type_id',
        'start_date',
        'end_date',
        'is_recurring',
        'recur_start_md',
        'recur_end_md',
        'status',
    ];

    protected $casts = [
        'is_recurring' => 'boolean',
        'start_date' => 'date',
        'end_date' => 'date',
        'discount_value' => 'decimal:2',
        'notified_at' => 'datetime',
    ];

    /**
     * Auto-included in JSON (Api\CatalogController::promotions() serializes
     * the model directly, unlike Announcement's hand-built array) so the
     * mobile app never has to resolve a bare storage-relative path itself.
     */
    protected $appends = ['image_url'];

    public function getImageUrlAttribute(): ?string
    {
        return $this->image ? Storage::disk('public')->url($this->image) : null;
    }

    /**
     * Get the room type this promotion targets (null = all types).
     */
    public function roomType()
    {
        return $this->belongsTo(RoomType::class);
    }

    /**
     * Amenities bundled by an amenity-type promotion, with per-amenity
     * quantities on the pivot. Empty for discount promotions.
     */
    public function amenities()
    {
        return $this->belongsToMany(Amenity::class, 'promotion_amenity')
            ->withPivot('quantity')
            ->withTimestamps();
    }

    /** A promo can include amenities, a discount, or both. */
    public function hasDiscount(): bool
    {
        return in_array($this->discount_type, ['percentage', 'fixed'], true) && (float) $this->discount_value > 0;
    }

    /** "15% off" / "₱500 off per night" - null without a discount. */
    public function discountLabel(): ?string
    {
        if (! $this->hasDiscount()) {
            return null;
        }

        return $this->discount_type === 'percentage'
            ? rtrim(rtrim(number_format((float) $this->discount_value, 2), '0'), '.') . '% off'
            : '₱' . number_format((float) $this->discount_value, 2) . ' off per night';
    }

    /** "2 x Breakfast, 1 x Spa + 10% off (Deluxe rate only)" - what the promo gives, for the staff's pickers. */
    public function offerSummary(): string
    {
        $parts = [];
        $amenities = $this->amenities->map(fn ($a) => $a->pivot->quantity . ' x ' . $a->amenity_name)->implode(', ');
        if ($amenities !== '') {
            $parts[] = $amenities;
        }
        if ($this->hasDiscount()) {
            $parts[] = $this->discountLabel() . ' (' . ($this->roomType ? $this->roomType->name . ' rate only' : 'every room rate') . ')';
        }

        return $parts ? implode(' + ', $parts) : 'nothing included';
    }

    /** Does this promo cover a room of this type? (no room type = every type) */
    public function coversRoomType($roomTypeId): bool
    {
        return $this->room_type_id === null || (string) $this->room_type_id === (string) $roomTypeId;
    }

    /**
     * Pesos this promo takes off one room line: the discount comes off the nightly RATE of a room of the
     * promo's room type (every room when it is for all types), times its nights. Any other room type is
     * charged in full. A fixed amount never takes a night below zero.
     */
    public function discountForRoom($roomTypeId, float $rate, int $nights): float
    {
        if (! $this->hasDiscount() || ! $this->coversRoomType($roomTypeId) || $rate <= 0) {
            return 0.0;
        }
        $offPerNight = $this->discount_type === 'percentage'
            ? $rate * min(100.0, (float) $this->discount_value) / 100
            : min((float) $this->discount_value, $rate);

        return round($offPerNight * max(1, $nights), 2);
    }

    /**
     * Check if promotion is currently active.
     *
     * start_date/end_date are `date`-cast (Carbon instances) - comparing
     * one against a plain string via <=/>= always resolved false/true
     * respectively regardless of the actual dates (PHP's DateTime-vs-
     * string comparison rules), so this accessor always returned false.
     * Comparing against another Carbon instance instead fixes that.
     */
    public function getIsActiveAttribute()
    {
        $today = \App\Support\CheckInWindow::date();

        return $this->status === 'active'
            && $this->start_date <= $today
            && $this->end_date >= $today;
    }

    /**
     * Promotions running on $day: one-time ones by their dates, yearly ones
     * by their month/day window (which may cross New Year, e.g. 1220..105).
     */
    public function scopeRunningOn($query, \Carbon\Carbon $day)
    {
        $md = (int) $day->format('n') * 100 + (int) $day->format('j');

        return $query->where(function ($q) use ($day, $md) {
            $q->where(fn ($once) => $once->where('is_recurring', false)
                ->whereDate('start_date', '<=', $day)->whereDate('end_date', '>=', $day))
              ->orWhere(fn ($yearly) => $yearly->where('is_recurring', true)->where(fn ($w) => $w
                  ->where(fn ($inYear) => $inYear->whereColumn('recur_start_md', '<=', 'recur_end_md')
                      ->where('recur_start_md', '<=', $md)->where('recur_end_md', '>=', $md))
                  ->orWhere(fn ($wraps) => $wraps->whereColumn('recur_start_md', '>', 'recur_end_md')
                      ->where(fn ($e) => $e->where('recur_start_md', '<=', $md)->orWhere('recur_end_md', '>=', $md)))));
        });
    }

    /** Set start_date / end_date to the current (or next) occurrence of a yearly window. */
    public function syncRecurringDates(?\Carbon\Carbon $from = null): void
    {
        if (! $this->is_recurring || ! $this->recur_start_md || ! $this->recur_end_md) {
            return;
        }
        $from = ($from ?? \App\Support\CheckInWindow::date())->copy()->startOfDay();
        $make = function (int $year, int $md): \Carbon\Carbon {
            $month = intdiv($md, 100);
            $day = min($md % 100, \Carbon\Carbon::create($year, $month, 1)->daysInMonth);

            return \Carbon\Carbon::create($year, $month, $day)->startOfDay();
        };
        foreach ([$from->year - 1, $from->year, $from->year + 1] as $year) {
            $start = $make($year, $this->recur_start_md);
            $end = $make($this->recur_start_md <= $this->recur_end_md ? $year : $year + 1, $this->recur_end_md);
            if ($end->gte($from)) {
                $this->start_date = $start;
                $this->end_date = $end;

                return;
            }
        }
    }
}
