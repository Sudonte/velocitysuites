<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Personal details of receptionists and managers (home address, date of
 * birth, gender, contact number) in their own tables, each referencing the
 * user account - the same pattern guests already have (`guests`). Existing
 * staff accounts are backfilled from whatever was on their `users` row.
 */
return new class extends Migration
{
    public function up(): void
    {
        foreach (['receptionists' => 'receptionist', 'managers' => 'manager'] as $table => $role) {
            Schema::create($table, function (Blueprint $t) {
                $t->id();
                $t->foreignId('user_id')->unique()->constrained('users')->cascadeOnDelete();
                $t->string('street', 255)->nullable();
                $t->string('barangay', 100)->nullable();
                $t->string('city', 100)->nullable();
                $t->string('province', 100)->nullable();
                $t->date('date_of_birth')->nullable();
                $t->enum('gender', ['male', 'female'])->nullable();
                $t->string('mobile_number', 20)->nullable()->unique();
                $t->timestamps();
            });

            $now = now();
            DB::table('users')->where('role', $role)->orderBy('id')->get()->each(function ($user) use ($table, $now) {
                DB::table($table)->insert([
                    'user_id' => $user->id,
                    'street' => $user->street ?? null,
                    'barangay' => $user->barangay ?? null,
                    'city' => $user->city ?? null,
                    'province' => $user->province ?? null,
                    'date_of_birth' => $user->date_of_birth ?? null,
                    'gender' => in_array($user->gender ?? null, ['male', 'female'], true) ? $user->gender : null,
                    // a number already used by another staff profile of the same role is left out rather than failing the migration
                    'mobile_number' => ($user->mobile_number ?? null) && ! DB::table($table)->where('mobile_number', $user->mobile_number)->exists() ? $user->mobile_number : null,
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('managers');
        Schema::dropIfExists('receptionists');
    }
};
