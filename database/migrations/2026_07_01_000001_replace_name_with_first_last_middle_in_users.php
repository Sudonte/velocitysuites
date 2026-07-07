<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * Replace the single 'name' column with first_name, last_name, middle_name
     */
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            // Drop the single 'name' column
            $table->dropColumn('name');
        });

        Schema::table('users', function (Blueprint $table) {
            // Add the new name columns
            $table->string('first_name', 100)->after('id');
            $table->string('last_name', 100)->after('first_name');
            $table->string('middle_name', 100)->nullable()->after('last_name');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            // Drop the new name columns
            $table->dropColumn(['first_name', 'last_name', 'middle_name']);
        });

        Schema::table('users', function (Blueprint $table) {
            // Restore the original 'name' column
            $table->string('name');
        });
    }
};