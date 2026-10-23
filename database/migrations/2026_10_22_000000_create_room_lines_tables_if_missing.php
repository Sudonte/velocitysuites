<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * booking_room_lines / reservation_room_lines (one line per room type booked: quantity,
 * nightly price, nights, subtotal) exist on the live database, but no migration created
 * them - a fresh clone could not take a booking or reservation at all. Creates them only
 * when missing, so the live database is untouched. stay_id is added right after by
 * 2026_10_23_000001_create_stays.
 */
return new class extends Migration
{
    public function up(): void
    {
        foreach (['booking_room_lines' => 'booking_id', 'reservation_room_lines' => 'reservation_id'] as $name => $parent) {
            if (Schema::hasTable($name)) {
                continue;
            }
            Schema::create($name, function (Blueprint $table) use ($parent) {
                $table->id();
                $table->unsignedBigInteger($parent)->index();
                $table->unsignedBigInteger('room_type_id')->nullable()->index();
                $table->string('room_type_name');
                $table->unsignedInteger('quantity');
                $table->decimal('price_per_night', 10, 2);
                $table->unsignedInteger('number_of_nights');
                $table->decimal('subtotal', 10, 2);
                $table->timestamps();
            });
        }
    }

    public function down(): void
    {
        // Never dropped: on the live database these tables predate this migration.
    }
};
