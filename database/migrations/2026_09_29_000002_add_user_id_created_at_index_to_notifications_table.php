<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The guest/staff notification list query (Api\NotificationController::index(),
 * NotificationController::index()) is `WHERE user_id = ? ORDER BY created_at DESC` -
 * the existing notifications_user_id_is_read_index (see
 * 2026_09_29_000000_add_perf_indexes_for_notifications_and_bookings) covers the
 * unread-badge COUNT query but not this ORDER BY, which still falls back to a
 * filesort per request. This composite index matches the list query's actual
 * filter+sort shape.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('notifications', function (Blueprint $table) {
            $table->index(['user_id', 'created_at'], 'notifications_user_id_created_at_index');
        });
    }

    public function down(): void
    {
        Schema::table('notifications', function (Blueprint $table) {
            $table->dropIndex('notifications_user_id_created_at_index');
        });
    }
};
