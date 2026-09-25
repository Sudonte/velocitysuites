<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * Coverage for the email-change OTP flow added this session
 * (Api\ProfileController::requestEmailChange/confirmEmailChange,
 * App\Services\EmailChangeService). Before this, email was a directly
 * writable field on the generic profile-update endpoint with no
 * verification of any kind - these tests exist to make sure that gap
 * cannot silently reopen.
 */
class EmailChangeSecurityTest extends TestCase
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
            $table->timestamp('last_login_at')->nullable();
            $table->timestamp('deleted_at')->nullable();
            $table->timestamp('restore_deadline')->nullable();
            $table->timestamp('deactivated_at')->nullable();
            $table->rememberToken();
            $table->timestamps();
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
        Schema::dropIfExists('users');
        parent::tearDown();
    }

    private function authAsGuest(string $password = 'correct-password'): array
    {
        $user = User::factory()->create([
            'role' => 'guest',
            'status' => 'active',
            'password' => Hash::make($password),
        ]);

        $plainToken = 'test-token-' . $user->id;
        DB::table('api_tokens')->insert([
            'user_id' => $user->id,
            'token' => hash('sha256', $plainToken),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return [$user, ['Authorization' => "Bearer {$plainToken}"]];
    }

    public function test_generic_profile_update_no_longer_accepts_email(): void
    {
        [$user, $headers] = $this->authAsGuest();
        $originalEmail = $user->email;

        $this->withHeaders($headers)->putJson('/api/guest/profile', [
            'first_name' => 'Changed',
            'last_name' => $user->last_name,
            'email' => 'sneaky-takeover@example.com',
        ]);

        $this->assertSame($originalEmail, $user->refresh()->email);
    }

    public function test_request_email_change_requires_correct_current_password(): void
    {
        [$user, $headers] = $this->authAsGuest('correct-password');

        $response = $this->withHeaders($headers)->postJson('/api/guest/profile/email/request', [
            'new_email' => 'new-address@example.com',
            'current_password' => 'wrong-password',
        ]);

        $response->assertStatus(422);
        $this->assertDatabaseMissing('email_change_requests', ['user_id' => $user->id]);
    }

    public function test_request_email_change_rejects_already_taken_address(): void
    {
        [$user, $headers] = $this->authAsGuest('correct-password');
        $other = User::factory()->create(['role' => 'guest']);

        $response = $this->withHeaders($headers)->postJson('/api/guest/profile/email/request', [
            'new_email' => $other->email,
            'current_password' => 'correct-password',
        ]);

        $response->assertStatus(422);
    }

    public function test_full_request_then_confirm_flow_changes_email(): void
    {
        [$user, $headers] = $this->authAsGuest('correct-password');

        $this->withHeaders($headers)->postJson('/api/guest/profile/email/request', [
            'new_email' => 'new-address@example.com',
            'current_password' => 'correct-password',
        ])->assertStatus(200);

        // Wrong OTP must not apply the change.
        $this->withHeaders($headers)->postJson('/api/guest/profile/email/confirm', [
            'otp' => '000000',
        ])->assertStatus(422);
        $this->assertNotSame('new-address@example.com', $user->refresh()->email);

        // Read back the real hashed OTP is not possible from the test side
        // (by design) - instead confirm the request row exists with the
        // right pending address, then drive the same confirm() logic the
        // controller uses via the service directly to get a known OTP,
        // mirroring how the live production verification substituted a
        // known OTP at the storage layer rather than reading a real email.
        $row = DB::table('email_change_requests')->where('user_id', $user->id)->first();
        $this->assertNotNull($row);
        $this->assertSame('new-address@example.com', $row->new_email);

        DB::table('email_change_requests')->where('user_id', $user->id)->update([
            'otp' => Hash::make('123456'),
        ]);

        $this->withHeaders($headers)->postJson('/api/guest/profile/email/confirm', [
            'otp' => '123456',
        ])->assertStatus(200);

        $this->assertSame('new-address@example.com', $user->refresh()->email);
        $this->assertDatabaseMissing('email_change_requests', ['user_id' => $user->id]);
    }

    public function test_expired_otp_is_rejected(): void
    {
        [$user, $headers] = $this->authAsGuest('correct-password');

        DB::table('email_change_requests')->insert([
            'user_id' => $user->id,
            'new_email' => 'new-address@example.com',
            'otp' => Hash::make('123456'),
            'created_at' => now()->subMinutes(20),
        ]);

        $response = $this->withHeaders($headers)->postJson('/api/guest/profile/email/confirm', [
            'otp' => '123456',
        ]);

        $response->assertStatus(422);
        $this->assertNotSame('new-address@example.com', $user->refresh()->email);
    }
}
