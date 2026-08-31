<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Backs the automatic archiving of amenity requests that have sat
     * Completed for a week or more (see
     * Console\Commands\ArchiveCompletedAmenityRequests) - a visibility flag
     * only, moving a row out of the receptionist's active list into a
     * separate read-only Archived view. Never a delete, so billing/reports/
     * reservations referencing these rows are entirely unaffected.
     */
    public function up(): void
    {
        Schema::table('amenity_requests', function (Blueprint $table) {
            $table->timestamp('archived_at')->nullable()->after('status');
        });
    }

    public function down(): void
    {
        Schema::table('amenity_requests', function (Blueprint $table) {
            $table->dropColumn('archived_at');
        });
    }
};
