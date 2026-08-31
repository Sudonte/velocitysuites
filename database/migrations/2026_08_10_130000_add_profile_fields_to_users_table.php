<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Adds staff-profile fields (age/gender/DOB/mobile/structured address) to
 * `users` directly, since these don't live on the `guests` table (which is
 * guest-role-specific, linked via user_id) - the System Administrator has
 * no Guest row. Field names match the mobile app's existing structured
 * address convention (country/region/province/city/barangay/street/zip_code -
 * see RegisterRequest.java / AddressSelection) so the same shape is usable
 * from any role in the future, though only the admin profile screen uses
 * them today.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->unsignedTinyInteger('age')->nullable()->after('restore_deadline');
            $table->enum('gender', ['male', 'female'])->nullable()->after('age');
            $table->date('date_of_birth')->nullable()->after('gender');
            $table->string('mobile_number', 20)->nullable()->after('date_of_birth');
            $table->string('country', 100)->nullable()->after('mobile_number');
            $table->string('region', 100)->nullable()->after('country');
            $table->string('province', 100)->nullable()->after('region');
            $table->string('city', 100)->nullable()->after('province');
            $table->string('barangay', 100)->nullable()->after('city');
            $table->string('street', 255)->nullable()->after('barangay');
            $table->string('zip_code', 20)->nullable()->after('street');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn([
                'age', 'gender', 'date_of_birth', 'mobile_number',
                'country', 'region', 'province', 'city', 'barangay', 'street', 'zip_code',
            ]);
        });
    }
};
