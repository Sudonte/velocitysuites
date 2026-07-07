<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Promotion extends Model
{
    use HasFactory;

    protected $fillable = [
        'promo_name',
        'discount_type',
        'discount_value',
        'description',
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
     * Get the room type this promotion targets (null = all types).
     */
    public function roomType()
    {
        return $this->belongsTo(RoomType::class);
    }

    /**
     * Check if promotion is currently active.
     */
    public function getIsActiveAttribute()
    {
        $today = now()->toDateString();
        return $this->status === 'active' && 
               $this->start_date <= $today && 
               $this->end_date >= $today;
    }
}
