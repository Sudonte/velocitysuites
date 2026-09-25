<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * Coverage for the mail-delivery-honesty fix added this session: every
 * OTP-sending path previously called Mail::raw() inside a try/catch that
 * silently logged and swallowed any failure, so the controller always
 * told the user "code sent" regardless of whether it actually was -
 * discovered via a real, ongoing production mail outage (Hostinger's
 * local relay refusing connections since 2026-09-24) where every guest
 * requesting a password reset was told success while receiving nothing.
 * These tests force Mail::raw() to throw and confirm the user now gets
 * an honest failure message instead.
 */
class MailFailureHonestyTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Schema::create('users', function (Blueprint $table) {
            $table->id();
            $table->string('first_name', 100);
            $table->string('last_name', 100);
            $table->string('middle_name', 100)->nullable();
            $table->string('email')->unique();
            $table->enum('role', ['admin', 'manager', 'receptionist', 'guest'])->default('guest');
            $table->string('status', 20)->default('active');
            $table->timestamp('email_verified_at')->nullable();
            $table->string('password');
            $table->integer('failed_login_attempts')->default(0);
            $table->boolean('must_change_password')->default(false);
            $table->timestamp('last_login_at')->nullable();
            $table->timestamp('deleted_at')->nullable();
            $table->timestamp('restore_deadline')->nullable();
            $table->timestamp('deactivated_at')->nullable();
            $table->rememberToken();
            $table->timestamps();
        });

        Schema::create('password_reset_tokens', function (Blueprint $table) {
            $table->string('email')->primary();
            $table->string('token');
            $table->timestamp('created_at')->nullable();
        });

        Schema::create('email_change_requests', function (Blueprint $table) {
            $table->unsignedBigInteger('user_id')->primary();
            $table->string('new_email');
            $table->string('otp');
            $table->timestamp('created_at')->nullable();
        });

        Schema::create('guests', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('user_id')->nullable();
            $table->timestamps();
        });

        Schema::create('activity_logs', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('user_id')->nullable();
            $table->string('action');
            $table->text('description')->nullable();
            $table->string('ip_address')->nullable();
            $table->timestamps();
        });

        Schema::create('api_tokens', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('user_id');
            $table->string('token')->unique();
            $table->string('device_name')->nullable();
            $table->timestamp('last_used_at')->nullable();
            $table->timestamps();
        });
    }

    protected function tearDown(): void
    {
        Schema::dropIfExists('api_tokens');
        Schema::dropIfExists('activity_logs');
        Schema::dropIfExists('guests');
        Schema::dropIfExists('email_change_requests');
        Schema::dropIfExists('password_reset_tokens');
        Schema::dropIfExists('users');
        parent::tearDown();
    }

    private function makeGuest(string $password = 'correct-password'): User
    {
        return User::factory()->create([
            'role' => 'guest',
            'status' => 'active',
            'password' => Hash::make($password),
        ]);
    }

    public function test_web_forgot_password_reports_failure_when_mail_send_throws(): void
    {
        $user = $this->makeGuest();
        Mail::shouldReceive('raw')->once()->andThrow(new \Exception('simulated SMTP outage'));

        $response = $this->post('/forgot-password', ['email' => $user->email]);

        // Must NOT redirect to the OTP-entry screen claiming success -
        // that would strand the guest on a screen for a code that will
        // never arrive.
        $response->assertSessionHas('error');
        $response->assertSessionMissing('status');
    }

    public function test_web_registration_reports_failure_when_mail_send_throws(): void
    {
        Mail::shouldReceive('raw')->once()->andThrow(new \Exception('simulated SMTP outage'));

        $response = $this->withSession(['registration_data' => [
            'email' => 'newguest@example.com',
            'otp' => '000000',
            'otp_created_at' => now(),
        ]])->post('/resend-otp', ['email' => 'newguest@example.com']);

        $response->assertSessionHas('error');
        $response->assertSessionMissing('success');
    }

    public function test_api_email_change_request_reports_failure_when_mail_send_throws(): void
    {
        $user = $this->makeGuest();
        \App\Models\Guest::create(['user_id' => $user->id]);

        $plainToken = 'test-token-' . $user->id;
        \Illuminate\Support\Facades\DB::table('api_tokens')->insert([
            'user_id' => $user->id,
            'token' => hash('sha256', $plainToken),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        Mail::shouldReceive('raw')->once()->andThrow(new \Exception('simulated SMTP outage'));

        $response = $this->withHeaders(['Authorization' => "Bearer {$plainToken}"])
            ->postJson('/api/guest/profile/email/request', [
                'new_email' => 'new-address@example.com',
                'current_password' => 'correct-password',
            ]);

        // Must be an honest failure, not the normal 200 "code sent" response.
        $response->assertStatus(422);
        $this->assertStringContainsString("couldn't", strtolower($response->json('message')));
    }
}
