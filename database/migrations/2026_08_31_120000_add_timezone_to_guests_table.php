<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Server-resolved IANA timezone (e.g. "Asia/Manila") for a guest's account, set once at
 * registration by RegisterController::resolveTimezone() - never trusted from the client
 * directly. Nullable since older accounts predate this column and some countries genuinely
 * can't be resolved to a single zone without an explicit pick.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('guests', function (Blueprint $table) {
            $table->string('timezone', 64)->nullable()->after('zip_code');
        });
    }

    public function down(): void
    {
        Schema::table('guests', function (Blueprint $table) {
            $table->dropColumn('timezone');
        });
    }
};
