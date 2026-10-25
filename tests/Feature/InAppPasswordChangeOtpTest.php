<?php

namespace Tests\Feature;

use App\Http\Controllers\Api\AuthController;
use App\Models\User;
use App\Services\PasswordResetService;
use Carbon\Carbon;
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
 * The signed-in guest's password change is gated server-side: a correct OTP yields a short-lived,
 * single-use token bound to that account, wrong codes are capped, and the resend has a cooldown.
 */
class InAppPasswordChangeOtpTest extends TestCase
{
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
            $table->unsignedInteger('failed_login_attempts')->default(0);
            $table->timestamp('last_failed_login_at')->nullable();
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
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function guest(string $email = 'guest@example.test'): User
    {
        $user = User::create(['first_name' => 'G', 'last_name' => 'T', 'email' => $email, 'password' => 'OldPassword1', 'role' => 'guest']);
        $user->apiTokens()->create(['token' => hash('sha256', 'device-1')]);
        $user->apiTokens()->create(['token' => hash('sha256', 'device-2')]);

        return $user;
    }

    private function seedOtp(string $email, string $otp = '123456'): void
    {
        DB::table('password_reset_tokens')->updateOrInsert(['email' => $email], ['token' => Hash::make($otp), 'created_at' => now()]);
    }

    private function verify(string $email, string $otp)
    {
        return app(AuthController::class)->verifyResetOtp(Request::create('/verify-reset-otp', 'POST', ['email' => $email, 'otp' => $otp]));
    }

    private function change(User $user, array $payload)
    {
        $request = Request::create('/guest/password/change', 'POST', $payload);
        $request->setUserResolver(fn () => $user);

        return app(AuthController::class)->changePasswordWithToken($request);
    }

    private function verifiedToken(User $user, string $otp = '123456'): string
    {
        $this->seedOtp($user->email, $otp);
        $response = $this->verify($user->email, $otp);
        $this->assertSame(200, $response->getStatusCode());

        return $response->getData(true)['reset_token'];
    }

    public function test_verifying_the_otp_issues_a_token_and_the_token_changes_the_password_once(): void
    {
        $user = $this->guest();
        $token = $this->verifiedToken($user);

        $response = $this->change($user, ['reset_token' => $token, 'password' => 'BrandNew123', 'password_confirmation' => 'BrandNew123']);
        $this->assertSame(200, $response->getStatusCode());
        $this->assertTrue(Hash::check('BrandNew123', $user->fresh()->password));
        $this->assertSame(0, $user->apiTokens()->count(), 'every device is signed out, as before');

        $again = $this->change($user, ['reset_token' => $token, 'password' => 'Another12345', 'password_confirmation' => 'Another12345']);
        $this->assertSame(422, $again->getStatusCode(), 'the token works exactly once');
        $this->assertTrue(Hash::check('BrandNew123', $user->fresh()->password));
    }

    public function test_a_wrong_code_is_refused_and_issues_no_token(): void
    {
        $user = $this->guest();
        $this->seedOtp($user->email, '123456');

        $response = $this->verify($user->email, '654321');

        $this->assertSame(422, $response->getStatusCode());
        $this->assertArrayNotHasKey('reset_token', $response->getData(true));
        $this->assertTrue(Hash::check('OldPassword1', $user->fresh()->password));
    }

    public function test_a_code_that_was_already_used_cannot_be_used_again(): void
    {
        $user = $this->guest();
        $this->verifiedToken($user, '123456'); // verifying consumes the code

        $again = $this->verify($user->email, '123456');

        $this->assertSame(422, $again->getStatusCode(), 'the same code does not verify twice');
        $this->assertArrayNotHasKey('reset_token', $again->getData(true));
    }

    public function test_an_expired_code_is_refused_and_issues_no_token(): void
    {
        $user = $this->guest();
        $this->seedOtp($user->email, '123456');
        Carbon::setTestNow(now()->addMinutes(16));

        $response = $this->verify($user->email, '123456');

        $this->assertSame(422, $response->getStatusCode());
        $this->assertArrayNotHasKey('reset_token', $response->getData(true));
    }

    public function test_the_password_cannot_be_changed_without_a_verified_otp(): void
    {
        $user = $this->guest();
        $this->seedOtp($user->email); // a code was sent, but never verified

        $this->assertSame(422, $this->change($user, ['reset_token' => 'made-up-token', 'password' => 'BrandNew123', 'password_confirmation' => 'BrandNew123'])->getStatusCode());
        $this->assertSame(422, $this->change($user, ['reset_token' => '123456', 'password' => 'BrandNew123', 'password_confirmation' => 'BrandNew123'])->getStatusCode(), 'the OTP itself is not a token');
        try {
            $this->change($user, ['password' => 'BrandNew123', 'password_confirmation' => 'BrandNew123']);
            $this->fail('a request with no token must be rejected');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('reset_token', $e->errors());
        }
        $this->assertTrue(Hash::check('OldPassword1', $user->fresh()->password));
    }

