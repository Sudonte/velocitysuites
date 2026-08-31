<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Amenities gain Description/Category fields, and assignment becomes
     * dual-level: a room type can carry its own baseline amenities
     * (room_type_room_feature, recreated - dropped in an earlier round
     * when assignment was per-room-only) in addition to the existing
     * per-room assignment (room_room_feature). A room type's guest-facing
     * amenities list is the union of both.
     */
    public function up(): void
    {
        Schema::table('room_features', function (Blueprint $table) {
            $table->text('description')->nullable()->after('name');
            $table->string('category')->nullable()->after('description');
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

        Schema::table('room_features', function (Blueprint $table) {
            $table->dropColumn(['description', 'category']);
        });
    }
};
