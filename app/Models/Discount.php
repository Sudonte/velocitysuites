<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * An authorized discount (Senior Citizen, PWD, Student, etc.) that a
 * receptionist applies manually at billing time, after verifying a
 * guest's uploaded ID. Genuinely separate from Promotion - no shared
 * logic or records.
 */
class Discount extends Model
{
    use HasFactory;

    protected $fillable = [
        'name',
        'discount_type',
        'value',
        'description',
        'status',
    ];

    protected $casts = [
        'value' => 'decimal:2',
    ];

    public function billings()
    {
        return $this->hasMany(Billing::class);
    }
}
