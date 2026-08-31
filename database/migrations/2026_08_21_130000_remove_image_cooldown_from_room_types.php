<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * The System Administrator reported the 24-hour cooldown on a Room
     * Type's main image as an unwanted restriction blocking continuous
     * image management - removes it outright rather than just not
     * triggering it. See RoomType::canChangeImage() (now deleted) and
     * RoomTypeManagementController::update()/removeImage() (now
     * ungated).
     */
    public function up(): void
    {
        Schema::table('room_types', function (Blueprint $table) {
            $table->dropColumn('image_changed_at');
        });
    }

    public function down(): void
    {
        Schema::table('room_types', function (Blueprint $table) {
            $table->timestamp('image_changed_at')->nullable()->after('image');
        });
    }
};
