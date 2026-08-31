<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * A Payment can now belong to either a Reservation (existing path,
 * unchanged) or directly to a Booking (new independent-booking path) -
 * exactly one of the two is ever set, enforced at the application layer
 * (DirectBookingService for the booking path, the existing reservation
 * controllers for the reservation path), mirroring how amenity_requests
 * already handles two possible parents.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement('ALTER TABLE payments MODIFY reservation_id BIGINT UNSIGNED NULL');

        Schema::table('payments', function (Blueprint $table) {
            $table->foreignId('booking_id')->nullable()->after('reservation_id')->constrained('bookings');
        });
    }

    public function down(): void
    {
        Schema::table('payments', function (Blueprint $table) {
            $table->dropConstrainedForeignId('booking_id');
        });

        DB::statement('ALTER TABLE payments MODIFY reservation_id BIGINT UNSIGNED NOT NULL');
    }
};
