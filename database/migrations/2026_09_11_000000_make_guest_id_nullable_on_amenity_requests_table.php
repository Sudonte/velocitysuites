<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A walk-in/accountless direct booking has no Guest account at all (guest_id
 * null on the Booking itself - see add_booking_id_to_amenity_requests_table),
 * so ReceptionistController::amenitiesStore() creates its AmenityRequest with
 * guest_id null too. The column was still NOT NULL from before booking_id
 * existed, so that insert violated the constraint and the request (and its
 * charge) silently failed for every accountless booking.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('amenity_requests', function (Blueprint $table) {
            $table->foreignId('guest_id')->nullable()->change();
        });
    }

    public function down(): void
    {
        Schema::table('amenity_requests', function (Blueprint $table) {
            $table->foreignId('guest_id')->nullable(false)->change();
        });
    }
};
