<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Re-adds a Category field to the centralized Amenities catalog - it
     * briefly existed on the now-deleted room_features table but was never
     * carried over when room_features was merged into amenities
     * (2026_08_20_200000_merge_room_features_into_amenities.php).
     */
    public function up(): void
    {
        Schema::table('amenities', function (Blueprint $table) {
            $table->string('category')->nullable()->after('description');
        });
    }

    public function down(): void
    {
        Schema::table('amenities', function (Blueprint $table) {
            $table->dropColumn('category');
        });
    }
};
