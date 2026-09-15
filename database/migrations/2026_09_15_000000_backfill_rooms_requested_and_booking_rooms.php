<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * reservations.rooms_requested, bookings.rooms_requested, and the
 * booking_rooms pivot table (the multi-room-booking feature this whole app
 * now depends on - RoomAvailabilityService, CheckInController's room
 * assignment, every reservation/booking create form) were added directly
 * to the live database at some point, outside any migration in this repo -
 * the same undocumented-schema-drift pattern already admitted for
 * bookings.booking_status/reservations.status (see those models' own
 * docblocks). A fresh clone (`composer install && php artisan migrate`)
 * would hard-fail on literally every reservation/booking create and every
 * check-in room assignment without this. Guarded with hasColumn/hasTable
 * so it's a safe no-op against the live database, which already has all
 * three - only a fresh install actually creates anything here. Column
 * shapes/constraints below match the live schema exactly (inspected via
 * SHOW CREATE TABLE), not guessed.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasColumn('reservations', 'rooms_requested')) {
            Schema::table('reservations', function (Blueprint $table) {
                $table->unsignedTinyInteger('rooms_requested')->default(1)->after('room_type_id');
            });
        }

        if (!Schema::hasColumn('bookings', 'rooms_requested')) {
            Schema::table('bookings', function (Blueprint $table) {
                $table->unsignedTinyInteger('rooms_requested')->default(1)->after('room_type_id');
            });
        }

        if (!Schema::hasTable('booking_rooms')) {
            Schema::create('booking_rooms', function (Blueprint $table) {
                $table->id();
                $table->foreignId('booking_id')->constrained()->onDelete('cascade');
                $table->foreignId('room_id')->constrained();
                $table->timestamps();
                $table->unique(['booking_id', 'room_id']);
            });
        }
    }

    public function down(): void
    {
        // No-op: this migration only backfills schema that the live
        // database already has in every environment that matters - a
        // fresh install rolling this back would just be re-creating the
        // exact gap it exists to close.
    }
};
