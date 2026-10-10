<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * "Removed by the guest" as its own flag. hidden_at stays the generic "off the guest's Bookings & Reservations list"
 * (also set by staff archiving); hidden_by_guest_at records that THE GUEST removed it. Neither ever deletes anything -
 * Transaction History lists hidden records too, with their real status.
 *
 * Backfill: every row the guest already hid through the old Delete flow (hidden_at set on a terminal record) is
 * marked as guest-hidden, so it reads "Removed from bookings by guest" in history.
 */
return new class extends Migration
{
    public function up(): void
    {
        foreach (['bookings', 'reservations'] as $table) {
            if (! Schema::hasColumn($table, 'hidden_by_guest_at')) {
                Schema::table($table, function (Blueprint $t) {
                    $t->timestamp('hidden_by_guest_at')->nullable()->after('hidden_at');
                });
            }
        }

        // Only the guest ever hides a Reservation, so every hidden one is a guest removal. A Booking made from such a
        // Reservation was hidden in the same action. (A direct Booking could also have been archived by staff, so
        // those are left unlabelled rather than guessed at.)
        DB::table('reservations')->whereNotNull('hidden_at')->whereNull('hidden_by_guest_at')->update(['hidden_by_guest_at' => DB::raw('hidden_at')]);
        DB::table('bookings')->whereNotNull('hidden_at')->whereNull('hidden_by_guest_at')->whereNotNull('reservation_id')
            ->whereIn('reservation_id', DB::table('reservations')->whereNotNull('hidden_by_guest_at')->select('id'))
            ->update(['hidden_by_guest_at' => DB::raw('hidden_at')]);
    }

    public function down(): void
    {
        foreach (['bookings', 'reservations'] as $table) {
            if (Schema::hasColumn($table, 'hidden_by_guest_at')) {
                Schema::table($table, fn (Blueprint $t) => $t->dropColumn('hidden_by_guest_at'));
            }
        }
    }
};
