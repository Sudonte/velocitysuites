<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Adds structured address columns to `guests`, mirroring the ones already
 * added to `users` for staff (2026_08_10_130000_add_profile_fields_to_users_table.php).
 * The mobile app has always sent country/region/province/city/barangay/street/zip_code
 * as separate fields on both registration and profile update (see
 * AddressHierarchyController.getStructuredValues() / ProfileUpdateRequest), but
 * neither Api\AuthController::verifyOtp() nor Api\ProfileController::update() had
 * anywhere to persist them - only the composed `address` string was ever saved,
 * so those fields were silently dropped and Api\ProfileController::show() could
 * never return real guest.region/province/city/barangay/street/zip_code values
 * (the Android app's ProfileManagementActivity has always expected exactly this
 * shape - see its `body.guest.region` reads - falling back to locally-cached
 * SharedPreferences since the server never actually had this data).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('guests', function (Blueprint $table) {
            $table->string('country', 100)->nullable()->after('address');
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
        Schema::table('guests', function (Blueprint $table) {
            $table->dropColumn([
                'country', 'region', 'province', 'city', 'barangay', 'street', 'zip_code',
            ]);
        });
    }
};
