<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/** Additive only: an approved request that was never used (or whose temporary password ran out) is closed as "expired" when the person asks again. */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('staff_password_reset_requests') && DB::getDriverName() === 'mysql') {
            DB::statement("ALTER TABLE staff_password_reset_requests MODIFY status ENUM('pending','approved','rejected','completed','expired') NOT NULL DEFAULT 'pending'");
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('staff_password_reset_requests') && DB::getDriverName() === 'mysql') {
            DB::table('staff_password_reset_requests')->where('status', 'expired')->update(['status' => 'rejected']);
            DB::statement("ALTER TABLE staff_password_reset_requests MODIFY status ENUM('pending','approved','rejected','completed') NOT NULL DEFAULT 'pending'");
        }
    }
};
