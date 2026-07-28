<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Promotions become package/amenity-only going forward - the new
     * Discount module (see create_discounts_table) is the only discount
     * mechanism now. Existing discount-type promotion rows are
     * deactivated (not deleted, for historical/audit purposes) rather
     * than silently left live under a mechanism the app no longer
     * auto-applies.
     */
    public function up(): void
    {
        DB::table('promotions')
            ->where('promo_type', 'discount')
            ->update(['status' => 'inactive']);
    }

    public function down(): void
    {
        // Not reversible - which promotions were active before
        // deactivation isn't recorded.
    }
};
