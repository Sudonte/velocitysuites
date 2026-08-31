<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Application-level uniqueness (RegisterController::register(), Api\AuthController::register(),
 * ProfileController::update(), Api\ProfileController::update()) already rejects duplicate mobile
 * numbers, but only a DB-level constraint actually closes the race-condition window the user
 * asked for - two concurrent registrations with the same number could otherwise both pass
 * validation before either finishes writing. NULL values (no mobile_number yet) are unaffected -
 * MySQL/MariaDB unique indexes allow multiple NULLs.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('guests', function (Blueprint $table) {
            $table->unique('mobile_number');
        });
    }

    public function down(): void
    {
        Schema::table('guests', function (Blueprint $table) {
            $table->dropUnique(['mobile_number']);
        });
    }
};
