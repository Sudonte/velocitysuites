<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A guest can book several stays in one go. Each stay is still its own booking (or reservation) with its own dates, rooms, check-in,
 * check-out, billing and three receipts; a "group" only ties them together for the guest (one combined payment, "Cancel all stays",
 * grouped lists) and for the front desk (verify the whole group at once). Additive only: existing records have no group.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('booking_groups', function (Blueprint $table) {
            $table->id();
            $table->foreignId('guest_id')->constrained('guests')->cascadeOnDelete();
            $table->timestamps();
        });

        Schema::table('bookings', function (Blueprint $table) {
            $table->unsignedBigInteger('group_id')->nullable()->after('guest_id')->index();
        });

        Schema::table('reservations', function (Blueprint $table) {
            $table->unsignedBigInteger('group_id')->nullable()->after('guest_id')->index();
        });
    }

    public function down(): void
    {
        Schema::table('reservations', function (Blueprint $table) {
            $table->dropColumn('group_id');
        });
        Schema::table('bookings', function (Blueprint $table) {
            $table->dropColumn('group_id');
        });
        Schema::dropIfExists('booking_groups');
    }
};
