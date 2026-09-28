<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Lets a single physical room within a multi-room Booking be checked out
 * independently of its siblings (Receptionist\CheckOutController::
 * checkOutRoom()) - previously check-out/check-in status only existed at
 * the whole-Booking level (bookings.booking_status), so every room in a
 * multi-room booking had to check out together in one atomic action.
 * Null means "still checked in"; Billing is only generated once every
 * assigned room's checked_out_at is set (see generateBilling()'s
 * per-room nights calculation, which caps an already-checked-out room's
 * charge at its own checked_out_at rather than the booking's current
 * check_out date - so a later extension of a sibling room's stay never
 * re-bills a room that already left).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('booking_rooms', function (Blueprint $table) {
            $table->timestamp('checked_out_at')->nullable()->after('room_id');
        });
    }

    public function down(): void
    {
        Schema::table('booking_rooms', function (Blueprint $table) {
            $table->dropColumn('checked_out_at');
        });
    }
};
