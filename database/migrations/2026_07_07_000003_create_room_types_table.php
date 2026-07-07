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
        Schema::create('room_types', function (Blueprint $table) {
            $table->id();
            $table->string('name')->unique();
            $table->decimal('rate', 10, 2);
            $table->integer('capacity');
            $table->enum('status', ['active', 'inactive'])->default('active');
            $table->timestamps();
        });

        Schema::table('rooms', function (Blueprint $table) {
            $table->foreignId('room_type_id')->nullable()->after('room_name')->constrained('room_types');
        });

        // Backfill: one room_types row per distinct existing room_type string,
        // using the first room of that type as the representative rate/capacity.
        $types = DB::table('rooms')->select('room_type', 'room_rate', 'room_capacity')->get()->unique('room_type');

        foreach ($types as $type) {
            $roomTypeId = DB::table('room_types')->insertGetId([
                'name' => $type->room_type,
                'rate' => $type->room_rate,
                'capacity' => $type->room_capacity,
                'status' => 'active',
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            DB::table('rooms')->where('room_type', $type->room_type)->update(['room_type_id' => $roomTypeId]);
        }

        Schema::table('rooms', function (Blueprint $table) {
            $table->foreignId('room_type_id')->nullable(false)->change();
            $table->dropColumn(['room_type', 'room_rate', 'room_capacity']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('rooms', function (Blueprint $table) {
            $table->string('room_type')->nullable();
            $table->decimal('room_rate', 10, 2)->nullable();
            $table->integer('room_capacity')->nullable();
        });

        foreach (DB::table('rooms')->select('id', 'room_type_id')->get() as $room) {
            $type = DB::table('room_types')->find($room->room_type_id);
            if ($type) {
                DB::table('rooms')->where('id', $room->id)->update([
                    'room_type' => $type->name,
                    'room_rate' => $type->rate,
                    'room_capacity' => $type->capacity,
                ]);
            }
        }

        Schema::table('rooms', function (Blueprint $table) {
            $table->string('room_type')->nullable(false)->change();
            $table->decimal('room_rate', 10, 2)->nullable(false)->change();
            $table->integer('room_capacity')->nullable(false)->change();
            $table->dropConstrainedForeignId('room_type_id');
        });

        Schema::dropIfExists('room_types');
    }
};
