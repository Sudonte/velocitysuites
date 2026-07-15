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
        // DB-backed counterpart to the session-based registration_data flow
        // used by the web RegisterController - a mobile client has no
        // server session to stash pending-registration fields in between
        // the register and verify-otp requests.
        Schema::create('registration_otps', function (Blueprint $table) {
            $table->id();
            $table->string('email')->unique();
            $table->string('otp', 6);
            $table->json('payload');
            $table->timestamp('expires_at');
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('registration_otps');
    }
};
