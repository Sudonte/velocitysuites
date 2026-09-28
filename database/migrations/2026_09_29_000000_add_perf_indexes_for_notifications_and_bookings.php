<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The unread-notification badge (navbar.blade.php, every authenticated page,
 * every role) and the receptionist sidebar's "new Check-In/Check-Out" dots
 * (sidebar.blade.php, every receptionist page) each run a WHERE on columns
 * that only ever had an implicit single-column FK index - every request was
 * doing a filtered scan instead of an index lookup. Composite indexes here
 * match exactly what those queries filter on.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('notifications', function (Blueprint $table) {
            $table->index(['user_id', 'is_read'], 'notifications_user_id_is_read_index');
        });

        Schema::table('bookings', function (Blueprint $table) {
            $table->index(['booking_status', 'viewed_at'], 'bookings_booking_status_viewed_at_index');
        });
    }

    public function down(): void
    {
        Schema::table('notifications', function (Blueprint $table) {
            $table->dropIndex('notifications_user_id_is_read_index');
        });

        Schema::table('bookings', function (Blueprint $table) {
            $table->dropIndex('bookings_booking_status_viewed_at_index');
        });
    }
};
