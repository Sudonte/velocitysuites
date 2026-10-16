<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Receipt 2 of a stay: issued when the receptionist settles the remaining balance in the Check-In module (a partial
 * payment becoming full, or a full payment confirmed). One per billing. The receipt is a frozen snapshot - what was
 * settled, the total paid, the balance (always 0 here) and the discount ID result at that moment - so re-opening it
 * later (after extra charges at check-out) never rewrites its numbers.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('billings', function (Blueprint $table) {
            $table->string('checkin_receipt_number', 40)->nullable()->unique()->after('checkin_settled_at');
            $table->json('checkin_receipt_snapshot')->nullable()->after('checkin_receipt_number');
        });
    }

    public function down(): void
    {
        Schema::table('billings', function (Blueprint $table) {
            $table->dropUnique(['checkin_receipt_number']);
            $table->dropColumn(['checkin_receipt_number', 'checkin_receipt_snapshot']);
        });
    }
};
