<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Test-data flag for a single booking - for walk-in test bookings that have
 * no guest account to flag with users.is_test_account. Set only via
 * `php artisan bookings:mark-test {id}`; honoured by App\Support\TestAccountScope.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('bookings', function (Blueprint $table) {
            $table->boolean('is_test_booking')->default(false)->after('booking_status');
        });
    }

    public function down(): void
    {
        Schema::table('bookings', function (Blueprint $table) {
            $table->dropColumn('is_test_booking');
        });
    }
};
