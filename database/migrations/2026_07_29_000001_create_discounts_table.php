<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Discounts (Senior Citizen, PWD, Student, etc.) are a genuinely
     * separate mechanism from Promotions - no shared logic or records.
     * A guest can only REQUEST one (uploading an ID for verification, see
     * reservations.discount_requested/id_document_path); the receptionist
     * is the only one who picks a specific Discount and applies it, at
     * billing time, after verifying the ID.
     */
    public function up(): void
    {
        Schema::create('discounts', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->enum('discount_type', ['percentage', 'fixed']);
            $table->decimal('value', 10, 2);
            $table->text('description')->nullable();
            $table->enum('status', ['active', 'inactive'])->default('active');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('discounts');
    }
};
