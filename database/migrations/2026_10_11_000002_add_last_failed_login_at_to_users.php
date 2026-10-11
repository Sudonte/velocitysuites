<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Failed sign-ins only count within a short window (User::LOGIN_FAILURE_WINDOW_MINUTES).
 * Existing rows get NULL, so any stale counter left over from before is
 * treated as expired rather than locking someone out on their next typo.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->timestamp('last_failed_login_at')->nullable()->after('failed_login_attempts');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('last_failed_login_at');
        });
    }
};
