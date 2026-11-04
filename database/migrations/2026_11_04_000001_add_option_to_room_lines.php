<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Additive: which room OPTION (price + capacity inside a room type) a booked line is for. NULL = the standard option, which is what
 * every existing line is, so nothing already stored changes.
 */
return new class extends Migration
{
    public function up(): void
    {
        foreach (['booking_room_lines', 'reservation_room_lines'] as $table) {
            if (Schema::hasTable($table) && ! Schema::hasColumn($table, 'option_key')) {
                Schema::table($table, function (Blueprint $t) {
                    $t->string('option_key', 40)->nullable();
                    $t->unsignedSmallInteger('capacity')->nullable();
                });
            }
        }
    }

    public function down(): void
    {
        foreach (['booking_room_lines', 'reservation_room_lines'] as $table) {
            if (Schema::hasColumn($table, 'option_key')) {
                Schema::table($table, fn (Blueprint $t) => $t->dropColumn(['option_key', 'capacity']));
            }
        }
    }
};
