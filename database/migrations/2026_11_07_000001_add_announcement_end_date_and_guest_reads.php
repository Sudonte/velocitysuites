<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Additive only: an optional end date and an "edited at" stamp on announcements, and the per-guest read state of the website's
 * System notifications (one row per guest and announcement; a row older than the announcement's last edit counts as unread again).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('announcements', function (Blueprint $table) {
            if (! Schema::hasColumn('announcements', 'ends_at')) {
                $table->date('ends_at')->nullable()->after('published_at');
            }
            if (! Schema::hasColumn('announcements', 'last_edited_at')) {
                $table->timestamp('last_edited_at')->nullable()->after('notified_at');
            }
        });

        if (! Schema::hasTable('announcement_reads')) {
            Schema::create('announcement_reads', function (Blueprint $table) {
                $table->id();
                $table->foreignId('user_id')->constrained()->cascadeOnDelete();
                $table->foreignId('announcement_id')->constrained()->cascadeOnDelete();
                $table->timestamp('read_at')->useCurrent();
                $table->unique(['user_id', 'announcement_id']);
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('announcement_reads');
        Schema::table('announcements', function (Blueprint $table) {
            foreach (['ends_at', 'last_edited_at'] as $c) {
                if (Schema::hasColumn('announcements', $c)) {
                    $table->dropColumn($c);
                }
            }
        });
    }
};
