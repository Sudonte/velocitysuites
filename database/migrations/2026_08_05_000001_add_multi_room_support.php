<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Real multi-room-per-booking support. `rooms_requested` is declared at
     * reservation time (so availability counting reserves the right amount
     * of inventory from the moment of booking, not just after check-in) and
     * copied onto the Booking at conversion, same as adults/children/etc.
     * `booking_rooms` is the specific-room assignment list, populated at
     * check-in - `bookings.room_id` stays as-is (first/primary room) for
     * the many display-only call sites that only need "the room", kept in
     * sync as the first assigned room for backward compatibility.
     */
    public function up(): void
    {
        Schema::table('reservations', function (Blueprint $table) {
            $table->unsignedTinyInteger('rooms_requested')->default(1)->after('room_type_id');
        });

        Schema::table('bookings', function (Blueprint $table) {
            $table->unsignedTinyInteger('rooms_requested')->default(1)->after('room_type_id');
        });

        Schema::create('booking_rooms', function (Blueprint $table) {
            $table->id();
            $table->foreignId('booking_id')->constrained('bookings')->cascadeOnDelete();
            $table->foreignId('room_id')->constrained('rooms');
            $table->timestamps();
            $table->unique(['booking_id', 'room_id']);
        });

        // Every already-checked-in (or checked-out) booking's single
        // room_id becomes its first booking_rooms row, so all downstream
        // code can uniformly read $booking->rooms going forward.
        DB::statement('
            INSERT INTO booking_rooms (booking_id, room_id, created_at, updated_at)
            SELECT id, room_id, NOW(), NOW() FROM bookings WHERE room_id IS NOT NULL
        ');
    }

    public function down(): void
    {
        Schema::dropIfExists('booking_rooms');

        Schema::table('bookings', function (Blueprint $table) {
            $table->dropColumn('rooms_requested');
        });

        Schema::table('reservations', function (Blueprint $table) {
            $table->dropColumn('rooms_requested');
        });
    }
};
