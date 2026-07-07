<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('reservations', function (Blueprint $table) {
            $table->foreignId('room_type_id')->nullable()->after('guest_id')->constrained('room_types');
        });

        // Backfill from each reservation's currently assigned room's type.
        DB::statement('
            UPDATE reservations
            JOIN rooms ON rooms.id = reservations.room_id
            SET reservations.room_type_id = rooms.room_type_id
        ');

        Schema::table('reservations', function (Blueprint $table) {
            $table->foreignId('room_type_id')->nullable(false)->change();
            // room_id becomes nullable: pending reservations have no room
            // until the receptionist assigns one at confirmation time.
            $table->foreignId('room_id')->nullable()->change();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('reservations', function (Blueprint $table) {
            $table->foreignId('room_id')->nullable(false)->change();
            $table->dropConstrainedForeignId('room_type_id');
        });
    }
};
