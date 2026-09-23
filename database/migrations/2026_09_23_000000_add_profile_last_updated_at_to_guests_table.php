<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Authoritative server-side timestamp for the 30-day Personal/Contact &
 * Address profile-update cooldown (Api\ProfileController::update()) -
 * null means the guest has never completed a profile-info update and is
 * immediately eligible. The pre-existing guests.profile_edit_used column
 * is a dead field (not referenced anywhere in app/database/routes prior
 * to this change - confirmed via a full-codebase search) that never
 * actually enforced anything; this is a new, separate field rather than
 * repurposing it, since a one-time boolean can't express a rolling
 * 30-day window the way a timestamp can. profile_edit_used itself is
 * left untouched/unused rather than dropped, to avoid an unrelated
 * destructive schema change.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('guests', function (Blueprint $table) {
            $table->timestamp('profile_last_updated_at')->nullable()->after('profile_edit_used');
        });
    }

    public function down(): void
    {
        Schema::table('guests', function (Blueprint $table) {
            $table->dropColumn('profile_last_updated_at');
        });
    }
};
