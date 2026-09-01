<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Marks whether any receptionist has opened this booking's details yet -
 * drives the "new" (unread) indicator across Bookings/Check-in/Check-out
 * (all three read/write this same column, since they all operate on
 * Booking - see Receptionist\BookingController::show(), CheckInController::
 * panel(), CheckOutController::checkOutBilling()): null sorts first in
 * each module's own list and shows a red dot next to the booking number,
 * cleared (set to now()) the moment a receptionist opens it from any of
 * those three places. Shared/global rather than per-receptionist - once
 * any staff member has seen it, it's no longer "new" for anyone.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('bookings', function (Blueprint $table) {
            $table->timestamp('viewed_at')->nullable()->after('hidden_at');
        });

        // Every booking that already existed before this feature shipped
        // is "already known", not new - without this, a fresh install (or
        // any environment migrating from scratch) would show every single
        // existing booking with a red dot the moment this column exists.
        DB::table('bookings')->whereNull('viewed_at')->update(['viewed_at' => now()]);
    }

    public function down(): void
    {
        Schema::table('bookings', function (Blueprint $table) {
            $table->dropColumn('viewed_at');
        });
    }
};
