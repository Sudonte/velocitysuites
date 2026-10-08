<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Senior Citizen and PWD discounts are 20% by law (RA 9994 and RA 10754). The Discount module is the single
 * source of truth for the value, so its rows must say 20 - they said 10 while the pricing code quietly used a
 * hardcoded 20 instead. Percentage rows only, matched by name; idempotent. Not reversed on rollback (there is
 * no meaningful "previous legal rate").
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::table('discounts')
            ->whereIn(DB::raw('LOWER(TRIM(name))'), ['senior citizen', 'pwd'])
            ->where('discount_type', 'percentage')
            ->where('value', '!=', 20)
            ->update(['value' => 20, 'updated_at' => now()]);
    }

    public function down(): void
    {
    }
};
