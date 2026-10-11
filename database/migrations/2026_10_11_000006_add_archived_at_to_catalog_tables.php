<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Promotions, discounts, amenities and announcements are archived, never
 * deleted: archived_at hides one from the admin's default list (and it is
 * switched off), and it can be restored from the Archived tab.
 */
return new class extends Migration
{
    private const TABLES = ['promotions', 'discounts', 'amenities', 'announcements'];

    public function up(): void
    {
        foreach (self::TABLES as $name) {
            Schema::table($name, function (Blueprint $table) {
                $table->timestamp('archived_at')->nullable();
            });
        }
    }

    public function down(): void
    {
        foreach (self::TABLES as $name) {
            Schema::table($name, function (Blueprint $table) {
                $table->dropColumn('archived_at');
            });
        }
    }
};
