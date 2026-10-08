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

    /**
     * Senior Citizen and PWD are statutory discounts (RA 9994 / RA 10754: 20%). Only these two are
     * pre-applied to a reservation's quote while the ID awaits verification; every other discount is
     * applied by the receptionist at billing. The PERCENTAGE itself is never written down in code - it is
     * always this row's own value, edited in the Admin Discount module.
     */
    public function isStatutory(): bool
    {
        return in_array(strtolower(trim((string) $this->name)), ['senior citizen', 'pwd', 'person with disability', 'persons with disability'], true);
    }

    /** The peso amount this discount takes off $base (percentage of it, or the fixed value), never more than $base. */
    public function amountOff(float $base): float
    {
        $amount = $this->discount_type === 'percentage'
            ? round($base * (float) $this->value / 100, 2)
            : (float) $this->value;

        return round(max(0, min($amount, $base)), 2);
    }
}
