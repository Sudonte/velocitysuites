<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Room amenities are no longer a separate catalog (room_features) -
     * per an explicit product decision, room/room-type amenity assignment
     * now reuses the single pre-existing Amenities module (`amenities`
     * table / Admin\AmenityManagementController) instead of a parallel
     * one. This migration creates the room/room-type assignment pivots
     * against `amenities`, carries every existing room_features row
     * forward into `amenities` (charge 0, since every one of them was
     * complimentary at the time of this migration) plus its real
     * assignments, then drops the room_features tables entirely.
     */
    public function up(): void
    {
        Schema::create('room_amenity', function (Blueprint $table) {
            $table->id();
            $table->foreignId('room_id')->constrained()->cascadeOnDelete();
            $table->foreignId('amenity_id')->constrained()->cascadeOnDelete();
            $table->timestamps();
            $table->unique(['room_id', 'amenity_id']);
        });

        Schema::create('room_type_amenity', function (Blueprint $table) {
            $table->id();
            $table->foreignId('room_type_id')->constrained()->cascadeOnDelete();
            $table->foreignId('amenity_id')->constrained()->cascadeOnDelete();
            $table->timestamps();
            $table->unique(['room_type_id', 'amenity_id']);
        });

        if (Schema::hasTable('room_features')) {
            $now = now();
            $featureIdToAmenityId = [];

            foreach (DB::table('room_features')->get() as $feature) {
                $existing = DB::table('amenities')->whereRaw('LOWER(amenity_name) = ?', [strtolower($feature->name)])->first();

                if ($existing) {
                    $featureIdToAmenityId[$feature->id] = $existing->id;
                    continue;
                }

                $featureIdToAmenityId[$feature->id] = DB::table('amenities')->insertGetId([
                    'amenity_name' => $feature->name,
                    'description' => $feature->description,
                    'quantity' => 999,
                    'charge' => $feature->pricing_type === 'paid' ? $feature->fee : 0,
                    'status' => $feature->status,
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);
            }

            if (Schema::hasTable('room_room_feature')) {
                foreach (DB::table('room_room_feature')->get() as $assignment) {
                    if (! isset($featureIdToAmenityId[$assignment->room_feature_id])) {
                        continue;
                    }
                    DB::table('room_amenity')->insertOrIgnore([
                        'room_id' => $assignment->room_id,
                        'amenity_id' => $featureIdToAmenityId[$assignment->room_feature_id],
                        'created_at' => $now,
                        'updated_at' => $now,
                    ]);
                }
                Schema::dropIfExists('room_room_feature');
            }

            if (Schema::hasTable('room_type_room_feature')) {
                foreach (DB::table('room_type_room_feature')->get() as $assignment) {
                    if (! isset($featureIdToAmenityId[$assignment->room_feature_id])) {
                        continue;
                    }
                    DB::table('room_type_amenity')->insertOrIgnore([
                        'room_type_id' => $assignment->room_type_id,
                        'amenity_id' => $featureIdToAmenityId[$assignment->room_feature_id],
                        'created_at' => $now,
                        'updated_at' => $now,
                    ]);
                }
                Schema::dropIfExists('room_type_room_feature');
            }

            Schema::dropIfExists('room_features');
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('room_type_amenity');
        Schema::dropIfExists('room_amenity');
    }
};
