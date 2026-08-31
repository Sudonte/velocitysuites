<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * An AmenityRequest can now belong to either a Reservation (existing path,
 * already nullable) or directly to a Booking (new independent-booking
 * path) - exactly one of the two is ever set. Mirrors
 * add_booking_id_to_payments_table's reasoning.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('amenity_requests', function (Blueprint $table) {
            $table->foreignId('booking_id')->nullable()->after('reservation_id')->constrained('bookings');
        });
    }

    public function down(): void
    {
        Schema::table('amenity_requests', function (Blueprint $table) {
            $table->dropConstrainedForeignId('booking_id');
        });
    }
};
