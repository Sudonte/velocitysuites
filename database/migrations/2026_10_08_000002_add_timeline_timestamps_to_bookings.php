<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The guest app's Transaction Timeline has always asked the API for
 * checked_in_at / checked_out_at / completed_at (see the Android BookingDto),
 * but the bookings table never stored them, so those steps could only ever
 * show "Pending". These columns are set by the receptionist actions
 * (check-in, check-out/completion, discount ID verification) at the moment
 * they happen. Additive and nullable.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('bookings', function (Blueprint $table) {
            foreach (['checked_in_at', 'checked_out_at', 'completed_at', 'discount_verified_at'] as $column) {
                if (! Schema::hasColumn('bookings', $column)) {
                    $table->timestamp($column)->nullable();
                }
            }
        });
    }

    public function down(): void
    {
        Schema::table('bookings', function (Blueprint $table) {
            foreach (['checked_in_at', 'checked_out_at', 'completed_at', 'discount_verified_at'] as $column) {
                if (Schema::hasColumn('bookings', $column)) {
                    $table->dropColumn($column);
                }
            }
        });
    }
};
