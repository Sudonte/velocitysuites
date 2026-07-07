<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Amenity extends Model
{
    use HasFactory;

    protected $fillable = [
        'amenity_name',
        'description',
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
}
