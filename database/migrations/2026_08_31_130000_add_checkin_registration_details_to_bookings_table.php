<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Guest-registration-card fields captured at check-in time (Receptionist\
 * CheckInController::store()), before room assignment - permanent/current
 * address and a contact number for whoever is actually standing at the
 * counter, which may differ from the account holder or from what was on
 * file at booking time. Stored on the Booking itself (a per-stay snapshot,
 * same philosophy as the existing guest_first_name/middle/last_name
 * columns) rather than overwriting the guest's account profile.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('bookings', function (Blueprint $table) {
            $table->string('checkin_permanent_address')->nullable()->after('guest_last_name');
            $table->string('checkin_current_address')->nullable()->after('checkin_permanent_address');
            $table->string('checkin_contact_number', 20)->nullable()->after('checkin_current_address');
        });
    }

    public function down(): void
    {
        Schema::table('bookings', function (Blueprint $table) {
            $table->dropColumn(['checkin_permanent_address', 'checkin_current_address', 'checkin_contact_number']);
        });
    }
};
