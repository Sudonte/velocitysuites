<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Who decided the guest's discount ID at check-in, and why it was rejected. Additive only: both columns are
 * nullable, so every existing booking keeps its data (older decisions simply have no reason / decider).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('bookings', function (Blueprint $table) {
            $table->text('discount_rejection_reason')->nullable();
            $table->foreignId('discount_decided_by')->nullable()->constrained('users')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('bookings', function (Blueprint $table) {
            $table->dropConstrainedForeignId('discount_decided_by');
            $table->dropColumn('discount_rejection_reason');
        });
    }
};
