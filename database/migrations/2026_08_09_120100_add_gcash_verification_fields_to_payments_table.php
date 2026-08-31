<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Adds GCash-specific payment verification fields on top of the
     * existing verified_at/verified_by pair (see
     * 2026_07_28_000003_add_deposit_support_to_payments_table.php):
     * gcash_number records the guest-declared sender number for a GCash
     * payment; rejection_reason/rejected_at/rejected_by mirror the
     * existing reservation-level rejection fields, but at the individual
     * payment/receipt level, so a receptionist can reject a specific
     * GCash receipt (bad screenshot, mismatched reference number, etc.)
     * without touching the parent Reservation/Booking status. The
     * payment_status enum gains a new 'rejected' member to match.
     */
    public function up(): void
    {
        Schema::table('payments', function (Blueprint $table) {
            $table->string('gcash_number', 15)->nullable()->after('receipt_path');
            $table->string('rejection_reason', 500)->nullable()->after('verified_at');
            $table->timestamp('rejected_at')->nullable()->after('rejection_reason');
            $table->foreignId('rejected_by')->nullable()->after('rejected_at')->constrained('users')->nullOnDelete();
        });

        // payment_status was: enum('pending','completed','failed') NOT NULL DEFAULT 'pending'
        DB::statement("ALTER TABLE payments MODIFY payment_status ENUM('pending','completed','failed','rejected') NOT NULL DEFAULT 'pending'");
    }

    public function down(): void
    {
        // Revert any 'rejected' rows back to 'failed' before narrowing the
        // enum, so the down() migration never fails / silently truncates data.
        DB::table('payments')->where('payment_status', 'rejected')->update(['payment_status' => 'failed']);

        DB::statement("ALTER TABLE payments MODIFY payment_status ENUM('pending','completed','failed') NOT NULL DEFAULT 'pending'");

        Schema::table('payments', function (Blueprint $table) {
            $table->dropForeign(['rejected_by']);
            $table->dropColumn(['gcash_number', 'rejection_reason', 'rejected_at', 'rejected_by']);
        });
    }
};
