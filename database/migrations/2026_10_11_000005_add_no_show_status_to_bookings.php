<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * No-show is a booking status set by hand by a receptionist (never
 * automatically). no_show_at records when; status_changed_by records the
 * staff member who marked a booking No-Show or Cancelled, so reports and
 * "Handled By" can name them.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (DB::getDriverName() === 'mysql') {
            DB::statement("ALTER TABLE bookings MODIFY booking_status ENUM('ACTIVE_BOOKING','CHECKED_IN','COMPLETED_BOOKING','CANCELLED_BOOKING','NO_SHOW_BOOKING') NOT NULL DEFAULT 'ACTIVE_BOOKING'");
        }

        Schema::table('bookings', function (Blueprint $table) {
            $table->timestamp('no_show_at')->nullable()->after('cancelled_at');
            $table->foreignId('status_changed_by')->nullable()->after('no_show_at')->constrained('users')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('bookings', function (Blueprint $table) {
            $table->dropConstrainedForeignId('status_changed_by');
            $table->dropColumn('no_show_at');
        });

        if (DB::getDriverName() === 'mysql') {
            DB::statement("UPDATE bookings SET booking_status = 'CANCELLED_BOOKING' WHERE booking_status = 'NO_SHOW_BOOKING'");
            DB::statement("ALTER TABLE bookings MODIFY booking_status ENUM('ACTIVE_BOOKING','CHECKED_IN','COMPLETED_BOOKING','CANCELLED_BOOKING') NOT NULL DEFAULT 'ACTIVE_BOOKING'");
        }
    }
};
