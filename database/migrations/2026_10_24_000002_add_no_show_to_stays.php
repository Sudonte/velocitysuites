<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A date range of a booking the guest never arrived for can be marked as a no-show by the front desk. `no_show_at` is when, `no_show_reason`
 * an optional note. Both stay NULL for every existing stay. Additive only: two nullable columns on `stays`.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('stays')) {
            return;
        }
        Schema::table('stays', function (Blueprint $t) {
            if (! Schema::hasColumn('stays', 'no_show_at')) {
                $t->timestamp('no_show_at')->nullable();
            }
            if (! Schema::hasColumn('stays', 'no_show_reason')) {
                $t->string('no_show_reason', 255)->nullable();
            }
        });
    }

    public function down(): void
    {
        Schema::table('stays', function (Blueprint $t) {
            $t->dropColumn(['no_show_at', 'no_show_reason']);
        });
    }
};
