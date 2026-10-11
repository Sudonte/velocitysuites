<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Per-amenity low-stock alert level. Null = the default from
 * config('hotel.amenity_low_stock_default') (10).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('amenities', function (Blueprint $table) {
            $table->unsignedInteger('low_stock_threshold')->nullable()->after('quantity');
        });
    }

    public function down(): void
    {
        Schema::table('amenities', function (Blueprint $table) {
            $table->dropColumn('low_stock_threshold');
        });
    }
};
