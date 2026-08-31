<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Restores the once-per-24-hours cooldown on a Room Type's main image
     * (the System Administrator confirmed they want it back after an
     * earlier turn removed it). Additive re-creation rather than trying to
     * reverse the removal migration, since that one already ran and this
     * keeps every migration a clean, linear step. See
     * RoomType::canChangeImage()/nextImageChangeDate().
     */
    public function up(): void
    {
        Schema::table('room_types', function (Blueprint $table) {
            $table->timestamp('image_changed_at')->nullable()->after('image');
        });
    }

    public function down(): void
    {
        Schema::table('room_types', function (Blueprint $table) {
            $table->dropColumn('image_changed_at');
        });
    }
};
