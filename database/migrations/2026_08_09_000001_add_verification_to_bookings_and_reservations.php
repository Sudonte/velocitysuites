<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * A new, receptionist-driven verification gate for bookings and
     * reservations - deliberately separate from booking_status/
     * reservation.status (and from the existing payment-level
     * verified_at/verified_by on the payments table) so the existing
     * check-in/check-out/accept/reject/convert logic is untouched.
     * verified_at IS NULL = "Pending Verification" (Active list);
     * NOT NULL = "Verified" (Complete list).
     */
    public function up(): void
    {
        Schema::table('bookings', function (Blueprint $table) {
            $table->timestamp('verified_at')->nullable()->after('booking_status');
            $table->foreignId('verified_by')->nullable()->after('verified_at')->constrained('users');
        });

        Schema::table('reservations', function (Blueprint $table) {
            $table->timestamp('verified_at')->nullable()->after('status');
            $table->foreignId('verified_by')->nullable()->after('verified_at')->constrained('users');
        });
    }

    public function down(): void
    {
        Schema::table('bookings', function (Blueprint $table) {
            $table->dropForeign(['verified_by']);
            $table->dropColumn(['verified_at', 'verified_by']);
        });

        Schema::table('reservations', function (Blueprint $table) {
            $table->dropForeign(['verified_by']);
            $table->dropColumn(['verified_at', 'verified_by']);
        });
    }
};