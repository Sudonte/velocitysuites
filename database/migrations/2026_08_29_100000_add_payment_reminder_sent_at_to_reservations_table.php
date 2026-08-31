<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('reservations', function (Blueprint $table) {
            // Marks when the 48-hour payment-deadline reminder notification
            // was sent, so reservations:send-payment-reminders never
            // re-notifies the same reservation on every hourly run.
            $table->timestamp('payment_reminder_sent_at')->nullable()->after('payment_method_locked_at');
        });
    }

    public function down(): void
    {
        Schema::table('reservations', function (Blueprint $table) {
            $table->dropColumn('payment_reminder_sent_at');
        });
    }
};
