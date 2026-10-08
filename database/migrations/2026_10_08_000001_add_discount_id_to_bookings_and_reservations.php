<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The guest-chosen discount (any active row of the Discount module, not just
 * the two statutory ones the old id_card_type enum could express). id_card_type
 * keeps holding the discount's NAME (display/back-compat); discount_id is the
 * unambiguous reference - two active discounts can share a name.
 */
return new class extends Migration
{
    public function up(): void
    {
        foreach (['bookings', 'reservations'] as $table) {
            if (! Schema::hasColumn($table, 'discount_id')) {
                Schema::table($table, function (Blueprint $t) {
                    $t->unsignedBigInteger('discount_id')->nullable()->after('id_card_type')->index();
                });
            }
        }
    }

    public function down(): void
    {
        foreach (['bookings', 'reservations'] as $table) {
            if (Schema::hasColumn($table, 'discount_id')) {
                Schema::table($table, function (Blueprint $t) {
                    $t->dropColumn('discount_id');
                });
            }
        }
    }
};
