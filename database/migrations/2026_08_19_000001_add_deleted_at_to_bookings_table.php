<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Soft-delete gate for the receptionist's "Delete" action on a
     * rejected/cancelled booking - never a hard DELETE (same no-hard-
     * delete convention as everything else in routes/web.php), just an
     * additional, stronger removal than hidden_at that also drops the row
     * out of every default query (route-model binding included) via
     * Eloquent's SoftDeletes trait.
     */
    public function up(): void
    {
        Schema::table('bookings', function (Blueprint $table) {
            $table->softDeletes()->after('hidden_at');
        });
    }

    public function down(): void
    {
        Schema::table('bookings', function (Blueprint $table) {
            $table->dropSoftDeletes();
        });
    }
};
