<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Optional validity window for a discount. Both columns nullable: empty means "no limit" on that side, so every
 * existing discount (including Senior Citizen and PWD) stays valid indefinitely. Dates are hotel-local (Asia/Manila)
 * calendar days, inclusive.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('discounts', function (Blueprint $table) {
            if (! Schema::hasColumn('discounts', 'start_date')) {
                $table->date('start_date')->nullable()->after('status');
            }
            if (! Schema::hasColumn('discounts', 'end_date')) {
                $table->date('end_date')->nullable()->after('start_date');
            }
        });
    }

    public function down(): void
    {
        Schema::table('discounts', function (Blueprint $table) {
            foreach (['start_date', 'end_date'] as $column) {
                if (Schema::hasColumn('discounts', $column)) {
                    $table->dropColumn($column);
                }
            }
        });
    }
};
