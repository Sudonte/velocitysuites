<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
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
        'country',
        'region',
        'province',
        'city',
        'barangay',
        'street',
        'zip_code',
        'timezone',
        'profile_picture',
        'profile_last_updated_at',
    ];

    protected $casts = [
        'date_of_birth' => 'date',
        'profile_last_updated_at' => 'datetime',
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
     * True if Personal Information / Contact & Address has never been
     * updated, or the last successful update was 30+ days ago. Mirrors
     * User::canChangeProfilePicture()'s convention exactly, applied to
     * profile_last_updated_at instead of profile_picture_changed_at -
     * this is a rolling 30-day window, not the old permanent one-time
     * profile_edit_used flag (which was never actually wired up anywhere
     * in the codebase and is left untouched/unused).
     */
    public function canUpdateProfile(): bool
    {
        return $this->profile_last_updated_at === null
            || $this->profile_last_updated_at->addDays(30)->isPast();
    }

    /** Null once editable again; otherwise the exact date the 30-day cooldown lifts. */
    public function nextProfileUpdateDate(): ?Carbon
    {
        if ($this->canUpdateProfile()) {
            return null;
        }

        return $this->profile_last_updated_at->copy()->addDays(30);
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
