<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class Amenity extends Model
{
    use HasFactory;
    use SoftDeletes;

    protected $fillable = [
        'amenity_name',
        'description',
        'category',
        'quantity',
        'charge',
        'status',
    ];

    protected $casts = [
        'charge' => 'decimal:2',
    ];

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
}
