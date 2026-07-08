<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * Capacity becomes a per-room attribute (the type's capacity is only
     * the baseline/default for new rooms). Rate stays type-first: the type
     * holds the base rate, and rate_override (nullable) lets a specific
     * high-value room charge differently - null means "use the type rate".
     */
    public function up(): void
    {
        Schema::table('rooms', function (Blueprint $table) {
            $table->integer('room_capacity')->nullable()->after('room_type_id');
            $table->decimal('rate_override', 10, 2)->nullable()->after('room_capacity');
        });

        // Baseline each existing room's capacity from its type.
        DB::statement('
            UPDATE rooms
            JOIN room_types ON room_types.id = rooms.room_type_id
            SET rooms.room_capacity = room_types.capacity
        ');

        Schema::table('rooms', function (Blueprint $table) {
            $table->integer('room_capacity')->nullable(false)->change();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('rooms', function (Blueprint $table) {
            $table->dropColumn(['room_capacity', 'rate_override']);
        });
    }
};
