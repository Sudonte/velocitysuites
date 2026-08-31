<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Backs the one-time Cash -> GCash payment-method switch (spec: a guest who created a
 * Cash Pay Later reservation may change it to GCash exactly once, then Pay Now unlocks).
 * The prior equivalent gate on the Android side (LocalTransactionState.hasModifiedOnce)
 * is per-device SharedPreferences only - reinstalling the app or switching devices would
 * silently reset it, letting the guest "use" the one-time switch again. This column is
 * the actual, unbypassable source of truth.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('reservations', function (Blueprint $table) {
            $table->timestamp('payment_method_locked_at')->nullable()->after('payment_method');
        });
    }

    public function down(): void
    {
        Schema::table('reservations', function (Blueprint $table) {
            $table->dropColumn('payment_method_locked_at');
        });
    }
};
