<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Both historical-snapshot tables (booking-time selection and
     * post-booking requests) gain a category snapshot alongside the
     * existing amenity_name/charge snapshots - same "frozen at the
     * moment of the transaction" guarantee applies: a later admin
     * recategorization must not rewrite what a past booking/request
     * showed. Existing rows are backfilled from the live catalog
     * (best-effort - the true category at their original transaction
     * time isn't recoverable, same caveat as the amenity_name backfill
     * in 2026_08_22_150000).
     */
    public function up(): void
    {
        Schema::table('reservation_amenities', function (Blueprint $table) {
            $table->string('category')->nullable()->after('amenity_name');
        });
        Schema::table('amenity_requests', function (Blueprint $table) {
            $table->string('category')->nullable()->after('amenity_name');
        });

        DB::statement("
            UPDATE reservation_amenities ra
            JOIN amenities a ON a.id = ra.amenity_id
            SET ra.category = a.category
            WHERE ra.category IS NULL
        ");

        DB::statement("
            UPDATE amenity_requests ar
            JOIN amenities a ON a.id = ar.amenity_id
            SET ar.category = a.category
            WHERE ar.category IS NULL
        ");
    }

    public function down(): void
    {
        Schema::table('reservation_amenities', function (Blueprint $table) {
            $table->dropColumn('category');
        });
        Schema::table('amenity_requests', function (Blueprint $table) {
            $table->dropColumn('category');
        });
    }
};
