<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Storage;

class Guest extends Model
{
    use HasFactory;

    protected $fillable = [
        'user_id',
        'age',
        'gender',
        'date_of_birth',
        'mobile_number',
        'address',
        'profile_picture',
    ];

    protected $casts = [
        'date_of_birth' => 'date',
    ];

    protected $appends = [
        'profile_picture_url',
    ];

    /**
     * Full public URL for the stored profile_picture path, since API
     * consumers (the mobile app) need something they can load directly
     * rather than a bare storage-relative path.
     */
    public function getProfilePictureUrlAttribute(): ?string
    {
        return $this->profile_picture ? Storage::disk('public')->url($this->profile_picture) : null;
    }

    /**
     * Get the user associated with the guest.
     */
    public function user()
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Get the reservations for the guest.
     */
    public function reservations()
    {
        return $this->hasMany(Reservation::class);
    }

    /**
     * Get the amenity requests for the guest.
     */
    public function amenityRequests()
    {
        return $this->hasMany(AmenityRequest::class);
    }
}
