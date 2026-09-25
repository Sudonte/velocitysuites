<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * One pending request per user (primary key on user_id, same
     * updateOrInsert-to-invalidate-the-previous-one pattern as
     * password_reset_tokens) - a fresh request always supersedes an
     * earlier unconfirmed one rather than allowing two to coexist.
     */
    public function up(): void
    {
        Schema::create('email_change_requests', function (Blueprint $table) {
            $table->unsignedBigInteger('user_id')->primary();
            $table->string('new_email');
            $table->string('otp');
            $table->timestamp('created_at')->nullable();

            $table->foreign('user_id')->references('id')->on('users')->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('email_change_requests');
    }
};
