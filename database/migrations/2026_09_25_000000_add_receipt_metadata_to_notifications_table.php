<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Additive, backward-compatible metadata so a payment-related notification
 * can carry the exact receipt it's about (PARTIAL_RECEIPT/FULL_PAYMENT_RECEIPT/
 * OFFICIAL_RECEIPT + its receipt_number), letting a client open that exact
 * receipt directly instead of guessing/inferring it or regex-parsing the
 * notification message. Both columns are nullable and NOT backfilled -
 * every notification created before this migration (and any non-payment
 * notification created after it) simply has both as null and continues to
 * behave exactly as before; only NotificationService's payment-verification/
 * checkout-completion call sites ever populate them, and only with a
 * receipt_number that was already minted by Payment::ensureReceiptNumber()/
 * Billing::ensureOfficialReceiptNumber() in their own explicit business-event
 * transaction - this migration and the Notification model never generate one.
 * No FK/unique constraint on receipt_number here deliberately - it is a
 * denormalized read-only copy for display/deep-link purposes only, not the
 * authoritative record (payments.receipt_number/billings.receipt_number
 * remain that); GET /guest/receipts/{receiptNumber} still independently
 * enforces ownership regardless of what a notification happens to carry.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('notifications', function (Blueprint $table) {
            $table->string('receipt_number')->nullable()->after('target_audience');
            $table->string('receipt_type')->nullable()->after('receipt_number');
        });
    }

    public function down(): void
    {
        Schema::table('notifications', function (Blueprint $table) {
            $table->dropColumn(['receipt_number', 'receipt_type']);
        });
    }
};
