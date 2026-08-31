<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * A room's description is no longer its own stored value - it always
     * comes from its Room Type (Room::getDescriptionAttribute()), so every
     * room stays in sync automatically whenever the type's description
     * changes, with no batch-update step needed. This column is now dead.
     */
    public function up(): void
    {
        Schema::table('rooms', function (Blueprint $table) {
            $table->dropColumn('description');
        });
    }

    public function down(): void
    {
        Schema::table('rooms', function (Blueprint $table) {
            $table->text('description')->nullable()->after('rate_override');
        });
    }
};
