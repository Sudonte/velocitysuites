<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->enum('role', ['admin', 'manager', 'receptionist', 'guest'])->default('guest')->after('email');
            $table->enum('status', ['active', 'suspended'])->default('active')->after('role');
            $table->integer('failed_login_attempts')->default(0)->after('password');
            $table->timestamp('last_login_at')->nullable()->after('failed_login_attempts');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn(['role', 'status', 'failed_login_attempts', 'last_login_at']);
        });
    }
};
