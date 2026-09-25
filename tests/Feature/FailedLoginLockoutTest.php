<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * Regression coverage for the failed-login lockout off-by-one fixed in
 * this session: the threshold check must fire on the 3rd failed attempt
 * itself (same request that increments the counter to 3), not only on a
 * subsequent 4th request. Covers both the mobile API (Api\AuthController)
 * and the web login (Auth\LoginController), since both were independently
 * patched with the same pattern.
 *
 * Builds the `users`/`password_reset_tokens` tables directly (mirroring
 * production's actual column list) instead of RefreshDatabase, because
 * this app's full migration history includes several MySQL-only raw
 * `UPDATE ... JOIN` / `ALTER ... MODIFY ENUM` statements that don't run on
 * the sqlite connection phpunit.xml configures for tests - out of scope to
 * rewrite here, so this test sidesteps the full history entirely.
 */
class FailedLoginLockoutTest extends TestCase
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

        Schema::create('password_reset_tokens', function (Blueprint $table) {
            $table->string('email')->primary();
            $table->string('token');
            $table->timestamp('created_at')->nullable();
        });
    }

    protected function tearDown(): void
    {
        Schema::dropIfExists('password_reset_tokens');
        Schema::dropIfExists('users');
        parent::tearDown();
    }

    private function makeGuest(): User
    {
        return User::factory()->create([
            'role' => 'guest',
            'status' => 'active',
            'password' => Hash::make('correct-password'),
            'failed_login_attempts' => 0,
        ]);
    }

    public function test_api_login_locks_on_third_failed_attempt_not_fourth(): void
    {
        $user = $this->makeGuest();

        $r1 = $this->postJson('/api/login', ['email' => $user->email, 'password' => 'wrong']);
        $r1->assertStatus(401);
        $this->assertSame(1, $user->refresh()->failed_login_attempts);

        $r2 = $this->postJson('/api/login', ['email' => $user->email, 'password' => 'wrong']);
        $r2->assertStatus(401);
        $r2->assertJsonFragment(['message' => 'Invalid credentials. One more failed attempt will require you to verify your account by email.']);
        $this->assertSame(2, $user->refresh()->failed_login_attempts);

        // This is the exact bug: the 3rd failed attempt itself must lock,
        // not fall through to a generic 401 the way a 4th attempt would.
        $r3 = $this->postJson('/api/login', ['email' => $user->email, 'password' => 'wrong']);
        $r3->assertStatus(423);
        $this->assertSame(3, $user->refresh()->failed_login_attempts);

        // Even the CORRECT password must not bypass the lock once tripped.
        $r4 = $this->postJson('/api/login', ['email' => $user->email, 'password' => 'correct-password']);
        $r4->assertStatus(423);
    }

    public function test_web_login_locks_on_third_failed_attempt_not_fourth(): void
    {
        $user = $this->makeGuest();

        $this->post('/login', ['email' => $user->email, 'password' => 'wrong']);
        $this->assertSame(1, $user->refresh()->failed_login_attempts);

        $this->post('/login', ['email' => $user->email, 'password' => 'wrong']);
        $this->assertSame(2, $user->refresh()->failed_login_attempts);

        $response = $this->post('/login', ['email' => $user->email, 'password' => 'wrong']);
        $this->assertSame(3, $user->refresh()->failed_login_attempts);
        $response->assertRedirect(route('password.request'));
    }
}
