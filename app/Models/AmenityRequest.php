<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class AmenityRequest extends Model
{
    use HasFactory;

    protected $fillable = [
        'guest_id',
        'amenity_id',
        'quantity',
        'charge',
        'status',
    ];

    protected $casts = [
        'charge' => 'decimal:2',
    ];

    /**
     * Get the guest associated with the amenity request.
     */
    public function guest()
    {
        return $this->belongsTo(Guest::class);
    }

    /**
     * Get the amenity associated with the request.
     */
    public function amenity()
    {
        return $this->belongsTo(Amenity::class);
    }
}
