<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * One guest submission can ask for different amenities for different rooms. Each (room, amenity) pair is already one
     * amenity_requests row (room_id + amenity_id, its own status); batch_ref only ties the rows of ONE submission together
     * so the front desk and the guest can see them as one request. Nullable: older rows simply have no batch.
     */
    public function up(): void
    {
        Schema::table('amenity_requests', function (Blueprint $table) {
            $table->string('batch_ref', 36)->nullable()->after('origin')->index();
        });
    }

    public function down(): void
    {
        Schema::table('amenity_requests', function (Blueprint $table) {
            $table->dropIndex(['batch_ref']);
            $table->dropColumn('batch_ref');
        });
    }
};
