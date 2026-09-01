<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Reservation's counterpart to bookings.viewed_at (see that migration's
 * docblock) - drives the same "new" (unread) indicator on the
 * Reservations module. Set by Receptionist\ReservationController::
 * details().
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('reservations', function (Blueprint $table) {
            $table->timestamp('viewed_at')->nullable()->after('hidden_at');
        });

        // Every reservation that already existed before this feature
        // shipped is "already known", not new - see the bookings.viewed_at
        // migration's identical backfill for why.
        DB::table('reservations')->whereNull('viewed_at')->update(['viewed_at' => now()]);
    }

    public function down(): void
    {
        Schema::table('reservations', function (Blueprint $table) {
            $table->dropColumn('viewed_at');
        });
    }
};
