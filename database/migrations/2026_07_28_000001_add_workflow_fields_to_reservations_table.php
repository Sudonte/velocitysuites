<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Reservation now owns only the pre-booking lifecycle (request -> staff
     * review -> optional deposit -> converted/rejected/cancelled). Once
     * converted, the Booking row (see restructure_bookings_table) becomes
     * the operational record for room assignment/check-in/check-out - the
     * old 'confirmed'/'checked_in'/'checked_out' reservation statuses are
     * retired in favor of a single 'converted' terminal state.
     */
    public function up(): void
    {
        // Widen bookings.booking_status and derive its new value from the
        // CURRENT (pre-rewrite) reservations.status while it's still
        // available, before this migration overwrites it below. Doing this
        // here (not in restructure_bookings_table) is the only point where
        // both the old reservation status and the old booking_status enum
        // are simultaneously in a known state - the old booking_status
        // column is itself unreliable (never updated past 'confirmed' by
        // the pre-redesign check-in/check-out code), so it must be
        // re-derived from the reservation, not trusted as-is.
        DB::statement("ALTER TABLE bookings MODIFY booking_status ENUM('pending', 'confirmed', 'checked_in', 'checked_out', 'cancelled') NOT NULL DEFAULT 'pending'");
        DB::statement('
            UPDATE bookings
            JOIN reservations ON reservations.id = bookings.reservation_id
            SET bookings.booking_status = reservations.status
            WHERE reservations.status IN ("confirmed", "checked_in", "checked_out", "cancelled")
        ');

        Schema::table('reservations', function (Blueprint $table) {
            $table->string('status_new', 20)->default('pending_review')->after('status');
            $table->enum('payment_preference', ['pay_now', 'pay_later'])->nullable()->after('status_new');
            $table->enum('payment_method', ['cash', 'gcash'])->nullable()->after('payment_preference');
            $table->boolean('discount_requested')->default(false)->after('payment_method');
            $table->string('id_document_path')->nullable()->after('discount_requested');
            $table->enum('discount_verification_status', ['not_requested', 'pending', 'approved', 'rejected'])
                ->default('not_requested')->after('id_document_path');
            $table->string('rejection_reason')->nullable()->after('discount_verification_status');
        });

        DB::statement("UPDATE reservations SET status_new = 'pending_review' WHERE status = 'pending'");
        DB::statement("UPDATE reservations SET status_new = 'converted' WHERE status IN ('confirmed', 'checked_in', 'checked_out')");
        DB::statement("UPDATE reservations SET status_new = 'cancelled' WHERE status = 'cancelled'");
        DB::statement("UPDATE reservations SET discount_verification_status = 'not_requested' WHERE discount_requested = 0");

        Schema::table('reservations', function (Blueprint $table) {
            $table->dropColumn('status');
        });

        Schema::table('reservations', function (Blueprint $table) {
            $table->renameColumn('status_new', 'status');
        });

        DB::statement("ALTER TABLE reservations MODIFY status ENUM('pending_review', 'ready_for_booking', 'rejected', 'cancelled', 'converted') NOT NULL DEFAULT 'pending_review'");
    }

    public function down(): void
    {
        Schema::table('reservations', function (Blueprint $table) {
            $table->dropColumn([
                'payment_preference',
                'payment_method',
                'discount_requested',
                'id_document_path',
                'discount_verification_status',
                'rejection_reason',
            ]);
        });

        DB::statement("ALTER TABLE reservations MODIFY status VARCHAR(20) NOT NULL DEFAULT 'pending_review'");
        DB::statement("UPDATE reservations SET status = 'pending' WHERE status = 'pending_review'");
        DB::statement("UPDATE reservations SET status = 'confirmed' WHERE status IN ('ready_for_booking', 'converted')");
        DB::statement("UPDATE reservations SET status = 'cancelled' WHERE status IN ('rejected', 'cancelled')");
        DB::statement("ALTER TABLE reservations MODIFY status ENUM('pending', 'confirmed', 'checked_in', 'checked_out', 'cancelled') NOT NULL DEFAULT 'pending'");

        DB::statement('
            UPDATE bookings
            JOIN reservations ON reservations.id = bookings.reservation_id
            SET bookings.booking_status = "confirmed"
            WHERE reservations.status IN ("checked_in", "checked_out")
        ');
        DB::statement("ALTER TABLE bookings MODIFY booking_status ENUM('pending', 'confirmed', 'cancelled') NOT NULL DEFAULT 'pending'");
    }
};
