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
        'start_date',
        'end_date',
    ];

    protected $casts = [
        'value' => 'decimal:2',
        'start_date' => 'date:Y-m-d',
        'end_date' => 'date:Y-m-d',
    ];

    /**
     * Is this discount offered on the given day (default: today, hotel time)? An empty start or end date means no limit
     * on that side. Dates are inclusive calendar days.
     */
    public function isValidOn(?\Carbon\Carbon $day = null): bool
    {
        $day = ($day ?? \App\Support\CheckInWindow::today())->toDateString();
        $start = $this->start_date?->toDateString();
        $end = $this->end_date?->toDateString();

        return ($start === null || $day >= $start) && ($end === null || $day <= $end);
    }

    /** Active AND valid on the day - what a guest may newly pick. */
    public function scopeOffered($query, ?\Carbon\Carbon $day = null)
    {
        $day = ($day ?? \App\Support\CheckInWindow::today())->toDateString();

        return $query->where('status', 'active')
            ->where(fn ($q) => $q->whereNull('start_date')->orWhere('start_date', '<=', $day))
            ->where(fn ($q) => $q->whereNull('end_date')->orWhere('end_date', '>=', $day));
    }

    /** Human text: "No expiry", "Valid until Dec 31, 2026", "Valid from Oct 1, 2026", "Valid Oct 1, 2026 - Dec 31, 2026". */
    public function validityLabel(): string
    {
        $start = $this->start_date?->format('M j, Y');
        $end = $this->end_date?->format('M j, Y');

        return match (true) {
            $start && $end => "Valid {$start} - {$end}",
            (bool) $end => "Valid until {$end}",
            (bool) $start => "Valid from {$start}",
            default => 'No expiry',
        };
    }

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
