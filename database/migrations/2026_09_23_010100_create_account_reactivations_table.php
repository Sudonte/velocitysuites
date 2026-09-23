<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * One pending reactivation challenge per user (unique on user_id - issuing a
 * new one always replaces the last, invalidating it). Deliberately its own
 * table rather than reusing registration_otps/password_reset_tokens - an OTP
 * issued for one purpose must never verify another (see
 * Services\AccountReactivationService's own docblock). reactivation_token is
 * the only thing the Android client holds between login() and
 * reactivate-verify/-resend - never a raw user id or email, so a client
 * can't submit an arbitrary account.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('account_reactivations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->unique()->constrained()->cascadeOnDelete();
            $table->string('email');
            $table->string('reactivation_token', 64)->unique();
            $table->string('otp_hash');
            $table->unsignedTinyInteger('attempts')->default(0);
            $table->timestamp('expires_at');
            $table->timestamp('last_sent_at');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('account_reactivations');
    }
};
