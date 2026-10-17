<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Receipts show Payment Date / Payment Time from payments.payment_date. A payment recorded without one printed "-".
 * Fill each missing value from the record's own timestamps: when staff verified it, else when the row was created.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::table('payments')->whereNull('payment_date')->update([
            'payment_date' => DB::raw('COALESCE(verified_at, created_at)'),
        ]);
    }

    public function down(): void
    {
        // Not reversible: the original NULLs carried no information.
    }
};
