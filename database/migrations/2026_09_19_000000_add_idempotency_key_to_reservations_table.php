<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Mirrors bookings.idempotency_key (already live - see the
 * 2026_09_16_000001_add_idempotency_key_to_bookings_table migration record
 * in the `migrations` table; that migration's own file is missing from the
 * current working tree, but the column itself is already correctly in
 * place, confirmed via SHOW COLUMNS before writing this one) on the
 * reservations side, so Api\ReservationController::store() gets the same
 * double-tap/retry protection Api\BookingController::store() already has.
 * See MULTI_ROOM_TRANSACTION_BACKEND_SPEC.md section 9b.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('reservations', function (Blueprint $table) {
            $table->string('idempotency_key')->nullable()->unique()->after('id');
        });
    }

    public function down(): void
    {
        Schema::table('reservations', function (Blueprint $table) {
            $table->dropUnique(['idempotency_key']);
            $table->dropColumn('idempotency_key');
        });
    }
};
