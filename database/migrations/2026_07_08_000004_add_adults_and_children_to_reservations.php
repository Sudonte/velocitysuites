<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Splits the single number_of_guests headcount into adults and
     * children (under 12), so pricing can treat children as free per
     * hotel policy while still keeping number_of_guests as the total
     * occupant count for capacity/display purposes.
     */
    public function up(): void
    {
        Schema::table('reservations', function (Blueprint $table) {
            $table->unsignedInteger('adults')->nullable()->after('number_of_guests');
            $table->unsignedInteger('children')->default(0)->after('adults');
        });

        DB::statement('UPDATE reservations SET adults = number_of_guests, children = 0');

        Schema::table('reservations', function (Blueprint $table) {
            $table->unsignedInteger('adults')->nullable(false)->change();
        });
    }

    public function down(): void
    {
        Schema::table('reservations', function (Blueprint $table) {
            $table->dropColumn(['adults', 'children']);
        });
    }
};
