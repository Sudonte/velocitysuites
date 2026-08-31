<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Amenities move from per-Room-Type assignment to per-individual-Room
     * assignment (each physical room, e.g. "Room 204", now gets its own
     * amenities list instead of sharing one list with every room of its
     * type) - per an explicit product decision. Also adds
     * complimentary/paid pricing to the amenity catalog itself.
     */
    public function up(): void
    {
        Schema::table('room_features', function (Blueprint $table) {
            $table->enum('pricing_type', ['complimentary', 'paid'])->default('complimentary')->after('status');
            $table->decimal('fee', 10, 2)->nullable()->after('pricing_type');
        });

        Schema::create('room_room_feature', function (Blueprint $table) {
            $table->id();
            $table->foreignId('room_id')->constrained()->cascadeOnDelete();
            $table->foreignId('room_feature_id')->constrained()->cascadeOnDelete();
            $table->timestamps();
            $table->unique(['room_id', 'room_feature_id']);
        });

        // Carry forward existing per-type assignments onto every physical
        // room of that type, so real data already assigned isn't silently
        // lost - the admin can then customize per room afterward.
        if (Schema::hasTable('room_type_room_feature')) {
            $assignments = DB::table('room_type_room_feature')->get(['room_type_id', 'room_feature_id']);
            $now = now();
            foreach ($assignments as $assignment) {
                $roomIds = DB::table('rooms')->where('room_type_id', $assignment->room_type_id)->pluck('id');
                foreach ($roomIds as $roomId) {
                    DB::table('room_room_feature')->insertOrIgnore([
                        'room_id' => $roomId,
                        'room_feature_id' => $assignment->room_feature_id,
                        'created_at' => $now,
                        'updated_at' => $now,
                    ]);
                }
            }

            Schema::dropIfExists('room_type_room_feature');
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('room_room_feature');

        Schema::create('room_type_room_feature', function (Blueprint $table) {
            $table->id();
            $table->foreignId('room_type_id')->constrained()->cascadeOnDelete();
            $table->foreignId('room_feature_id')->constrained()->cascadeOnDelete();
            $table->timestamps();
            $table->unique(['room_type_id', 'room_feature_id']);
        });

        Schema::table('room_features', function (Blueprint $table) {
            $table->dropColumn(['pricing_type', 'fee']);
        });
    }
};
