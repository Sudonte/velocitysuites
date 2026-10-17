<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Indexes for lookups that were scanning whole tables: revenue / receipt queries filter payments by payment_date,
 * the activity history looks rows up by (subject_type, subject_id), and room availability filters bookings by
 * room type + status + stay dates. Purely additive.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('payments', function (Blueprint $table) {
            $table->index('payment_date', 'payments_payment_date_index');
        });
        // subject_type is a 255-char string: index only its first 40 characters so the key stays inside every MySQL/MariaDB row-format limit.
        DB::statement('CREATE INDEX activity_logs_subject_index ON activity_logs (subject_type(40), subject_id)');
        Schema::table('bookings', function (Blueprint $table) {
            $table->index(['room_type_id', 'booking_status', 'check_in'], 'bookings_availability_index');
        });
    }

    public function down(): void
    {
        Schema::table('payments', fn (Blueprint $t) => $t->dropIndex('payments_payment_date_index'));
        DB::statement('DROP INDEX activity_logs_subject_index ON activity_logs');
        Schema::table('bookings', fn (Blueprint $t) => $t->dropIndex('bookings_availability_index'));
    }
};
