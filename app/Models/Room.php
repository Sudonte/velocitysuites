<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Room extends Model
{
    use HasFactory;

    protected $fillable = [
        'room_number',
        'room_name',
        'room_type',
        'room_rate',
        'room_capacity',
        'description',
        'status',
        'image',
    ];

    protected $casts = [
        'room_rate' => 'decimal:2',
    ];

    /**
     * Get the images for the room.
     */
    public function images()
    {
        return $this->hasMany(RoomImage::class);
    }

    /**
     * Get the reservations for the room.
     */
    public function reservations()
    {
        return $this->hasMany(Reservation::class);
    }
}
