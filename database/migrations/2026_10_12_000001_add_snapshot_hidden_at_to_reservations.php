<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The guest app shows a converted reservation twice: as the live Booking, and as a frozen "Completed"
 * snapshot in the Reservation list. This lets the guest remove just that snapshot row (nullable, additive)
 * without touching the booking, the reservation's own hidden_at, or Transaction History.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('reservations', function (Blueprint $table) {
            $table->timestamp('snapshot_hidden_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('reservations', function (Blueprint $table) {
            $table->dropColumn('snapshot_hidden_at');
        });
    }
};
