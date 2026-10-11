<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A promotion either runs once (specific dates, with a year) or repeats every
 * year in a month/day window (no year). recur_start_md / recur_end_md hold
 * that window as MMDD numbers (e.g. 1220 .. 105 for Dec 20 - Jan 5).
 * start_date / end_date always hold the current or next occurrence, so
 * anything reading them (the mobile app) still gets real dates.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('promotions', function (Blueprint $table) {
            $table->boolean('is_recurring')->default(false)->after('end_date');
            $table->unsignedSmallInteger('recur_start_md')->nullable()->after('is_recurring');
            $table->unsignedSmallInteger('recur_end_md')->nullable()->after('recur_start_md');
        });
    }

    public function down(): void
    {
        Schema::table('promotions', function (Blueprint $table) {
            $table->dropColumn(['is_recurring', 'recur_start_md', 'recur_end_md']);
        });
    }
};
