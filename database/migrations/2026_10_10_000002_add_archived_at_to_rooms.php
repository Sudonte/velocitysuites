<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Archived rooms are kept (with every booking that ever used them) but leave
 * inventory: they are excluded from availability, assignment and room counts.
 * A timestamp rather than SoftDeletes so historical relations
 * ($booking->room) keep resolving without withTrashed() everywhere.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('rooms', function (Blueprint $table) {
            $table->timestamp('archived_at')->nullable()->index();
        });
    }

    public function down(): void
    {
        Schema::table('rooms', function (Blueprint $table) {
            $table->dropColumn('archived_at');
        });
    }
};
