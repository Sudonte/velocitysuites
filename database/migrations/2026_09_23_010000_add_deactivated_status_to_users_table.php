<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Widens users.status from ENUM('active','suspended') to a plain VARCHAR so
 * it can also hold 'deactivated' (voluntary guest self-deactivation - see
 * Api\ProfileController::deactivateAccount()/Api\AuthController's OTP
 * reactivation flow), without an ALTER ... MODIFY ENUM(...) migration every
 * time a new status is needed. Raw SQL rather than Blueprint::change()
 * because doctrine/dbal isn't installed in this environment.
 *
 * Deliberately a distinct concept from the existing users.deleted_at /
 * restore_deadline 30-day-then-purge mechanism (see
 * 2026_08_10_120000_add_account_deletion_fields_to_users_table.php) - that
 * one is left completely untouched. 'deactivated' has no expiry and no
 * purge; only the guest themselves can lift it, via OTP.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement("ALTER TABLE users MODIFY status VARCHAR(20) NOT NULL DEFAULT 'active'");

        Schema::table('users', function (Blueprint $table) {
            $table->timestamp('deactivated_at')->nullable()->after('restore_deadline');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('deactivated_at');
        });

        DB::statement("UPDATE users SET status = 'active' WHERE status NOT IN ('active', 'suspended')");
        DB::statement("ALTER TABLE users MODIFY status ENUM('active','suspended') NOT NULL DEFAULT 'active'");
    }
};
