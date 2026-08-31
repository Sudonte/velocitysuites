<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * A room type's main image is now a real, authoritative column
     * instead of being guessed at read-time from "any individual room of
     * this type that happens to have an image" - that exact hack was
     * copy-pasted across PublicRoomController::index(), LandingPageController,
     * and Api\RoomController::index() (each independently picking a
     * possibly-different "representative" room), plus a fourth variant in
     * public/rooms/show.blade.php's gallery-first-image - four ways to
     * disagree with each other, which is the actual inconsistency bug this
     * column fixes. Backfills from that same old logic once, for
     * continuity, so a type that already had a de-facto image via one of
     * its rooms doesn't go blank the moment this ships - going forward the
     * column is the single source of truth, set only via
     * Admin\RoomTypeManagementController's Edit/Create Type forms.
     */
    public function up(): void
    {
        Schema::table('room_types', function (Blueprint $table) {
            $table->string('image')->nullable()->after('description');
        });

        DB::statement('
            UPDATE room_types
            SET image = (
                SELECT image FROM rooms
                WHERE rooms.room_type_id = room_types.id AND rooms.image IS NOT NULL
                LIMIT 1
            )
        ');
    }

    public function down(): void
    {
        Schema::table('room_types', function (Blueprint $table) {
            $table->dropColumn('image');
        });
    }
};
