<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Explicit quantity mode for amenities: Limited (quantity is the stock,
 * existing behavior) or Unlimited (never runs out). Every existing amenity
 * stays Limited.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('amenities', function (Blueprint $table) {
            $table->boolean('is_unlimited')->default(false)->after('quantity');
        });
    }

    public function down(): void
    {
        Schema::table('amenities', function (Blueprint $table) {
            $table->dropColumn('is_unlimited');
        });
    }
};
