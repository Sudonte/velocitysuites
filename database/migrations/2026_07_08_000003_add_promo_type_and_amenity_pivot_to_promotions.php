<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * Promotions become typed: a "discount" promo carries the existing
     * discount_type/discount_value, while an "amenity" promo instead
     * bundles included amenities (with quantities) for its room type.
     * Discount columns turn nullable since amenity promos don't use them.
     */
    public function up(): void
    {
        Schema::table('promotions', function (Blueprint $table) {
            $table->enum('promo_type', ['discount', 'amenity'])->default('discount')->after('promo_name');
        });

        // Existing rows are all discount promos; the enum default covers them.
        Schema::table('promotions', function (Blueprint $table) {
            $table->enum('discount_type', ['percentage', 'fixed'])->nullable()->default(null)->change();
            $table->decimal('discount_value', 10, 2)->nullable()->change();
        });

        Schema::create('promotion_amenity', function (Blueprint $table) {
            $table->id();
            $table->foreignId('promotion_id')->constrained()->cascadeOnDelete();
            $table->foreignId('amenity_id')->constrained()->cascadeOnDelete();
            $table->integer('quantity')->default(1);
            $table->timestamps();
            $table->unique(['promotion_id', 'amenity_id']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('promotion_amenity');

        Schema::table('promotions', function (Blueprint $table) {
            $table->enum('discount_type', ['percentage', 'fixed'])->default('percentage')->change();
            $table->decimal('discount_value', 10, 2)->nullable(false)->change();
            $table->dropColumn('promo_type');
        });
    }
};
