<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class RoomType extends Model
{
    use HasFactory;

    protected $fillable = [
        'name',
        'rate',
        'capacity',
        'status',
    ];

    protected $casts = [
        'rate' => 'decimal:2',
    ];

    /**
     * Get the physical rooms of this type.
     */
    public function rooms()
    {
        return $this->hasMany(Room::class);
    }

    /**
     * Get the reservations requesting this type.
     */
    public function reservations()
    {
        return $this->hasMany(Reservation::class);
    }
}
