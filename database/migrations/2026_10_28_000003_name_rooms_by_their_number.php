<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * A room is known by its number - the separate Room Name field is gone from Add Rooms and Edit Room. Existing rooms
 * get the same "Room <number>" the app now gives new ones, so notifications and the mobile app (room_name) read
 * the same everywhere. The column stays (the API still sends it).
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::table('rooms')->select('id', 'room_number')->orderBy('id')->each(function ($room) {
            DB::table('rooms')->where('id', $room->id)->update(['room_name' => 'Room ' . $room->room_number]);
        });
    }

    public function down(): void
    {
        // The old names were free text - nothing to restore.
    }
};
