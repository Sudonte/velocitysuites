<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Mirrors reservations.discount_verification_status - a direct "New
 * Booking" transaction (see Services\DirectBookingService) that requested
 * a Senior/PWD discount needs this too, for the exact same checkout-time
 * discount-approval flow (Receptionist\CheckOutController::applyDiscount())
 * that already exists for a reservation-derived booking.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('bookings', function (Blueprint $table) {
            $table->string('discount_verification_status')->nullable()->after('discount_requested');
        });

        // Backfill existing direct bookings (none exist yet in practice,
        // but keep this consistent with how reservations seeds this same
        // column) - not_requested when no discount was ever requested.
        DB::table('bookings')->whereNull('discount_verification_status')
            ->update(['discount_verification_status' => 'not_requested']);
    }

    public function down(): void
    {
        Schema::table('bookings', function (Blueprint $table) {
            $table->dropColumn('discount_verification_status');
        });
    }
};
