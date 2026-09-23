<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Additive-only: a nullable, unique receipt_number on payments and
 * billings so a Partial Payment Receipt (per receptionist-verified
 * deposit-stage payment - see Payment::ensureReceiptNumber()) and an
 * Official Payment Receipt (per fully-paid billing - see
 * Billing::ensureOfficialReceiptNumber()) each get a stable, lazily
 * assigned identifier the first time that receipt is actually generated,
 * not backfilled. No existing column is touched, renamed, or dropped, and
 * no existing row's data changes - every historical payment/billing
 * simply gets its own receipt_number assigned (once) the first time its
 * receipt is viewed after this migration runs, same as any new one. The
 * unique index is defense-in-depth only: the generation logic itself is
 * collision-free by construction (each number embeds its own row's
 * primary key - see PAYMENT_RECEIPT_HISTORY_BACKEND_SPEC.md).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('payments', function (Blueprint $table) {
            $table->string('receipt_number')->nullable()->unique()->after('payment_date');
        });

        Schema::table('billings', function (Blueprint $table) {
            $table->string('receipt_number')->nullable()->unique()->after('billing_status');
        });
    }

    public function down(): void
    {
        Schema::table('payments', function (Blueprint $table) {
            $table->dropUnique(['receipt_number']);
            $table->dropColumn('receipt_number');
        });

        Schema::table('billings', function (Blueprint $table) {
            $table->dropUnique(['receipt_number']);
            $table->dropColumn('receipt_number');
        });
    }
};
