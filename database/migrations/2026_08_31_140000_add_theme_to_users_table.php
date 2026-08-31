<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Per-user light/dark appearance preference, set from the new shared
 * Settings page (SettingsController) and applied via data-bs-theme on
 * <html> in layouts/app.blade.php. Defaults to 'light' rather than nullable
 * so every read site can trust the column instead of null-coalescing.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('theme', 10)->default('light')->after('timezone');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('theme');
        });
    }
};
