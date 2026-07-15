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
        // The Android app collects a senior-citizen/PWD ID (for a 20%
        // discount) and named additional-guest details at booking time -
        // neither had a column to land in, so that data was silently
        // dropped after the request completed.
        Schema::table('reservations', function (Blueprint $table) {
            $table->string('id_card_type')->nullable()->after('children');
            $table->string('id_card_image_path')->nullable()->after('id_card_type');
            $table->json('additional_guest_details')->nullable()->after('id_card_image_path');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('reservations', function (Blueprint $table) {
            $table->dropColumn(['id_card_type', 'id_card_image_path', 'additional_guest_details']);
        });
    }
};
