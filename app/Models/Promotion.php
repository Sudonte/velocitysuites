<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Storage;

class Promotion extends Model
{
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
        'status',
    ];

    protected $casts = [
        'start_date' => 'date',
        'end_date' => 'date',
        'discount_value' => 'decimal:2',
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
        $today = now()->startOfDay();

        return $this->status === 'active'
            && $this->start_date <= $today
            && $this->end_date >= $today;
    }
}
