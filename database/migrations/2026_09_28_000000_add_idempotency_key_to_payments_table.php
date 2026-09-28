<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Mirrors bookings.idempotency_key / reservations.idempotency_key (see
 * 2026_09_19_000000_add_idempotency_key_to_reservations_table) on the
 * payments side, so Api\PaymentController::store() (the "Pay Now" endpoint
 * for an existing Booking/Reservation) gets the same double-tap/dropped-
 * response-retry protection the two creation endpoints already have. Prior
 * to this, a retried submission with the same reference_number would hit
 * the reference_number.unique validation rule and get a misleading
 * "already used" rejection instead of its own already-created payment back
 * - see Api\PaymentController::store()'s own doc comment.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('payments', function (Blueprint $table) {
            $table->string('idempotency_key')->nullable()->unique()->after('id');
        });
    }

    public function down(): void
    {
        Schema::table('payments', function (Blueprint $table) {
            $table->dropUnique(['idempotency_key']);
            $table->dropColumn('idempotency_key');
        });
    }
};
