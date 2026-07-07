<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('promotions', function (Blueprint $table) {
            // Nullable: a null room_type_id means the promotion applies to all types.
            $table->foreignId('room_type_id')->nullable()->after('description')->constrained('room_types')->nullOnDelete();
        });

        DB::statement('
            UPDATE promotions
            JOIN room_types ON room_types.name = promotions.room_type
            SET promotions.room_type_id = room_types.id
        ');

        Schema::table('promotions', function (Blueprint $table) {
            $table->dropColumn('room_type');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('promotions', function (Blueprint $table) {
            $table->string('room_type')->nullable();
        });

        DB::statement('
            UPDATE promotions
            JOIN room_types ON room_types.id = promotions.room_type_id
            SET promotions.room_type = room_types.name
        ');

        Schema::table('promotions', function (Blueprint $table) {
            $table->dropConstrainedForeignId('room_type_id');
        });
    }
};
