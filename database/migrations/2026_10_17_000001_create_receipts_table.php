<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Permanent receipts. Each booking gets at most ONE receipt of each type - 1 booking verification, 2 check-in payment,
 * 3 check-out final - and the row keeps a frozen copy (snapshot) of everything the receipt showed when it was issued.
 * Nothing here is ever recomputed or updated; issuing Receipt 2 or 3 never touches Receipt 1 or 2. The unique key on
 * (booking_id, receipt_type) is what makes a double click or a repeated request harmless. New table only - existing
 * data is untouched, and receipts issued before this table existed keep working through the previous code path.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('receipts', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('booking_id')->index();
            $table->unsignedTinyInteger('receipt_type'); // 1, 2, 3
            $table->string('receipt_no', 40)->unique();
            $table->timestamp('issued_at');
            $table->unsignedBigInteger('issued_by')->nullable();
            $table->decimal('total_amount', 12, 2)->default(0);
            $table->decimal('total_paid', 12, 2)->default(0);
            $table->decimal('remaining_balance', 12, 2)->default(0);
            $table->json('snapshot');
            $table->timestamps();

            $table->unique(['booking_id', 'receipt_type']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('receipts');
    }
};
