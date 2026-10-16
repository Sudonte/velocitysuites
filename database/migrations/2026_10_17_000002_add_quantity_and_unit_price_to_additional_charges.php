<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * An additional / other / damage charge is a line item: description, quantity, unit price, line total (the existing
 * `amount`). Both new columns are optional, so every existing charge keeps working unchanged (quantity 1, no unit price).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('additional_charges', function (Blueprint $table) {
            $table->unsignedInteger('quantity')->default(1)->after('description');
            $table->decimal('unit_price', 10, 2)->nullable()->after('quantity');
        });
    }

    public function down(): void
    {
        Schema::table('additional_charges', function (Blueprint $table) {
            $table->dropColumn(['quantity', 'unit_price']);
        });
    }
};
