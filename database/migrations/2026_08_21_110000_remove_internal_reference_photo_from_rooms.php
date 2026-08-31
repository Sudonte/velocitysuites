<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;

return new class extends Migration
{
    /**
     * Removes the per-room "Internal Reference Photo". Every room of a
     * type already shows that type's single main image everywhere
     * (RoomType::getImageUrlAttribute()) - this second, room-scoped image
     * was pure duplication with no guest-facing use, and confused admins
     * into thinking it was the photo guests would see.
     */
    public function up(): void
    {
        $rooms = DB::table('rooms')->whereNotNull('image')->get(['id', 'image']);
        foreach ($rooms as $room) {
            Storage::disk('public')->delete($room->image);
        }

        Schema::table('rooms', function (Blueprint $table) {
            $table->dropColumn('image');
        });
    }

    public function down(): void
    {
        Schema::table('rooms', function (Blueprint $table) {
            $table->string('image')->nullable()->after('status');
        });
    }
};
