<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Additive only. A transaction remembers the promos that were running for its room types when it was booked (so a promo that
 * expires before check-in is still honoured), who verified the one promo it uses and when, and the pesos that promo took off
 * the bill (a line of its own next to the ID discount).
 */
return new class extends Migration
{
    public function up(): void
    {
        foreach (['reservations', 'bookings'] as $table) {
            Schema::table($table, function (Blueprint $t) use ($table) {
                if (! Schema::hasColumn($table, 'promo_snapshot')) {
                    $t->json('promo_snapshot')->nullable();
                }
            });
        }
        Schema::table('bookings', function (Blueprint $t) {
            if (! Schema::hasColumn('bookings', 'promotion_verified_by')) {
                $t->unsignedBigInteger('promotion_verified_by')->nullable();
            }
            if (! Schema::hasColumn('bookings', 'promotion_verified_at')) {
                $t->timestamp('promotion_verified_at')->nullable();
            }
        });
        Schema::table('billings', function (Blueprint $t) {
            if (! Schema::hasColumn('billings', 'promo_discount')) {
                $t->decimal('promo_discount', 10, 2)->default(0);
            }
            if (! Schema::hasColumn('billings', 'promotion_id')) {
                $t->unsignedBigInteger('promotion_id')->nullable();
            }
        });
    }

    public function down(): void
    {
        // additive migration: nothing is dropped
    }
};
