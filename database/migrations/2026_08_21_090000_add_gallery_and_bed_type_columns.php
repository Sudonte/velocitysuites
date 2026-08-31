<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Real gallery ordering (so "replace preserves position" and
     * "1st-5th image" have something concrete to mean) plus a per-room-type
     * bed configuration field, both previously missing entirely.
     */
    public function up(): void
    {
        Schema::table('room_images', function (Blueprint $table) {
            $table->unsignedInteger('sort_order')->default(0)->after('image_path');
        });

        Schema::table('room_types', function (Blueprint $table) {
            $table->string('bed_type')->nullable()->after('capacity');
        });
    }

    public function down(): void
    {
        Schema::table('room_images', function (Blueprint $table) {
            $table->dropColumn('sort_order');
        });

        Schema::table('room_types', function (Blueprint $table) {
            $table->dropColumn('bed_type');
        });
    }
};
