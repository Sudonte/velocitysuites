<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * How many of an included paid amenity each room of the type gets free
 * (e.g. 1 extra bed). More can still be bought as add-ons at the normal price.
 * Additive: existing assignments read as 1.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('room_type_amenity', 'quantity')) {
            Schema::table('room_type_amenity', function (Blueprint $table) {
                $table->unsignedSmallInteger('quantity')->default(1)->after('amenity_id');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('room_type_amenity', 'quantity')) {
            Schema::table('room_type_amenity', function (Blueprint $table) {
                $table->dropColumn('quantity');
            });
        }
    }
};
