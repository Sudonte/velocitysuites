<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Lets a Booking exist as a genuinely independent transaction, never
 * derived from a Reservation - the guest mobile app's "New Booking" path
 * (as opposed to "New Reservation") creates one of these directly, with
 * its own auto-increment id as the Booking #, no Reservation row ever
 * created. Existing bookings (all currently reservation-derived) are
 * completely untouched by this migration - reservation_id simply becomes
 * allowed to be null going forward; every existing row keeps its value.
 *
 * The new columns mirror the equivalent ones already on `reservations`
 * (same types/nullability) so a direct booking can carry the same guest/
 * identification data a reservation-derived one already gets via
 * reservation->guest_first_name etc.
 */
return new class extends Migration
{
    public function up(): void
    {
        // Raw ALTER (not ->nullable()->change()) to avoid any doctrine/dbal
        // interaction with the existing bookings_reservation_id_foreign
        // constraint - MySQL allows NULL in an FK column without touching
        // the constraint itself, same raw-ALTER approach already used by
        // this project's other enum-widening migrations.
        DB::statement('ALTER TABLE bookings MODIFY reservation_id BIGINT UNSIGNED NULL');

        Schema::table('bookings', function (Blueprint $table) {
            $table->foreignId('guest_id')->nullable()->after('reservation_id')->constrained('guests');
            $table->string('guest_first_name')->nullable()->after('guest_id');
            $table->string('guest_middle_name')->nullable()->after('guest_first_name');
            $table->string('guest_last_name')->nullable()->after('guest_middle_name');
            $table->enum('payment_method', ['cash', 'gcash'])->nullable()->after('booking_status');
            $table->string('id_card_type')->nullable()->after('payment_method');
            $table->string('id_card_image_path')->nullable()->after('id_card_type');
            $table->longText('additional_guest_details')->nullable()->after('id_card_image_path');
            $table->boolean('discount_requested')->default(false)->after('additional_guest_details');
            $table->string('rejection_reason')->nullable()->after('discount_requested');
        });
    }

    public function down(): void
    {
        Schema::table('bookings', function (Blueprint $table) {
            $table->dropConstrainedForeignId('guest_id');
            $table->dropColumn([
                'guest_first_name', 'guest_middle_name', 'guest_last_name',
                'payment_method', 'id_card_type', 'id_card_image_path',
                'additional_guest_details', 'discount_requested', 'rejection_reason',
            ]);
        });

        DB::statement('ALTER TABLE bookings MODIFY reservation_id BIGINT UNSIGNED NOT NULL');
    }
};
