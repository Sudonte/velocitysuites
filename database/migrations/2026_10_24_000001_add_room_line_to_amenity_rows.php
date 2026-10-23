<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Booking-time amenities are chosen per room, so the amenity rows can say which booked room they are for: `room_line_id` is the room line
 * (booking_room_lines for a booking's rows, reservation_room_lines for a reservation's) and `room_slot` which room of that line (1..quantity).
 * Both stay NULL for every existing row and for an amenity booked for the whole stay - those keep showing under the stay exactly as before.
 * Additive only: two nullable columns on two tables.
 */
return new class extends Migration
{
    public function up(): void
    {
        foreach (['amenity_requests', 'reservation_amenities'] as $table) {
            if (! Schema::hasTable($table)) {
                continue;
            }
            Schema::table($table, function (Blueprint $t) use ($table) {
                if (! Schema::hasColumn($table, 'room_line_id')) {
                    $t->unsignedBigInteger('room_line_id')->nullable()->index();
                }
                if (! Schema::hasColumn($table, 'room_slot')) {
                    $t->unsignedSmallInteger('room_slot')->nullable();
                }
            });
        }
    }

    public function down(): void
    {
        foreach (['amenity_requests', 'reservation_amenities'] as $table) {
            if (! Schema::hasTable($table)) {
                continue;
            }
            Schema::table($table, function (Blueprint $t) use ($table) {
                if (Schema::hasColumn($table, 'room_line_id')) {
                    $t->dropIndex(['room_line_id']);
                    $t->dropColumn('room_line_id');
                }
                if (Schema::hasColumn($table, 'room_slot')) {
                    $t->dropColumn('room_slot');
                }
            });
        }
    }
};
