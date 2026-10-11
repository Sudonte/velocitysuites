<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Descriptions are free text with no required length: an additional
 * charge's description becomes optional and unlimited (text, nullable).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('additional_charges', function (Blueprint $table) {
            $table->text('description')->nullable()->change();
        });
    }

    public function down(): void
    {
        Schema::table('additional_charges', function (Blueprint $table) {
            $table->string('description')->default('')->change();
        });
    }
};
