<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Cash handed over vs cash applied. payments.amount_paid stays the amount APPLIED to the bill (the only money
 * recorded as paid); cash_received is what the guest actually handed over and change_given is the difference returned.
 * Both are null for GCash and for every payment recorded before this existed.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('payments', function (Blueprint $table) {
            $table->decimal('cash_received', 10, 2)->nullable()->after('amount_paid');
            $table->decimal('change_given', 10, 2)->nullable()->after('cash_received');
        });
    }

    public function down(): void
    {
        Schema::table('payments', function (Blueprint $table) {
            $table->dropColumn(['cash_received', 'change_given']);
        });
    }
};
