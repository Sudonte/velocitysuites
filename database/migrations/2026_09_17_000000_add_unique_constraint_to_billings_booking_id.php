<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A Booking should only ever have one Billing (Booking::billing() is
 * hasOne, and CheckOutController::checkOutBilling() only ever creates one
 * via $booking->billing ?? $this->generateBilling($booking)), but nothing
 * at the database level actually enforced that - billings.booking_id was
 * a plain indexed foreign key with no uniqueness guarantee, so two
 * concurrent checkout-panel opens for the same booking could each create
 * their own Billing row. Enforced here instead of only in application
 * code, matching this project's "reuse before creating"/data-integrity
 * conventions.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('billings', function (Blueprint $table) {
            $table->unique('booking_id');
        });
    }

    public function down(): void
    {
        Schema::table('billings', function (Blueprint $table) {
            $table->dropUnique(['booking_id']);
        });
    }
};
