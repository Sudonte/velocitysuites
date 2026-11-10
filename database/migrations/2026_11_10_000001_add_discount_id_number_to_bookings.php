<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Guests no longer upload an ID when they book: the front desk records it at check-in, either as a photo or by noting the ID number.
 * Additive and nullable: nothing existing changes.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('bookings', 'discount_id_number')) {
            Schema::table('bookings', function (Blueprint $table) {
                $table->string('discount_id_number', 60)->nullable()->after('id_card_image_path');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('bookings', 'discount_id_number')) {
            Schema::table('bookings', function (Blueprint $table) {
                $table->dropColumn('discount_id_number');
            });
        }
    }
};
