<?php

namespace Tests\Feature;

use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Auth\ForcePasswordChangeController;
use App\Http\Controllers\Guest\GuestController;
use App\Http\Controllers\ProfileController;
use App\Models\User;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

/**
 * One rule for a NEW password everywhere - sign-up, forgot-password reset, the app's change-password, the web forms and the
 * forced change: 8+ characters with a letter and a number. Existing passwords are never re-judged (they keep working), and
 * "Forgot password" gives the same answer whether or not the email has an account.
 */
class PasswordRuleEverywhereTest extends TestCase
{
    private const WEAK = ['short1', 'allletters', '12345678', 'abc12'];
    private const GOOD = 'Sunrise2026';

    protected function setUp(): void
    {
        parent::setUp();
        Mail::fake();
        Cache::flush();

        Schema::create('users', function (Blueprint $table) {
            $table->id();
            $table->string('first_name', 100)->default('A');
            $table->string('last_name', 100)->default('B');
            $table->string('email')->unique();
            $table->string('role', 20)->default('guest');
            $table->string('status', 20)->default('active');
            $table->string('password')->default('x');
            $table->boolean('must_change_password')->default(false);
            $table->unsignedInteger('failed_login_attempts')->default(0);
            $table->timestamp('last_failed_login_at')->nullable();
            $table->timestamp('last_login_at')->nullable();
            $table->timestamp('deleted_at')->nullable();
            $table->timestamp('restore_deadline')->nullable();
            $table->timestamp('deactivated_at')->nullable();
            $table->rememberToken();
            $table->timestamps();
        });
        Schema::create('guests', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('user_id');
            foreach (['mobile_number', 'gender', 'date_of_birth', 'address', 'profile_picture', 'country', 'region', 'province', 'city', 'barangay', 'street', 'zip_code', 'timezone'] as $col) {
                $table->string($col)->nullable();
            }
            $table->integer('age')->nullable();
            $table->timestamps();
        });
        Schema::create('api_tokens', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('user_id');
            $table->string('token');
            $table->string('device_name')->nullable();
            $table->timestamp('last_used_at')->nullable();
            $table->timestamp('expires_at')->nullable();
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
    }

    private function user(string $email = 'guest@example.test', string $role = 'guest', string $password = 'OldPassword1'): User
    {
        $user = User::create(['first_name' => 'G', 'last_name' => 'T', 'email' => $email, 'password' => $password, 'role' => $role]);
        $user->apiTokens()->create(['token' => hash('sha256', 'device-1')]);

        return $user;
    }

    private function seedOtp(string $email, string $otp = '123456'): void
    {
        DB::table('password_reset_tokens')->updateOrInsert(['email' => $email], ['token' => Hash::make($otp), 'created_at' => now()]);
    }

    private function as(User $user, string $path = '/x', array $payload = []): Request
    {
        $request = Request::create($path, 'POST', $payload);
        $request->setUserResolver(fn () => $user);
        $this->actingAs($user);

        return $request;
    }

    // ---- forgot password: one answer ----

    public function test_forgot_password_answers_the_same_for_an_unknown_email_a_guest_and_a_staff_account(): void
    {
        $guest = $this->user('guest@example.test');
        $staff = $this->user('desk@example.test', 'receptionist');

        $answers = [];
        foreach (['nobody@example.test', $guest->email, $staff->email] as $email) {
            Cache::flush(); // the per-address cooldown is not what is under test here
            $r = $this->postJson('/api/forgot-password', ['email' => $email]);
            $r->assertOk();
            $answers[] = $r->getContent();
        }
        $this->assertCount(1, array_unique($answers), 'identical body for every address');
        $this->assertSame("If an account exists for that email, we've sent a code.", json_decode($answers[0], true)['message']);
    }

    public function test_the_forgot_password_cooldown_is_the_same_for_a_known_and_an_unknown_address(): void
    {
        $guest = $this->user('guest@example.test');
        foreach (['nobody@example.test', $guest->email] as $email) {
            $this->postJson('/api/forgot-password', ['email' => $email])->assertOk();
            $second = $this->postJson('/api/forgot-password', ['email' => $email]);
            $second->assertStatus(429);
        }
    }

    public function test_the_web_forgot_password_form_does_not_reveal_whether_the_email_exists(): void
    {
        $guest = $this->user('guest@example.test');

        $unknown = $this->post('/forgot-password', ['email' => 'nobody@example.test']);
        $known = $this->post('/forgot-password', ['email' => $guest->email]);

        $unknown->assertSessionHasNoErrors();
        $unknown->assertRedirect(route('password.otp.form', ['email' => 'nobody@example.test']));
        $known->assertRedirect(route('password.otp.form', ['email' => $guest->email]));
        $this->assertSame(\App\Http\Controllers\Auth\ForgotPasswordController::SENT_MESSAGE, session('status'));
        $unknown->assertSessionHas('status', \App\Http\Controllers\Auth\ForgotPasswordController::SENT_MESSAGE);
    }

    // ---- reset (forgot password) ----

    public function test_the_app_reset_needs_the_rule_but_the_old_password_is_never_judged(): void
    {
        $user = $this->user('guest@example.test', 'guest', 'allletters'); // an old weak password keeps working
        $this->assertTrue(Hash::check('allletters', $user->password));

        foreach (self::WEAK as $weak) {
            $this->seedOtp($user->email);
            $r = $this->postJson('/api/reset-password', ['email' => $user->email, 'otp' => '123456', 'password' => $weak, 'password_confirmation' => $weak]);
            $r->assertStatus(422)->assertJsonValidationErrors('password');
        }
        $this->assertTrue(Hash::check('allletters', $user->fresh()->password), 'nothing changed');

        $this->seedOtp($user->email);
        $this->postJson('/api/reset-password', ['email' => $user->email, 'otp' => '123456', 'password' => self::GOOD, 'password_confirmation' => self::GOOD])
            ->assertOk()->assertJsonStructure(['token']);
        $this->assertTrue(Hash::check(self::GOOD, $user->fresh()->password));
    }

