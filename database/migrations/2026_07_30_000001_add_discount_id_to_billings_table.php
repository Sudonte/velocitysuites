<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('billings', function (Blueprint $table) {
            $table->foreignId('discount_id')->nullable()->after('discount')->constrained()->nullOnDelete();
            $table->foreignId('discount_verified_by')->nullable()->after('discount_id')->constrained('users')->nullOnDelete();
            $table->timestamp('discount_verified_at')->nullable()->after('discount_verified_by');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('billings', function (Blueprint $table) {
            $table->dropConstrainedForeignId('discount_id');
            $table->dropConstrainedForeignId('discount_verified_by');
            $table->dropColumn('discount_verified_at');
        });
    }
};
