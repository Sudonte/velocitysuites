<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        // The name shown for a reservation/booking always came from
        // whichever account was logged in, even though a different
        // person can be the one actually staying (e.g. booking on a
        // friend's account). Capture the real stay guest's name at
        // reservation/booking time instead of inferring it from the
        // account.
        Schema::table('reservations', function (Blueprint $table) {
            $table->string('guest_first_name')->nullable()->after('guest_id');
            $table->string('guest_last_name')->nullable()->after('guest_first_name');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('reservations', function (Blueprint $table) {
            $table->dropColumn(['guest_first_name', 'guest_last_name']);
        });
    }
};
