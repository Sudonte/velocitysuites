<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Lets a Reservation exist with no Guest/User account behind it, mirroring
 * what 2026_08_23_150000_make_bookings_independent_of_reservations.php
 * already did for bookings.guest_id - needed for
 * Receptionist\ReservationController::store() (the receptionist "Create
 * Reservation" action), which captures the guest's name directly via
 * guest_first_name/guest_middle_name/guest_last_name (already nullable,
 * already fillable) instead of requiring an account. Raw SQL rather than
 * Schema::table()->nullable()->change(), matching the bookings migration's
 * approach, since ->change() needs doctrine/dbal which isn't installed.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement('ALTER TABLE reservations MODIFY guest_id BIGINT UNSIGNED NULL');
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE reservations MODIFY guest_id BIGINT UNSIGNED NOT NULL');
    }
};
