<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Mirrors announcements.notified_at (see
 * 2026_08_14_130000_add_notified_at_to_announcements_table). Guards
 * Admin\PromotionManagementController::notifyIfActive() so a promotion only
 * ever notifies guests once, the first time it's actually active - a later
 * edit to an already-notified, still-active promotion must not re-blast
 * every guest again. Nullable, not backfilled: every promotion that existed
 * before this migration simply has notified_at = null and will notify on its
 * next store()/update()/toggle() if it's currently active, exactly the same
 * one-time-notify behavior a brand new promotion gets.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('promotions', function (Blueprint $table) {
            $table->timestamp('notified_at')->nullable()->after('status');
        });
    }

    public function down(): void
    {
        Schema::table('promotions', function (Blueprint $table) {
            $table->dropColumn('notified_at');
        });
    }
};
