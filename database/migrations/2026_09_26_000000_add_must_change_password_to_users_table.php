<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Replaces "is this account still on the shared DEFAULT_STAFF_PASSWORD
     * constant" (a Hash::check against one known value) with an explicit,
     * per-account flag - see Admin\UserManagementController/
     * Admin\PasswordResetRequestController/Auth\ForcePasswordChangeController,
     * all updated in this same change to generate a random temporary
     * password per account/reset instead of reusing one constant.
     *
     * Backfills true for any account that happens to be sitting on that
     * shared default RIGHT NOW (created or reset but never yet logged in
     * to set a real password) so nobody's pending forced-change state is
     * silently lost by this migration. Defaults to false for everyone
     * else - no existing legitimately-logged-in staff account is affected.
     */
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->boolean('must_change_password')->default(false)->after('failed_login_attempts');
        });

        DB::table('users')->select('id', 'password')->whereIn('role', ['manager', 'receptionist'])->get()
            ->each(function ($row) {
                if (Hash::check(\App\Http\Controllers\Admin\UserManagementController::DEFAULT_STAFF_PASSWORD, $row->password)) {
                    DB::table('users')->where('id', $row->id)->update(['must_change_password' => true]);
                }
            });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('must_change_password');
        });
    }
};
