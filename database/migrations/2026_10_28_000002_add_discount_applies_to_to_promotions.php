<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * What a promo's discount comes off: 'rate' - the nightly rate of rooms of its room type (every room when it is
 * for all types) - or 'total' - the stay's total from BEFORE check-in (rooms + the amenities booked up to check-in;
 * never the charges added during the stay or at check-out). Existing promos keep the room-rate behaviour.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('promotions', function (Blueprint $table) {
            $table->string('discount_applies_to', 10)->default('rate')->after('discount_value');
        });
    }

    public function down(): void
    {
        Schema::table('promotions', function (Blueprint $table) {
            $table->dropColumn('discount_applies_to');
        });
    }
};
