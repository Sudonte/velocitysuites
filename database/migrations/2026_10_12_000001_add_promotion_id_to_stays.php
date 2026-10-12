<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The promotion a receptionist applied to a stay (Create Reservation / Create
 * Booking / Walk-in / Check-in). A stay gets either an ID discount or a
 * promotion; its included amenities are added free as amenity requests.
 */
return new class extends Migration
{
    public function up(): void
    {
        foreach (['reservations', 'bookings'] as $name) {
            Schema::table($name, function (Blueprint $table) {
                $table->foreignId('promotion_id')->nullable()->constrained('promotions')->nullOnDelete();
            });
        }
    }

    public function down(): void
    {
        foreach (['reservations', 'bookings'] as $name) {
            Schema::table($name, function (Blueprint $table) {
                $table->dropConstrainedForeignId('promotion_id');
            });
        }
    }
};
