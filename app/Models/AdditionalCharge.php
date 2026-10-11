<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class AdditionalCharge extends Model
{
    use HasFactory;

    protected $fillable = [
        'billing_id',
        'description',
        'amount',
        'category',
        'notes',
    ];

    protected $casts = [
        'amount' => 'decimal:2',
    ];

    /**
     * Get the billing associated with the additional charge.
     */
    public function billing()
    {
        return $this->belongsTo(Billing::class);
    }

    /**
     * Get the category label.
     */
    /**
     * The description is optional - fall back to the category name so
     * logs and guest notifications always say what the charge was.
     */
    public function getLabelAttribute(): string
    {
        $description = trim((string) $this->description);

        return $description !== '' ? $description : $this->category_label;
    }

    public function getCategoryLabelAttribute(): string
    {
        return match ($this->category) {
            'damage' => 'Damage',
            'lost_item' => 'Lost Item',
            'broken_equipment' => 'Broken Equipment',
            'mini_bar' => 'Mini Bar',
            'laundry' => 'Laundry',
            'other' => 'Other',
            default => 'Other',
        };
    }

    /**
     * Available categories for additional charges.
     */
    public static function categories(): array
    {
        return [
            'damage' => 'Damage',
            'lost_item' => 'Lost Item',
            'broken_equipment' => 'Broken Equipment',
            'mini_bar' => 'Mini Bar',
            'laundry' => 'Laundry',
            'other' => 'Other',
        ];
    }
}