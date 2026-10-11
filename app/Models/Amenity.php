<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class Amenity extends Model
{
    use \App\Models\Concerns\Archivable;

    /** Archive switches it off; restore brings it back off for the admin to re-enable. */
    public const ARCHIVED_STATUS = 'inactive';
    public const RESTORED_STATUS = 'inactive';

    use HasFactory;
    use SoftDeletes;

    protected $fillable = [
        'amenity_name',
        'description',
        'category',
        'quantity',
        'low_stock_threshold',
        'is_unlimited',
        'charge',
        'status',
    ];

    protected $casts = [
        'charge' => 'decimal:2',
        'is_unlimited' => 'boolean',
    ];

    /** Remaining stock reported for an Unlimited amenity - never runs out. */
    public const UNLIMITED_STOCK = PHP_INT_MAX;

    /** Quantity the mobile API reports for an Unlimited amenity (display only). */
    public const API_UNLIMITED_DISPLAY_QUANTITY = 999;

    /** Display text for stock: "Unlimited" or the configured quantity. */
    public function getStockLabelAttribute(): string
    {
        return $this->is_unlimited ? 'Unlimited' : (string) (int) $this->quantity;
    }

    public static function isUnlimitedStock(?int $remaining): bool
    {
        return $remaining === self::UNLIMITED_STOCK;
    }

    /**
     * Get the amenity requests for this amenity.
     */
    public function amenityRequests()
    {
        return $this->hasMany(AmenityRequest::class);
    }

    /**
     * Room types this amenity is directly assigned to as a type-wide
     * baseline (separate from, and in addition to, per-room assignment
     * via rooms() above).
     */
    public function roomTypes()
    {
        return $this->belongsToMany(RoomType::class, 'room_type_amenity')->withTimestamps();
    }

    public function isPaid(): bool
    {
        return (float) $this->charge > 0;
    }

    /**
     * Font Awesome icon for the public website (Home page + dedicated
     * Amenities page) - amenities have no image column, so this keyword
     * match against the admin-entered name is the display icon instead.
     * Checked in a specific-before-general order (e.g. "airport transfer"
     * before a bare "transfer"/"shuttle" fallback) since amenity_name is
     * free text the System Administrator can type as anything.
     */
    public function getIconAttribute(): string
    {
        $name = strtolower($this->amenity_name);

        $map = [
            'wifi' => 'fa-wifi',
            'internet' => 'fa-wifi',
            'parking' => 'fa-square-parking',
            'pool' => 'fa-person-swimming',
            'spa' => 'fa-spa',
            'gym' => 'fa-dumbbell',
            'fitness' => 'fa-dumbbell',
            'restaurant' => 'fa-utensils',
            'dining' => 'fa-utensils',
            'breakfast' => 'fa-mug-saucer',
            'room service' => 'fa-bell-concierge',
            'concierge' => 'fa-bell-concierge',
            'laundry' => 'fa-shirt',
            'airport' => 'fa-plane-departure',
            'transfer' => 'fa-van-shuttle',
            'shuttle' => 'fa-van-shuttle',
            'security' => 'fa-shield-halved',
            'extra bed' => 'fa-bed',
            'bed' => 'fa-bed',
            'pillow' => 'fa-bed',
            'blanket' => 'fa-bed',
            'towel' => 'fa-bath',
            'toiletries' => 'fa-bath',
            'tv' => 'fa-tv',
            'television' => 'fa-tv',
            'air condition' => 'fa-wind',
            'ac' => 'fa-wind',
        ];

        foreach ($map as $keyword => $icon) {
            if (str_contains($name, $keyword)) {
                return $icon;
            }
        }

        return 'fa-circle-check';
    }

    /**
     * How many of each amenity are actually still available right now:
     * the catalog's own configured `quantity` (total supply) minus every
     * request already made against it that wasn't rejected AND whose stay
     * hasn't checked out yet (a rejected request never took stock; a
     * checked-out stay has returned whatever it borrowed). Hotel-wide,
     * shared across every guest and every booking/reservation, not
     * scoped to one - the same reasoning room inventory already uses.
     * Shared between Receptionist\ReceptionistController's own request
     * flow and every guest-facing selection point (booking-time via
     * ReservationAmenityService::validateSelection(), post-check-in
     * top-ups via Api\AmenityRequestController) so none of them can
     * approve more of a shared amenity than the hotel actually has.
     * Unlimited amenities report UNLIMITED_STOCK, so no quantity is ever
     * refused for them.
     */
    public static function remainingStockFor(iterable $amenityIds): \Illuminate\Support\Collection
    {
        $requested = AmenityRequest::whereIn('amenity_id', $amenityIds)
            ->where('status', '!=', 'rejected')
            ->whereDoesntHave('booking', fn ($q) => $q->where('booking_status', Booking::STATUS_COMPLETED))
            ->whereDoesntHave('reservation.booking', fn ($q) => $q->where('booking_status', Booking::STATUS_COMPLETED))
            ->selectRaw('amenity_id, SUM(quantity) as used')
            ->groupBy('amenity_id')
            ->pluck('used', 'amenity_id');

        return static::whereIn('id', $amenityIds)
            ->get(['id', 'quantity', 'is_unlimited'])
            ->mapWithKeys(fn (self $amenity) => [
                $amenity->id => $amenity->is_unlimited
                    ? self::UNLIMITED_STOCK
                    : max(0, (int) $amenity->quantity - (int) ($requested[$amenity->id] ?? 0)),
            ]);
    }

    /** The remaining-stock level at which the admin is alerted. */
    public function lowStockLevel(): int
    {
        return $this->low_stock_threshold ?? (int) config('hotel.amenity_low_stock_default', 10);
    }

    /** 'unlimited' | 'out' | 'low' | 'ok' for a given remaining count. */
    public function stockState(int $remaining): string
    {
        if ($this->is_unlimited) {
            return 'unlimited';
        }

        return $remaining <= 0 ? 'out' : ($remaining <= $this->lowStockLevel() ? 'low' : 'ok');
    }

    /**
     * Alert the admin when an amenity's remaining stock is at/below its
     * low-stock level - at most once per amenity per day so a busy day
     * doesn't flood the bell.
     */
    public static function alertIfLowStock(int $amenityId): void
    {
        $amenity = static::find($amenityId);
        if (! $amenity || $amenity->is_unlimited) {
            return;
        }
        $remaining = (int) (static::remainingStockFor([$amenityId])[$amenityId] ?? 0);
        if ($amenity->stockState($remaining) === 'ok') {
            return;
        }
        if (! \Illuminate\Support\Facades\Cache::add("amenity_low_stock:{$amenityId}:" . today()->toDateString(), 1, now()->endOfDay())) {
            return;
        }
        app(\App\Services\NotificationService::class)->notifyAdmin(
            $remaining <= 0 ? "Out of Stock: {$amenity->amenity_name}" : "Low Stock: {$amenity->amenity_name}",
            $remaining <= 0
                ? "{$amenity->amenity_name} has no units left - guests can't request it until stock is added."
                : "Only {$remaining} of {$amenity->quantity} {$amenity->amenity_name} left (alert level {$amenity->lowStockLevel()}).",
            'amenity'
        );
    }
}
