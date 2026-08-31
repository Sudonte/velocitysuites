<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * notifications.reference_id was hard FK-constrained to reservations.id
 * only (add_reference_id_to_notifications_table) - every existing caller
 * happened to only ever pass a reservation id, so this was never a
 * problem until now: a direct "New Booking" transaction (see
 * Services\DirectBookingService) needs to reference its own Booking id
 * instead, and there's no reservation for it to point to at all. A single
 * column can't cleanly FK to two different tables, so this drops the
 * constraint and leaves reference_id a plain nullable id whose meaning is
 * already understood contextually from the notification's `category`
 * (exactly how it already behaves for every other category today - this
 * changes nothing about existing reservation-referencing notifications,
 * it only removes a constraint that would otherwise reject a valid
 * booking reference).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('notifications', function (Blueprint $table) {
            $table->dropForeign(['reference_id']);
        });
    }

    public function down(): void
    {
        Schema::table('notifications', function (Blueprint $table) {
            $table->foreign('reference_id')->references('id')->on('reservations')->nullOnDelete();
        });
    }
};
