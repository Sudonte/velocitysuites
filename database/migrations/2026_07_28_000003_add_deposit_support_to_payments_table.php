<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Lets a guest pay a deposit at reservation time, before a Billing
     * record exists (Billing is still only created at checkout). A deposit
     * payment is created with billing_id null and reservation_id set; at
     * checkout, generateBilling() re-parents it by setting billing_id, so
     * Billing::getBalanceAttribute() picks it up automatically with no
     * changes to the already-working checkout billing/payment flow.
     */
    public function up(): void
    {
        Schema::table('payments', function (Blueprint $table) {
            $table->foreignId('billing_id')->nullable()->change();
            $table->foreignId('reservation_id')->nullable()->after('billing_id')->constrained('reservations');
            $table->string('receipt_path')->nullable()->after('reference_number');
            $table->enum('payment_stage', ['deposit', 'final'])->default('final')->after('payment_status');
            $table->foreignId('verified_by')->nullable()->after('payment_stage')->constrained('users');
            $table->timestamp('verified_at')->nullable()->after('verified_by');
        });
    }

    public function down(): void
    {
        Schema::table('payments', function (Blueprint $table) {
            $table->dropForeign(['reservation_id']);
            $table->dropForeign(['verified_by']);
            $table->dropColumn(['reservation_id', 'receipt_path', 'payment_stage', 'verified_by', 'verified_at']);
            $table->foreignId('billing_id')->nullable(false)->change();
        });
    }
};
