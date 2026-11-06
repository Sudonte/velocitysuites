<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Additive only: when the temporary password an administrator handed to a Manager/Receptionist stops working (24 hours after it was issued). */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('users', 'temporary_password_expires_at')) {
            Schema::table('users', function (Blueprint $table) {
                $table->timestamp('temporary_password_expires_at')->nullable()->after('must_change_password');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('users', 'temporary_password_expires_at')) {
            Schema::table('users', function (Blueprint $table) {
                $table->dropColumn('temporary_password_expires_at');
            });
        }
    }
};
