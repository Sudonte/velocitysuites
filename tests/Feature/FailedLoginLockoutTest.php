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
 * subsequent 4th request. Covers the mobile API (Api\AuthController,
 * guest-only) and the web login (Auth\LoginController, shared by all four
 * roles - there is no per-role branching in the counter logic itself, only
 * in the post-login redirect and the recovery flow), since both were
 * independently patched with the same pattern.
 *
 * Builds the users/password_reset_tokens tables directly (mirroring
 * production's actual column list) instead of RefreshDatabase, because
 * this app's full migration history includes several MySQL-only raw
 * UPDATE JOIN / ALTER MODIFY ENUM statements that do not run on the
 * sqlite connection phpunit.xml configures for tests.
 */
class FailedLoginLockoutTest extends TestCase
{
    // Each test gets its own fresh in-memory sqlite database (see Tests\TestCase), so the tables built in
    // setUp() vanish on their own - nothing ever needs to drop them by hand.

    protected function setUp(): void
    {
        parent::setUp();

        // An unknown email is checked against sign-ups still waiting for their code.
        Schema::create('registration_otps', function (Blueprint $table) {
            $table->id();
            $table->string('email')->unique();
            $table->string('otp', 6);
            $table->json('payload');
            $table->timestamp('expires_at');
            $table->timestamps();
        });

        // Sign-in/out actions are written to the audit trail.
        Schema::create('activity_logs', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('user_id')->nullable();
            $table->string('action');
            $table->text('description')->nullable();
            $table->string('subject_type')->nullable();
            $table->unsignedBigInteger('subject_id')->nullable();
            $table->string('ip_address')->nullable();
            $table->timestamps();
        });

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
            $table->timestamp('last_failed_login_at')->nullable();
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

        Schema::create('staff_password_reset_requests', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id');
            $table->string('status', 20)->default('pending');
            $table->foreignId('processed_by')->nullable();
            $table->timestamp('processed_at')->nullable();
            $table->string('rejection_reason')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();
        });
    }

    private function makeUser(string $role): User
    {
        return User::factory()->create([
            'role' => $role,
            'status' => 'active',
            'password' => Hash::make('correct-password'),
            'failed_login_attempts' => 0,
        ]);
    }

    public function test_api_login_locks_silently_on_the_third_failure_and_says_so_only_to_the_right_password(): void
    {
        $user = $this->makeUser('guest');

        foreach ([1, 2, 3] as $n) {
            $r = $this->postJson('/api/login', ['email' => $user->email, 'password' => 'wrong']);
            $r->assertStatus(401)->assertJsonFragment(['code' => 'INVALID_CREDENTIALS', 'message' => 'Incorrect email or password.']);
            $this->assertSame($n, $user->refresh()->failed_login_attempts);
        }

        // locked now: a wrong guess still gets the one shared answer ...
        $this->postJson('/api/login', ['email' => $user->email, 'password' => 'wrong'])
            ->assertStatus(401)->assertJsonFragment(['code' => 'INVALID_CREDENTIALS']);
        // ... and only the correct password learns the account is locked (and does not get in)
        $this->postJson('/api/login', ['email' => $user->email, 'password' => 'correct-password'])
            ->assertStatus(423)->assertJsonFragment(['code' => 'ACCOUNT_LOCKED']);
    }

    public function test_api_login_stops_comparing_guesses_after_a_few_more_failures_during_a_lock(): void
    {
        $user = $this->makeUser('guest');
        foreach (range(1, 6) as $i) {
            $this->postJson('/api/login', ['email' => $user->email, 'password' => 'wrong'])->assertStatus(401);
        }
        $this->assertSame(6, $user->refresh()->failed_login_attempts);
        // six failures: even the right password is no longer compared (a lock cannot be used to keep guessing)
        $this->postJson('/api/login', ['email' => $user->email, 'password' => 'correct-password'])
            ->assertStatus(401)->assertJsonFragment(['code' => 'INVALID_CREDENTIALS']);
    }

    public function test_api_login_gives_the_same_answer_for_an_unknown_email_and_a_wrong_password(): void
    {
        $user = $this->makeUser('guest');

        $unknown = $this->postJson('/api/login', ['email' => 'nobody-here@example.test', 'password' => 'whatever1']);
        $wrong = $this->postJson('/api/login', ['email' => $user->email, 'password' => 'wrong']);

        $unknown->assertStatus(401);
        $wrong->assertStatus(401);
        $this->assertSame($unknown->getContent(), $wrong->getContent(), 'identical body, status and code');
        $unknown->assertExactJson(['code' => 'INVALID_CREDENTIALS', 'message' => 'Incorrect email or password.']);
    }

    public function test_api_login_answers_an_unknown_email_about_as_slowly_as_a_wrong_password(): void
    {
        $user = $this->makeUser('guest');
        $time = function (string $email) {
            $start = hrtime(true);
            $this->postJson('/api/login', ['email' => $email, 'password' => 'wrong-guess-1']);

            return (hrtime(true) - $start) / 1e6;
        };
        $time('warm-up@example.test');
        $unknown = min($time('nobody-1@example.test'), $time('nobody-2@example.test'), $time('nobody-3@example.test'));
        $known = min($time($user->email), $time($user->email), $time($user->email));
        $this->assertGreaterThan(0.5 * $known, $unknown, "unknown email {$unknown}ms vs wrong password {$known}ms: the unknown one must still pay for a password check");
    }

    public function test_api_login_blocked_and_staff_accounts_are_only_told_after_the_right_password(): void
    {
        $suspended = $this->makeUser('guest');
        $suspended->update(['status' => 'suspended']);
        $this->postJson('/api/login', ['email' => $suspended->email, 'password' => 'wrong'])
            ->assertStatus(401)->assertJsonFragment(['code' => 'INVALID_CREDENTIALS']);
        $this->postJson('/api/login', ['email' => $suspended->email, 'password' => 'correct-password'])
            ->assertStatus(403)->assertJsonFragment(['code' => 'ACCOUNT_BLOCKED']);

        $staff = $this->makeUser('receptionist');
        $this->postJson('/api/login', ['email' => $staff->email, 'password' => 'wrong'])->assertStatus(401)->assertJsonFragment(['code' => 'INVALID_CREDENTIALS']);
        $this->postJson('/api/login', ['email' => $staff->email, 'password' => 'correct-password'])->assertStatus(403);
    }

    public function test_web_login_gives_one_message_and_never_warns_or_locks_visibly_on_a_wrong_password(): void
    {
        $user = $this->makeUser('guest');

        $this->post('/login', ['email' => 'nobody@example.test', 'password' => 'wrong']);
        $messages = [session('error')];
        foreach ([1, 2, 3] as $n) {
            $r = $this->post('/login', ['email' => $user->email, 'password' => 'wrong']);
            $r->assertRedirect();
            $this->assertNotSame(route('password.request'), $r->headers->get('Location'), 'no visible lock on a wrong password');
            $messages[] = session('error');
            $this->assertSame($n, $user->refresh()->failed_login_attempts);
        }
        $this->assertCount(1, array_unique($messages), 'the same message for an unknown email, every wrong guess and the lock');

        // the right password on a locked account is told, and does not get in
        $locked = $this->post('/login', ['email' => $user->email, 'password' => 'correct-password']);
        $locked->assertRedirect(route('password.request'));
        $this->assertGuest();
    }

    public function test_web_login_locks_staff_roles_the_same_way(): void
    {
        foreach (['receptionist', 'manager', 'admin'] as $role) {
            $user = $this->makeUser($role);
            foreach ([1, 2, 3] as $i) {
                $this->post('/login', ['email' => $user->email, 'password' => 'wrong']);
            }
            $this->assertSame(3, $user->refresh()->failed_login_attempts, "role={$role}");
            $blocked = $this->post('/login', ['email' => $user->email, 'password' => 'correct-password']);
            $blocked->assertRedirect(route('password.request'));
        }
    }

    public function test_staff_forgot_password_creates_approval_request_not_otp(): void
    {
        foreach (['receptionist', 'manager'] as $role) {
            $user = $this->makeUser($role);

            $response = $this->post('/forgot-password', ['email' => $user->email]);

            $response->assertRedirect(route('password.staff-request.status', ['email' => $user->email]));
            $this->assertDatabaseHas('staff_password_reset_requests', [
                'user_id' => $user->id,
                'status' => 'pending',
            ]);
            $this->assertDatabaseMissing('password_reset_tokens', ['email' => $user->email]);
        }
    }

    public function test_admin_forgot_password_uses_otp_not_approval_request(): void
    {
        $user = $this->makeUser('admin');

        $response = $this->post('/forgot-password', ['email' => $user->email]);

        $response->assertRedirect(route('password.otp.form', ['email' => $user->email]));
        $this->assertDatabaseHas('password_reset_tokens', ['email' => $user->email]);
        $this->assertDatabaseMissing('staff_password_reset_requests', ['user_id' => $user->id]);
    }

    public function test_old_failures_are_forgotten_so_one_typo_never_locks(): void
    {
        // Two failures left over from days ago (the reported case: a counter
        // that never expired locked the account on the next single typo).
        $user = $this->makeUser('manager');
        $user->forceFill(['failed_login_attempts' => 2, 'last_failed_login_at' => now()->subDays(6)])->save();

        $response = $this->post('/login', ['email' => $user->email, 'password' => 'wrong']);

        $response->assertRedirect();
        $this->assertNotSame(route('password.request'), $response->headers->get('Location'));
        $this->assertSame(1, $user->refresh()->failed_login_attempts);
    }

    public function test_stale_lock_no_longer_blocks_the_correct_password(): void
    {
        $user = $this->makeUser('manager');
        $user->forceFill(['failed_login_attempts' => 3, 'last_failed_login_at' => now()->subDays(6)])->save();

        $this->post('/login', ['email' => $user->email, 'password' => 'correct-password']);

        $this->assertAuthenticatedAs($user);
        $this->assertSame(0, $user->refresh()->failed_login_attempts);
    }

    public function test_lockout_lifts_after_the_window(): void
    {
        $user = $this->makeUser('guest');
        foreach (range(1, 3) as $i) {
            $this->postJson('/api/login', ['email' => $user->email, 'password' => 'wrong']);
        }
        $this->postJson('/api/login', ['email' => $user->email, 'password' => 'correct-password'])->assertStatus(423);

        $this->travel(User::LOGIN_FAILURE_WINDOW_MINUTES + 1)->minutes();

        $this->assertFalse($user->refresh()->isLoginLocked());
        $this->post('/login', ['email' => $user->email, 'password' => 'correct-password']);
        $this->assertAuthenticatedAs($user);
    }

    public function test_sign_in_attempts_are_written_to_the_audit_trail(): void
    {
        $user = $this->makeUser('receptionist');

        $this->post('/login', ['email' => $user->email, 'password' => 'wrong']);
        $this->post('/login', ['email' => $user->email, 'password' => 'correct-password']);
        $this->post('/logout');

        $actions = \App\Models\ActivityLog::where('user_id', $user->id)->orderBy('id')->pluck('action')->all();
        $this->assertSame(['Failed login attempt', 'Logged in', 'Logged out'], $actions);
    }
}
