<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Promos can now carry a discount alongside their free amenities, and any discount_type/discount_value on a
 * promotion is billed. Until now an amenity promo never used those columns, but one converted from an old
 * discount promo (the edit form never cleared them) could still hold a value - clear them so no existing
 * amenity promo starts discounting on its own. Old discount-only promos keep theirs (they are inactive).
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::table('promotions')->where('promo_type', 'amenity')->update(['discount_type' => null, 'discount_value' => null]);
    }

    public function down(): void
    {
        // Nothing to restore - the cleared values were never used.
    }
};
