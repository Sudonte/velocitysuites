<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Each stay has its own check-in and check-out day, so "the receptionist was told it is past 2 PM / 12 NN" is remembered per stay.
 * extended_at marks a stay the front desk extended past its booked check-out.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('stays', function (Blueprint $table) {
            $table->timestamp('checkin_alerted_at')->nullable();
            $table->timestamp('checkout_alerted_at')->nullable();
            $table->timestamp('extended_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('stays', function (Blueprint $table) {
            $table->dropColumn(['checkin_alerted_at', 'checkout_alerted_at', 'extended_at']);
        });
    }
};