    public function test_a_token_is_bound_to_the_account_it_was_issued_for(): void
    {
        $owner = $this->guest('owner@example.test');
        $other = $this->guest('other@example.test');
        $token = $this->verifiedToken($owner);

        $this->assertSame(422, $this->change($other, ['reset_token' => $token, 'password' => 'BrandNew123', 'password_confirmation' => 'BrandNew123'])->getStatusCode());
        $this->assertTrue(Hash::check('OldPassword1', $other->fresh()->password));
    }

    public function test_the_token_expires_after_ten_minutes(): void
    {
        Carbon::setTestNow('2026-10-12 12:00:00');
        $user = $this->guest();
        $token = $this->verifiedToken($user);

        Carbon::setTestNow('2026-10-12 12:10:30');
        $this->assertSame(422, $this->change($user, ['reset_token' => $token, 'password' => 'BrandNew123', 'password_confirmation' => 'BrandNew123'])->getStatusCode());
        $this->assertTrue(Hash::check('OldPassword1', $user->fresh()->password));
    }

    public function test_the_server_applies_the_same_password_rule_the_app_shows(): void
    {
        $user = $this->guest();
        $token = $this->verifiedToken($user);

        foreach ([['Short1', 'Short1'], ['LongEnough1', 'Different99']] as [$password, $confirm]) {
            try {
                $this->change($user, ['reset_token' => $token, 'password' => $password, 'password_confirmation' => $confirm]);
                $this->fail('rejected');
            } catch (ValidationException $e) {
                $this->assertArrayHasKey('password', $e->errors());
            }
        }
        // A rejected password does not burn the verification.
        $this->assertSame(200, $this->change($user, ['reset_token' => $token, 'password' => 'exactly8', 'password_confirmation' => 'exactly8'])->getStatusCode());
    }

    public function test_five_wrong_codes_destroy_the_otp_even_for_the_right_code_afterwards(): void
    {
        $user = $this->guest();
        $this->seedOtp($user->email, '123456');

        for ($i = 1; $i <= 4; $i++) {
            $this->assertSame(422, $this->verify($user->email, '000000')->getStatusCode());
        }
        $this->assertSame(429, $this->verify($user->email, '000000')->getStatusCode(), 'the 5th wrong code locks it');
        $this->assertSame(429, $this->verify($user->email, '123456')->getStatusCode(), 'the correct code no longer works');
        $this->assertDatabaseMissing('password_reset_tokens', ['email' => $user->email]);
    }

    public function test_requesting_a_new_code_restores_the_attempt_budget(): void
    {
        $user = $this->guest();
        $this->seedOtp($user->email, '123456');
        for ($i = 0; $i < 5; $i++) {
            $this->verify($user->email, '000000');
        }

        Carbon::setTestNow(now()->addSeconds(31));
        $sent = app(AuthController::class)->forgotPassword(Request::create('/forgot-password', 'POST', ['email' => $user->email]));
        $this->assertSame(200, $sent->getStatusCode());

        $this->seedOtp($user->email, '654321');
        $this->assertSame(200, $this->verify($user->email, '654321')->getStatusCode());
    }

    public function test_an_otp_expires_after_fifteen_minutes(): void
    {
        Carbon::setTestNow('2026-10-12 12:00:00');
        $user = $this->guest();
        $this->seedOtp($user->email, '123456');

        Carbon::setTestNow('2026-10-12 12:16:00');
        $this->assertSame(422, $this->verify($user->email, '123456')->getStatusCode());
    }

    public function test_resending_a_code_has_a_thirty_second_cooldown(): void
    {
        $user = $this->guest();
        $controller = app(AuthController::class);
        $request = fn () => Request::create('/forgot-password', 'POST', ['email' => $user->email]);

        $this->assertSame(200, $controller->forgotPassword($request())->getStatusCode());
        $this->assertSame(429, $controller->forgotPassword($request())->getStatusCode());
        // An unregistered address behaves identically (200 first, 429 right after), so the cooldown leaks nothing.
        $ghost = fn () => Request::create('/forgot-password', 'POST', ['email' => 'nobody@example.test']);
        $this->assertSame(200, $controller->forgotPassword($ghost())->getStatusCode());
        $this->assertSame(429, $controller->forgotPassword($ghost())->getStatusCode());

        Carbon::setTestNow(now()->addSeconds(31));
        $this->assertSame(200, $controller->forgotPassword($request())->getStatusCode());
    }
}
