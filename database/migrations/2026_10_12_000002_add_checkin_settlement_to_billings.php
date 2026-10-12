<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The bill is created and settled at check-in: checkin_settled_total is what
 * was billed (and fully paid) then, checkin_settled_at when. Check-out lists
 * those items as "Paid at check-in" and only charges what was added after.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('billings', function (Blueprint $table) {
            $table->decimal('checkin_settled_total', 10, 2)->nullable()->after('total_amount');
            $table->timestamp('checkin_settled_at')->nullable()->after('checkin_settled_total');
        });
    }

    public function down(): void
    {
        Schema::table('billings', function (Blueprint $table) {
            $table->dropColumn(['checkin_settled_total', 'checkin_settled_at']);
        });
    }
};