    public function test_the_web_reset_needs_the_rule_and_does_not_say_whether_the_email_exists(): void
    {
        $user = $this->user('guest@example.test');
        foreach (self::WEAK as $weak) {
            $this->seedOtp($user->email);
            $this->post('/reset-password', ['email' => $user->email, 'otp' => '123456', 'password' => $weak, 'password_confirmation' => $weak])
                ->assertSessionHasErrors('password');
        }
        $this->assertTrue(Hash::check('OldPassword1', $user->fresh()->password));

        // an unknown email gets the same refusal as a wrong code (no "selected email is invalid")
        $r = $this->post('/reset-password', ['email' => 'nobody@example.test', 'otp' => '123456', 'password' => self::GOOD, 'password_confirmation' => self::GOOD]);
        $r->assertSessionHasNoErrors();
        $this->assertSame('Invalid or expired code.', session('error'));

        $this->seedOtp($user->email);
        $this->post('/reset-password', ['email' => $user->email, 'otp' => '123456', 'password' => self::GOOD, 'password_confirmation' => self::GOOD])->assertSessionHasNoErrors();
        $this->assertTrue(Hash::check(self::GOOD, $user->fresh()->password));
    }

    // ---- change password ----

    public function test_the_apps_change_password_needs_the_rule(): void
    {
        $user = $this->user('guest@example.test');
        $controller = app(AuthController::class);

        foreach (self::WEAK as $i => $weak) {
            $otp = (string) (222220 + $i);
            $this->seedOtp($user->email, $otp);
            $verified = $controller->verifyResetOtp(Request::create('/v', 'POST', ['email' => $user->email, 'otp' => $otp]));
            $token = $verified->getData(true)['reset_token'];
            try {
                $controller->changePasswordWithToken($this->as($user, '/c', ['reset_token' => $token, 'password' => $weak, 'password_confirmation' => $weak]));
                $this->fail("$weak was accepted");
            } catch (ValidationException $e) {
                $this->assertArrayHasKey('password', $e->errors());
            }
            $this->assertTrue(Hash::check('OldPassword1', $user->fresh()->password));
        }

        $this->seedOtp($user->email);
        $token = $controller->verifyResetOtp(Request::create('/v', 'POST', ['email' => $user->email, 'otp' => '123456']))->getData(true)['reset_token'];
        $ok = $controller->changePasswordWithToken($this->as($user, '/c', ['reset_token' => $token, 'password' => self::GOOD, 'password_confirmation' => self::GOOD]));
        $this->assertSame(200, $ok->getStatusCode());
        $this->assertTrue(Hash::check(self::GOOD, $user->fresh()->password));
    }

    public function test_the_web_change_password_forms_need_the_rule(): void
    {
        foreach ([[GuestController::class, 'guest'], [ProfileController::class, 'admin']] as [$class, $role]) {
            $user = $this->user($role . '@example.test', $role);
            foreach (self::WEAK as $weak) {
                try {
                    app($class)->changePassword($this->as($user, '/p', ['otp' => '123456', 'new_password' => $weak, 'new_password_confirmation' => $weak]));
                    $this->fail("$class accepted $weak");
                } catch (ValidationException $e) {
                    $this->assertArrayHasKey('new_password', $e->errors(), $class);
                }
            }
            // a good password gets past this rule (the missing code is what stops it here)
            $this->seedOtp($user->email, '111111');
            $response = app($class)->changePassword($this->as($user, '/p', ['otp' => '123456', 'new_password' => self::GOOD, 'new_password_confirmation' => self::GOOD]));
            $this->assertSame('Invalid or expired verification code.', session('errors')->first('otp') ?? $response->getSession()?->get('errors')?->first('otp'));
            $this->assertTrue(Hash::check('OldPassword1', $user->fresh()->password));
        }
    }

    public function test_the_forced_change_needs_the_rule(): void
    {
        $user = $this->user('desk@example.test', 'receptionist');
        $user->update(['must_change_password' => true]);
        foreach (self::WEAK as $weak) {
            try {
                app(ForcePasswordChangeController::class)->update($this->as($user, '/f', ['password' => $weak, 'password_confirmation' => $weak]));
                $this->fail("$weak was accepted");
            } catch (ValidationException $e) {
                $this->assertArrayHasKey('password', $e->errors());
            }
        }
        $this->assertTrue($user->fresh()->must_change_password);

        app(ForcePasswordChangeController::class)->update($this->as($user, '/f', ['password' => self::GOOD, 'password_confirmation' => self::GOOD]));
        $this->assertTrue(Hash::check(self::GOOD, $user->fresh()->password));
        $this->assertFalse((bool) $user->fresh()->must_change_password);
    }

    public function test_existing_passwords_keep_working_whatever_they_look_like(): void
    {
        $weakOld = $this->user('old@example.test', 'guest', 'allletters');
        $this->assertTrue(Hash::check('allletters', $weakOld->password));
        // signing in compares, it never judges: an old all-letters password still gets a token
        $this->postJson('/api/login', ['email' => 'old@example.test', 'password' => 'allletters'])->assertOk()->assertJsonStructure(['token']);
    }
}
