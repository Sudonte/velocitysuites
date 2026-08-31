<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Lets the System Administrator delete an amenity from the catalog
     * safely - soft-delete rather than a hard delete, since
     * amenity_requests.amenity_id currently cascades on delete and would
     * otherwise silently destroy historical request records. Soft-delete
     * never triggers that cascade (the row is never actually removed), and
     * every existing Amenity::where(...) query in the app automatically
     * excludes soft-deleted rows via Eloquent's global scope with zero
     * other code changes needed.
     */
    public function up(): void
    {
        Schema::table('amenities', function (Blueprint $table) {
            $table->softDeletes();
        });
    }

    public function down(): void
    {
        Schema::table('amenities', function (Blueprint $table) {
            $table->dropSoftDeletes();
        });
    }
};
