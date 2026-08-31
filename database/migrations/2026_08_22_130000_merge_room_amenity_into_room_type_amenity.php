<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Amenities move from dual-level (independently editable per Room AND
     * per Room Type) to Room Type-only, per an explicit System
     * Administrator requirement: an individual room can never carry its
     * own independent amenity assignment, it always reflects exactly what
     * its type has (Room::getAmenitiesAttribute() becomes a pure
     * passthrough to RoomType::amenities after this). Before dropping the
     * per-room pivot, backfill: for every (room_id, amenity_id) currently
     * assigned directly to a room, insert the equivalent
     * (room_type_id, amenity_id) into room_type_amenity - so an amenity a
     * room used to show, that its type didn't already have, doesn't
     * silently disappear. insertOrIgnore + the pivot's existing unique
     * constraint handles de-duplication automatically.
     */
    public function up(): void
    {
        if (Schema::hasTable('room_amenity')) {
            $now = now();
            $assignments = DB::table('room_amenity')
                ->join('rooms', 'rooms.id', '=', 'room_amenity.room_id')
                ->select('rooms.room_type_id', 'room_amenity.amenity_id')
                ->distinct()
                ->get();

            foreach ($assignments as $assignment) {
                DB::table('room_type_amenity')->insertOrIgnore([
                    'room_type_id' => $assignment->room_type_id,
                    'amenity_id' => $assignment->amenity_id,
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);
            }

            Schema::dropIfExists('room_amenity');
        }
    }

    public function down(): void
    {
        Schema::create('room_amenity', function ($table) {
            $table->id();
            $table->foreignId('room_id')->constrained()->cascadeOnDelete();
            $table->foreignId('amenity_id')->constrained()->cascadeOnDelete();
            $table->timestamps();
            $table->unique(['room_id', 'amenity_id']);
        });

        // Backfill is intentionally not reversed - the merge is a one-way
        // consolidation (per-room assignments were absorbed into their
        // type), not a recoverable split.
    }
};
