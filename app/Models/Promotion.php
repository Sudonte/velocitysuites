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
        'discount_applies_to',
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

    /** What its discount comes off (discount_applies_to). */
    public const APPLIES_TO_RATE = 'rate';
    public const APPLIES_TO_TOTAL = 'total';

    /** A promo can include amenities, a discount, or both. */
    public function hasDiscount(): bool
    {
        return in_array($this->discount_type, ['percentage', 'fixed'], true) && (float) $this->discount_value > 0;
    }

    /** True when the discount comes off the stay's total from before check-in, not the room rate. */
    public function discountsTotal(): bool
    {
        return $this->discount_applies_to === self::APPLIES_TO_TOTAL;
    }

    /** "15% off the room rate" / "₱500 off per night" / "10% off the total" / "₱1,000.00 off the total" - null without a discount. */
    public function discountLabel(): ?string
    {
        if (! $this->hasDiscount()) {
            return null;
        }
        $amount = $this->discount_type === 'percentage'
            ? rtrim(rtrim(number_format((float) $this->discount_value, 2), '0'), '.') . '%'
            : '₱' . number_format((float) $this->discount_value, 2);

        if ($this->discountsTotal()) {
            return $amount . ' off the total';
        }

        return $this->discount_type === 'percentage' ? $amount . ' off the room rate' : $amount . ' off per night';
    }

    /** "2 x Breakfast, 1 x Spa + 10% off the room rate (Deluxe only)" - what the promo gives, for the staff's pickers. */
    public function offerSummary(): string
    {
        $parts = [];
        $amenities = $this->amenities->map(fn ($a) => $a->pivot->quantity . ' x ' . $a->amenity_name)->implode(', ');
        if ($amenities !== '') {
            $parts[] = $amenities;
        }
        if ($this->hasDiscount()) {
            $parts[] = $this->discountLabel() . ' (' . ($this->discountsTotal()
                ? 'before check-in' . ($this->roomType ? ', ' . $this->roomType->name . ' bookings' : '')
                : ($this->roomType ? $this->roomType->name . ' rooms only' : 'every room')) . ')';
        }

        return $parts ? implode(' + ', $parts) : 'nothing included';
    }

    /** Does this promo cover a room of this type? (no room type = every type) */
    public function coversRoomType($roomTypeId): bool
    {
        return $this->room_type_id === null || (string) $this->room_type_id === (string) $roomTypeId;
    }

    /**
     * Pesos this promo takes off one room line (room-rate promos only): the discount comes off the nightly RATE of
     * a room of the promo's room type (every room when it is for all types), times its nights. Any other room type
     * is charged in full. A fixed amount never takes a night below zero.
     */
    public function discountForRoom($roomTypeId, float $rate, int $nights): float
    {
        if (! $this->hasDiscount() || $this->discountsTotal() || ! $this->coversRoomType($roomTypeId) || $rate <= 0) {
            return 0.0;
        }
        $offPerNight = $this->discount_type === 'percentage'
            ? $rate * min(100.0, (float) $this->discount_value) / 100
            : min((float) $this->discount_value, $rate);

        return round($offPerNight * max(1, $nights), 2);
    }

    /**
     * Pesos this promo takes off a stay. A room-rate promo: each room line (room_type_id, rate, nights) on its own.
     * A total promo: a percentage of, or a fixed amount once off, $totalBeforeCheckIn - the room charge plus the
     * amenities booked up to check-in. Charges added during the stay or at check-out are never part of that total.
     *
     * @param  array<int,array{room_type_id:mixed,rate:float|int|string,nights:int}>  $roomLines
     */
    public function discountFor(array $roomLines, float $totalBeforeCheckIn): float
    {
        if (! $this->hasDiscount()) {
            return 0.0;
        }
        if ($this->discountsTotal()) {
            $base = max(0.0, $totalBeforeCheckIn);
            $off = $this->discount_type === 'percentage'
                ? $base * min(100.0, (float) $this->discount_value) / 100
                : (float) $this->discount_value;

            return round(min($off, $base), 2);
        }

        return round(array_sum(array_map(
            fn ($l) => $this->discountForRoom($l['room_type_id'], (float) $l['rate'], (int) $l['nights']),
            $roomLines,
        )), 2);
    }

    /**
     * An ACTIVE promo: switched on, not archived, today (hotel time) inside its dates, and assigned to an active room type
     * (a promo with no room type covers every type). Guests only ever see these.
     */
    public function scopeActiveNow($query, ?\Carbon\Carbon $day = null)
    {
        return $query->where('status', 'active')->notArchived()
            ->runningOn($day ?? \App\Support\CheckInWindow::date())
            ->where(fn ($q) => $q->whereNull('room_type_id')
                ->orWhereHas('roomType', fn ($t) => $t->where('status', 'active')));
    }

    private const CATALOG_KEY = 'promotions.active-catalog';

    /** Forget the per-request list of active promos (a promo or a room type changed). */
    public static function forgetActiveCatalog(): void
    {
        app()->forgetInstance(self::CATALOG_KEY);
    }

    protected static function booted(): void
    {
        static::saved(fn () => static::forgetActiveCatalog());
        static::deleted(fn () => static::forgetActiveCatalog());
    }

    /**
     * Active promos for any of these room types (a promo for another type is not listed). The list of everything active today is
     * read once per request, so a screen listing many transactions does not ask the database once per transaction.
     */
    public static function activeFor(array $roomTypeIds, ?\Carbon\Carbon $day = null)
    {
        $day ??= \App\Support\CheckInWindow::date();
        $cached = app()->bound(self::CATALOG_KEY) ? app(self::CATALOG_KEY) : null;
        if ($cached === null || $cached['day'] !== $day->toDateString()) {
            $cached = ['day' => $day->toDateString(), 'list' => static::with(['roomType', 'amenities'])->activeNow($day)->orderBy('promo_name')->get()];
            app()->instance(self::CATALOG_KEY, $cached);
        }
        $ids = array_map('intval', $roomTypeIds);

        return $cached['list']->filter(fn ($p) => $p->room_type_id === null || in_array((int) $p->room_type_id, $ids, true))->values();
    }

    /**
     * EVERY promo a transaction can use, as the rows the apps show: the ones remembered when it was booked ($snapshot, honoured even
     * after they expired), the ones running today for its room types (none once it is over), and the applied one. Each carries the
     * saving it would give on $lines (room_type_id, rate, nights) - an estimate, never subtracted until the desk verifies it.
     *
     * @param  array<int,array<string,mixed>>  $lines
     * @return array<int,array<string,mixed>>
     */
    public static function availableRows(array $lines, array $snapshot, ?Promotion $applied, bool $over = false): array
    {
        $typeIds = array_values(array_unique(array_filter(array_map(fn ($l) => ($l['room_type_id'] ?? null) !== null ? (int) $l['room_type_id'] : null, $lines))));
        $ids = collect($snapshot)->pluck('id')->map(fn ($i) => (int) $i);
        if ($applied) {
            $ids->push((int) $applied->id);
        }
        $promos = $over ? collect() : static::activeFor($typeIds);
        $extra = $ids->diff($promos->pluck('id'))->values();
        if ($extra->isNotEmpty()) {
            $promos = $promos->concat(static::with(['roomType', 'amenities'])->whereIn('id', $extra)->get());
        }

        return $promos->unique('id')
            ->filter(fn ($p) => $p->room_type_id === null || in_array((int) $p->room_type_id, $typeIds, true))
            ->map(function ($p) use ($lines, $applied) {
                $row = $p->snapshotRow();
                $row['estimated_saving'] = $p->hasDiscount() && ! $p->discountsTotal() ? $p->discountFor($lines, 0.0) : 0.0;
                $row['applied'] = $applied !== null && (int) $applied->id === (int) $p->id;
                $row['amenities'] = $p->amenities->map(fn ($a) => $a->pivot->quantity . ' x ' . $a->amenity_name)->values()->all();

                return $row;
            })->values()->all();
    }

    /**
     * What a guest's screens need about promos on one transaction: every promo it can use (each with an estimated saving), which one the
     * desk applied and what it took off. Before the desk verifies a promo nothing is subtracted.
     *
     * @param  array<int,array<string,mixed>>  $lines  room_type_id, rate, nights (rooms x nights)
     * @return array<string,mixed>
     */
    public static function infoFor(array $lines, array $snapshot, ?Promotion $applied, bool $over = false): array
    {
        $off = $applied ? $applied->discountFor($lines, 0.0) : 0.0;

        return [
            'available' => static::availableRows($lines, $snapshot, $applied, $over),
            'applied_id' => $applied?->id,
            'applied_title' => $applied?->promo_name,
            'promo_discount' => $applied && ! $applied->discountsTotal() ? $off : 0.0,
            'status' => $applied ? 'applied' : 'to_be_verified',
        ];
    }

    public static function emptyInfo(): array
    {
        return ['available' => [], 'applied_id' => null, 'applied_title' => null, 'promo_discount' => 0.0, 'status' => 'to_be_verified'];
    }

    /** The nightly rate of one room of $roomTypeId after this promo (never below zero; other room types are unchanged). */
    public function rateAfter($roomTypeId, float $rate): float
    {
        if (! $this->hasDiscount() || $this->discountsTotal() || ! $this->coversRoomType($roomTypeId)) {
            return round($rate, 2);
        }
        $off = $this->discount_type === 'percentage'
            ? $rate * min(100.0, (float) $this->discount_value) / 100
            : (float) $this->discount_value;

        return round(max(0.0, $rate - $off), 2);
    }

    /** The promo as the guest sees it and as it is remembered on a transaction (title, value, room type, valid until). */
    public function snapshotRow(): array
    {
        return [
            'id' => $this->id,
            'title' => $this->promo_name,
            'description' => $this->description,
            'discount_type' => $this->hasDiscount() ? $this->discount_type : null,
            'discount_value' => $this->hasDiscount() ? (float) $this->discount_value : null,
            'applies_to' => $this->discount_applies_to,
            'label' => $this->discountLabel(),
            'room_type_id' => $this->room_type_id,
            'room_type_name' => $this->roomType?->name,
            'valid_until' => $this->end_date?->toDateString(),
            'has_money_effect' => $this->hasDiscount() && ! $this->discountsTotal(),
        ];
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
