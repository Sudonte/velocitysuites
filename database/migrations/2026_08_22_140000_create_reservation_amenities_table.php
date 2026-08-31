<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Captures which Paid/Additional amenities (and what quantity) a guest
     * selected at booking time, with a historical price/name snapshot -
     * see ReservationAmenity's docblock. This never touches the
     * reservation/booking's own price columns; the base room price is
     * computed and stored exactly as it already was before this table
     * existed. Also the sole backend source of truth for what a guest may
     * later submit an AmenityRequest for (2026_08_22_150000 wires that up).
     */
    public function up(): void
    {
        Schema::create('reservation_amenities', function (Blueprint $table) {
            $table->id();
            $table->foreignId('reservation_id')->constrained()->cascadeOnDelete();
            $table->foreignId('amenity_id')->nullable()->constrained()->nullOnDelete();
            $table->string('amenity_name');
            $table->decimal('charge', 10, 2);
            $table->integer('quantity');
            $table->decimal('subtotal', 10, 2);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('reservation_amenities');
    }
};
