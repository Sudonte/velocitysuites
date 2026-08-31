<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Room features are the free descriptive amenities list an admin can
     * optionally assign per room type (e.g. "Air Conditioning", "Free
     * WiFi") - distinct from the pre-existing `amenities` table, which is
     * an admin-managed catalog of paid add-on services guests attach
     * during booking (has charge/quantity). Pivot mirrors the existing
     * `promotion_amenity` table's shape, minus the quantity column (not
     * needed here - a room type either has a feature or it doesn't).
     */
    public function up(): void
    {
        Schema::create('room_features', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->enum('status', ['active', 'inactive'])->default('active');
            $table->timestamps();
        });

        Schema::create('room_type_room_feature', function (Blueprint $table) {
            $table->id();
            $table->foreignId('room_type_id')->constrained()->cascadeOnDelete();
            $table->foreignId('room_feature_id')->constrained()->cascadeOnDelete();
            $table->timestamps();
            $table->unique(['room_type_id', 'room_feature_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('room_type_room_feature');
        Schema::dropIfExists('room_features');
    }
};
