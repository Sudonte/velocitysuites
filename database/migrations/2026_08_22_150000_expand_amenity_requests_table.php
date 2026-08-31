<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Adds the room/room-type/amenity-name snapshot fields and the
     * in_progress/completed statuses needed by the guest-facing amenity
     * request flow (Api\AmenityRequestController) and the Receptionist
     * Request Amenity module. amenity_name is backfilled from the live
     * catalog for existing rows (best-effort - those rows predate the
     * "never rewrite history" guarantee this column exists to provide
     * going forward). room_id/room_type_id are left null for existing
     * rows - they were never captured before, and can't be reconstructed
     * reliably after the fact.
     */
    public function up(): void
    {
        Schema::table('amenity_requests', function (Blueprint $table) {
            $table->foreignId('room_id')->nullable()->after('reservation_id')->constrained()->nullOnDelete();
            $table->foreignId('room_type_id')->nullable()->after('room_id')->constrained()->nullOnDelete();
            $table->string('amenity_name')->nullable()->after('amenity_id');
        });

        DB::statement("
            UPDATE amenity_requests ar
            JOIN amenities a ON a.id = ar.amenity_id
            SET ar.amenity_name = a.amenity_name
            WHERE ar.amenity_name IS NULL
        ");

        Schema::table('amenity_requests', function (Blueprint $table) {
            $table->string('amenity_name')->nullable(false)->change();
        });

        DB::statement("ALTER TABLE amenity_requests MODIFY status ENUM('pending','approved','in_progress','completed','rejected') NOT NULL DEFAULT 'pending'");
    }

    public function down(): void
    {
        DB::statement("ALTER TABLE amenity_requests MODIFY status ENUM('pending','approved','rejected') NOT NULL DEFAULT 'pending'");

        Schema::table('amenity_requests', function (Blueprint $table) {
            $table->dropConstrainedForeignId('room_id');
            $table->dropConstrainedForeignId('room_type_id');
            $table->dropColumn('amenity_name');
        });
    }
};
