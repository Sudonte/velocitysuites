<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Guest capacity moves to room types as a range: min_capacity..capacity
 * (capacity stays the maximum, so existing readers and the API keep working).
 * rooms.room_capacity is no longer read by the app; it becomes nullable and
 * is kept only as a mirror of the type's maximum for API backward
 * compatibility. Existing per-room values are left untouched.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('room_types', function (Blueprint $table) {
            $table->unsignedSmallInteger('min_capacity')->default(1)->after('capacity');
        });

        Schema::table('rooms', function (Blueprint $table) {
            $table->integer('room_capacity')->nullable()->change();
        });
    }

    public function down(): void
    {
        Schema::table('room_types', function (Blueprint $table) {
            $table->dropColumn('min_capacity');
        });
    }
};
