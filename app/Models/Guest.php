<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

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
