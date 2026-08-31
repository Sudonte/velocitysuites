<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Guest-view-only "hide" gate - a completed/cancelled transaction can be
     * removed from the guest's own list without hard-deleting the real
     * transaction/payment rows (the hotel still needs them for accounting/
     * audit, and staff dashboards must keep showing them). Deliberately a
     * new, orthogonal column rather than reusing booking_status/status or
     * the existing verified_at gate - same pattern as the verified_at
     * migration. hidden_at IS NULL = visible to the guest; NOT NULL = hidden.
     */
    public function up(): void
    {
        Schema::table('bookings', function (Blueprint $table) {
            $table->timestamp('hidden_at')->nullable()->after('verified_by');
        });

        Schema::table('reservations', function (Blueprint $table) {
            $table->timestamp('hidden_at')->nullable()->after('verified_by');
        });
    }

    public function down(): void
    {
        Schema::table('bookings', function (Blueprint $table) {
            $table->dropColumn('hidden_at');
        });

        Schema::table('reservations', function (Blueprint $table) {
            $table->dropColumn('hidden_at');
        });
    }
};
