<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Booking becomes the sole operational record from conversion through
     * checkout - it gets its own copy of room_type/dates/guest counts (taken
     * at conversion time, since Reservation is frozen/historical afterward)
     * plus room_id, which now stays null until check-in assigns it.
     *
     * Under the old model a Booking row was created for EVERY reservation
     * immediately at submission (booking_status started at 'pending'). Under
     * the new model a Booking row only ever exists for a converted
     * reservation, so legacy rows whose reservation didn't end up
     * 'converted' (add_workflow_fields_to_reservations_table already ran)
     * are deleted here rather than restructured - they were never real
     * bookings under the new definition.
     */
    public function up(): void
    {
        DB::table('bookings')
            ->join('reservations', 'reservations.id', '=', 'bookings.reservation_id')
            ->where('reservations.status', '!=', 'converted')
            ->delete();

        Schema::table('bookings', function (Blueprint $table) {
            $table->foreignId('room_type_id')->nullable()->after('reservation_id')->constrained('room_types');
            $table->foreignId('room_id')->nullable()->after('room_type_id')->constrained('rooms');
            $table->dateTime('check_in')->nullable()->after('room_id');
            $table->dateTime('check_out')->nullable()->after('check_in');
            $table->integer('adults')->nullable()->after('check_out');
            $table->integer('children')->nullable()->after('adults');
            $table->integer('number_of_guests')->nullable()->after('children');
            $table->timestamp('confirmed_at')->nullable()->after('number_of_guests');
        });

        // Backfill the surviving (converted) bookings from their reservation,
        // which still has room_id/dates/guests at this point in the
        // migration sequence.
        DB::statement('
            UPDATE bookings
            JOIN reservations ON reservations.id = bookings.reservation_id
            SET bookings.room_type_id = reservations.room_type_id,
                bookings.room_id = reservations.room_id,
                bookings.check_in = reservations.check_in,
                bookings.check_out = reservations.check_out,
                bookings.adults = reservations.adults,
                bookings.children = reservations.children,
                bookings.number_of_guests = reservations.number_of_guests,
                bookings.confirmed_at = bookings.created_at
        ');

        Schema::table('bookings', function (Blueprint $table) {
            $table->foreignId('room_type_id')->nullable(false)->change();
        });

        Schema::table('bookings', function (Blueprint $table) {
            $table->dropColumn('booking_date');
        });
    }

    public function down(): void
    {
        Schema::table('bookings', function (Blueprint $table) {
            $table->date('booking_date')->nullable()->after('reservation_id');
        });
        DB::statement('UPDATE bookings SET booking_date = DATE(confirmed_at)');

        Schema::table('bookings', function (Blueprint $table) {
            $table->dropForeign(['room_type_id']);
            $table->dropForeign(['room_id']);
            $table->dropColumn([
                'room_type_id',
                'room_id',
                'check_in',
                'check_out',
                'adults',
                'children',
                'number_of_guests',
                'confirmed_at',
            ]);
        });
    }
};
