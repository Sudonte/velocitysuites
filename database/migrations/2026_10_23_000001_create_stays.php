<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * One booking or reservation is ONE transaction that can hold several stays, each with its own dates and rooms. A stay is a row here;
 * the room lines, the physical rooms (booking_rooms) and the amenity rows say which stay they belong to through stay_id.
 *
 * Additive: every existing booking and reservation gets exactly one stay (its own check-in / check-out), and everything that belongs to
 * it is pointed at that stay, so nothing changes for a record that already exists. bookings.check_in / check_out keep meaning "the overall
 * span" (earliest check-in, latest check-out), which is what older code reads. The only structural change is the booking_rooms unique
 * index: the same physical room may now serve two different stays of one booking, so uniqueness is per stay.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('stays', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('booking_id')->nullable()->index();
            $table->unsignedBigInteger('reservation_id')->nullable()->index();
            $table->unsignedSmallInteger('position')->default(1);
            $table->dateTime('check_in');
            $table->dateTime('check_out');
            $table->timestamp('checked_in_at')->nullable();
            $table->timestamp('checked_out_at')->nullable();
            $table->timestamps();
        });

        foreach (['booking_room_lines', 'reservation_room_lines', 'booking_rooms', 'amenity_requests', 'reservation_amenities'] as $name) {
            if (Schema::hasTable($name) && ! Schema::hasColumn($name, 'stay_id')) {
                Schema::table($name, function (Blueprint $table) {
                    $table->unsignedBigInteger('stay_id')->nullable()->index();
                });
            }
        }

        $this->makeRoomAssignmentUniquePerStay();
        $this->backfill();
    }

    /** The same room can serve stay 1 and stay 2 of one booking; it still can't be listed twice for the same stay. */
    private function makeRoomAssignmentUniquePerStay(): void
    {
        if (! Schema::hasTable('booking_rooms')) {
            return;
        }
        $indexes = collect(Schema::getIndexes('booking_rooms'));
        $perStay = 'booking_rooms_booking_id_stay_id_room_id_unique';
        if (! $indexes->contains('name', $perStay)) {
            Schema::table('booking_rooms', fn (Blueprint $table) => $table->unique(['booking_id', 'stay_id', 'room_id'], $perStay));
        }
        // Added first on purpose: it starts with booking_id, so the foreign key keeps an index while the old one goes.
        $old = $indexes->first(fn ($i) => ($i['unique'] ?? false) && ($i['columns'] ?? []) === ['booking_id', 'room_id']);
        if ($old) {
            Schema::table('booking_rooms', fn (Blueprint $table) => $table->dropUnique($old['name']));
        }
    }

    /** One stay per existing booking and reservation (soft-deleted ones included), and every child row pointed at it. */
    private function backfill(): void
    {
        \App\Support\StaysBackfill::run();
    }

    public function down(): void
    {
        // Back to one unique (booking, room). Only possible while no booking uses the same room for two stays.
        if (Schema::hasTable('booking_rooms')) {
            $dupes = DB::table('booking_rooms')->select('booking_id', 'room_id')->groupBy('booking_id', 'room_id')->havingRaw('COUNT(*) > 1')->count();
            if ($dupes === 0) {
                Schema::table('booking_rooms', fn (Blueprint $table) => $table->unique(['booking_id', 'room_id']));
            }
            if (collect(Schema::getIndexes('booking_rooms'))->contains('name', 'booking_rooms_booking_id_stay_id_room_id_unique')) {
                Schema::table('booking_rooms', fn (Blueprint $table) => $table->dropUnique('booking_rooms_booking_id_stay_id_room_id_unique'));
            }
        }
        foreach (['booking_room_lines', 'reservation_room_lines', 'booking_rooms', 'amenity_requests', 'reservation_amenities'] as $name) {
            if (Schema::hasTable($name) && Schema::hasColumn($name, 'stay_id')) {
                Schema::table($name, fn (Blueprint $table) => $table->dropColumn('stay_id'));
            }
        }
        Schema::dropIfExists('stays');
    }
};
