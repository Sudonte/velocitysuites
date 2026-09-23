<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Enforces at the database level that one Reservation can convert to at
 * most one Booking - a defense-in-depth backstop for
 * ReservationWorkflowService::tryAutoConvert()'s own application-level
 * lockForUpdate() + existing-booking check (see that method's own doc for
 * why both layers exist independently). `reservation_id` stays nullable -
 * a direct "New Booking" transaction has no reservation at all - and a
 * unique index over a nullable column in MySQL/MariaDB permits unlimited
 * NULLs while still enforcing uniqueness among any non-null values, which
 * is exactly the desired one-reservation-to-one-booking rule.
 *
 * Verified against live production data before writing this migration:
 * `SELECT reservation_id, COUNT(*) FROM bookings WHERE reservation_id IS
 * NOT NULL GROUP BY reservation_id HAVING COUNT(*) > 1` returned zero rows
 * (75 bookings with a reservation_id, 75 distinct reservation_id values) -
 * safe to add without conflicting with any existing row.
 *
 * Guarded with an existence check: running this against the current live
 * database discovered `bookings_reservation_id_unique` already exists as a
 * genuine UNIQUE index (confirmed via `SHOW INDEX FROM bookings`) despite
 * no tracked migration in this repository ever having created it - the
 * live schema and migration history had already drifted apart, presumably
 * from an untracked manual change. This migration is a no-op there, but
 * still closes the gap for any OTHER environment (a fresh local/staging
 * database built purely from `php artisan migrate`) that would otherwise
 * have no such protection at all.
 */
return new class extends Migration
{
    public function up(): void
    {
        if ($this->indexExists('bookings', 'bookings_reservation_id_unique')) {
            return;
        }

        Schema::table('bookings', function (Blueprint $table) {
            $table->unique('reservation_id', 'bookings_reservation_id_unique');
        });
    }

    public function down(): void
    {
        if (! $this->indexExists('bookings', 'bookings_reservation_id_unique')) {
            return;
        }

        Schema::table('bookings', function (Blueprint $table) {
            $table->dropUnique('bookings_reservation_id_unique');
        });
    }

    private function indexExists(string $table, string $indexName): bool
    {
        return DB::select("SHOW INDEX FROM `{$table}` WHERE Key_name = ?", [$indexName]) !== [];
    }
};
