<?php

namespace Tests\Feature;

use App\Models\User;
use Carbon\Carbon;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * Guest web: Step 1 arriving with rooms (same as app 1.72.3), the Featured rooms type filter, and the three-step password reset
 * (one generic answer, the code mailed the moment the response is sent - never through a queue -, wrong/expired codes, the password
 * rule, signing in after the reset). The browser behaviour of Step 1 and the chips is checked in headless Chrome; here the contract
 * the scripts rely on and the wording are pinned.
 */
class WebStepOneFeaturedAndResetTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
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
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function user(string $email, string $role = 'guest'): User
    {
        return User::create(['first_name' => 'J', 'last_name' => 'Doe', 'email' => $email, 'password' => 'OldPassword1', 'role' => $role]);
    }

    private function ask(string $email)
    {
        return $this->postJson('/api/forgot-password', ['email' => $email]);
    }

    // ---------------------------------------------------------------- reset: one answer, code sent immediately

    public function test_every_address_gets_the_same_answer_and_only_a_registered_guest_gets_a_code(): void
    {
        $sent = [];
        Mail::shouldReceive('send')->andReturnUsing(function ($views, $data, $cb) use (&$sent) { $sent[] = $data['code']; });
        Mail::shouldReceive('raw')->andReturnNull();
        $this->user('guest@example.test');
        $this->user('desk@example.test', 'receptionist');

        $a = $this->ask('guest@example.test');
        $b = $this->ask('nobody@example.test');
        $c = $this->ask('desk@example.test');
        foreach ([$a, $b, $c] as $r) {
            $r->assertOk();
        }
        $this->assertSame($a->json('message'), $b->json('message'));
        $this->assertSame($a->json('message'), $c->json('message'));
        $this->assertCount(1, array_unique($sent), 'one mail: the registered guest only (unknown and staff addresses get nothing)');
        $this->assertDatabaseHas('password_reset_tokens', ['email' => 'guest@example.test']);
        $this->assertDatabaseMissing('password_reset_tokens', ['email' => 'nobody@example.test']);
        $this->assertDatabaseMissing('password_reset_tokens', ['email' => 'desk@example.test']);
    }

    public function test_the_code_is_stored_and_mailed_with_the_request_never_queued(): void
    {
        Queue::fake();
        $sentData = null;
        Mail::shouldReceive('send')->once()->andReturnUsing(function ($views, $data, $cb) use (&$sentData) { $sentData = $data; });
        $this->user('guest@example.test');

        $this->ask('guest@example.test')->assertOk();

        $this->assertNotNull($sentData, 'the mail went out as part of the request');
        Queue::assertNothingPushed();
        $this->assertMatchesRegularExpression('/^\d{6}$/', $sentData['code'], 'the mail carries a 6-digit code');
        $stored = DB::table('password_reset_tokens')->where('email', 'guest@example.test')->value('token');
        $this->assertTrue(Hash::check($sentData['code'], $stored), 'the code in the mail is the stored one');
        $this->assertSame('J', $sentData['name'], 'addressed by first name');
    }

    public function test_the_mail_leaves_after_the_response_so_the_answer_takes_no_longer_for_a_registered_address(): void
    {
        $service = file_get_contents(app_path('Services/PasswordResetService.php'));
        $controller = file_get_contents(app_path('Http/Controllers/Api/AuthController.php'));
        $this->assertStringContainsString('app()->terminating(fn () => $this->deliverOtp($user, $otp))', $service);
        $this->assertStringContainsString('$this->passwordReset->sendOtp($user, true);', $controller);
        $this->assertStringContainsString("'smtp_ms'", $service, 'the SMTP time is logged (never the code)');
    }

    public function test_a_wrong_code_and_an_expired_code_are_refused_and_the_right_code_is_accepted(): void
    {
        Mail::shouldReceive('raw')->andReturnNull();
        Mail::shouldReceive('send')->andReturnNull();
        $this->user('guest@example.test');
        $this->ask('guest@example.test')->assertOk();
        DB::table('password_reset_tokens')->where('email', 'guest@example.test')->update(['token' => Hash::make('123456'), 'created_at' => now()]);

        $this->postJson('/api/verify-reset-otp', ['email' => 'guest@example.test', 'otp' => '000000'])->assertStatus(422)->assertJsonPath('message', 'Invalid or expired code.');
        $this->postJson('/api/verify-reset-otp', ['email' => 'guest@example.test', 'otp' => '123456'])->assertOk();

        DB::table('password_reset_tokens')->where('email', 'guest@example.test')->update(['token' => Hash::make('654321'), 'created_at' => now()]);
        Carbon::setTestNow(now()->addMinutes(16));
        $this->postJson('/api/verify-reset-otp', ['email' => 'guest@example.test', 'otp' => '654321'])->assertStatus(422)->assertJsonPath('message', 'Invalid or expired code.');
    }

    public function test_the_new_password_must_follow_the_rule_and_the_guest_is_signed_in_after_the_reset(): void
    {
        $u = $this->user('guest@example.test');
        DB::table('password_reset_tokens')->updateOrInsert(['email' => 'guest@example.test'], ['token' => Hash::make('123456'), 'created_at' => now()]);

        $weak = ['email' => 'guest@example.test', 'otp' => '123456', 'password' => 'short', 'password_confirmation' => 'short', 'device_name' => 'Web'];
        $this->postJson('/api/reset-password', $weak)->assertStatus(422);
        $letters = array_merge($weak, ['password' => 'onlyletters', 'password_confirmation' => 'onlyletters']);
        $this->postJson('/api/reset-password', $letters)->assertStatus(422);
        $mismatch = array_merge($weak, ['password' => 'NewPassword9', 'password_confirmation' => 'Different9']);
        $this->postJson('/api/reset-password', $mismatch)->assertStatus(422);

        DB::table('password_reset_tokens')->updateOrInsert(['email' => 'guest@example.test'], ['token' => Hash::make('123456'), 'created_at' => now()]);
        $ok = $this->postJson('/api/reset-password', array_merge($weak, ['password' => 'NewPassword9', 'password_confirmation' => 'NewPassword9']));
        $ok->assertOk();
        $this->assertNotEmpty($ok->json('token'), 'the answer carries the sign-in token the page trades for a web session');
        $this->assertTrue(Hash::check('NewPassword9', $u->fresh()->password));
        $this->assertDatabaseMissing('password_reset_tokens', ['email' => 'guest@example.test']);
        $this->postJson('/api/reset-password', array_merge($weak, ['password' => 'AnotherPass9', 'password_confirmation' => 'AnotherPass9']))->assertStatus(422);
    }

    // ---------------------------------------------------------------- the page and its script

    public function test_the_forgot_password_page_has_the_login_header_and_the_three_steps(): void
    {
        $html = $this->get('/forgot-password')->assertOk()->getContent();
        $this->assertStringContainsString('images/logo.jpg', $html);
        $this->assertStringContainsString('Velocity Suites', $html);
        $this->assertStringContainsString('Your comfort is our service', $html);
        $this->assertStringContainsString('vsFStep', $html);
        $js = file_get_contents(public_path('js/portal/auth.js'));
        foreach (['Reset your password', 'Email address', 'Enter the email registered to your Velocity Suites account', 'Send code',
            'sent a 6-digit code', 'Enter the code', 'We sent a 6-digit code to ', 'Change email', 'Resend code', 'Create a new password', 'New password',
            'Confirm new password', 'vs-strength', 'Save and sign in', 'Password changed. Welcome back!', 'save.disabled = !(pwOk(np.value) && same)', "webPost('/auth/reset/verify'", 'otp.invalid()'] as $needle) {
            $this->assertStringContainsString($needle, $js, $needle);
        }
        $this->assertStringContainsString('vs_flash', file_get_contents(public_path('js/portal/shell.js')), 'the welcome message shows after the sign-in');
    }

    // ---------------------------------------------------------------- Step 1 and Featured rooms (contract + wording)

    public function test_step_one_arriving_with_rooms_follows_the_apps_wording_and_order(): void
    {
        $wiz = file_get_contents(public_path('js/portal/wizard.js'));
        foreach (['Choose your dates', 'Pick check-in and check-out for the rooms you selected', 'Selected rooms', 'Choose your dates to see the total',
            'Remove the selected rooms?', 'Confirm these dates', 'Add another date', 'Confirm these dates to continue', 'Choose your dates first', 'Choose at least one room',
            'is free for these dates, so your selection was lowered from', 'is not free for these dates, so it was removed from your selection', 'n.disabled = !!reason',
            'if (!S.stays.length) main.insertBefore(ed, list)', 'sv.chosen'] as $needle) {
            $this->assertStringContainsString($needle, $wiz, $needle);
        }
        // no night count and no total before dates: the card prints the total line only when the dates are valid
        $this->assertStringContainsString("(ok ? VS.esc(VS.plural(count, 'room') + ' \\u00d7 ' + VS.plural(n, 'night') + ' = ' + VS.money(total)) : 'Choose your dates to see the total')", $wiz);
        $this->assertStringContainsString("per night</span></div>'", $wiz);
        // the pre-filled dates count as chosen, and a changed date is saved at once
        $rooms = file_get_contents(public_path('js/portal/rooms.js'));
        $this->assertStringContainsString('sel.markChosen = function', $rooms);
        $this->assertGreaterThanOrEqual(4, substr_count($rooms, 'markChosen()'));
        $this->assertStringContainsString('chosen: st.chosen', $rooms);
    }

    public function test_the_featured_rooms_have_a_room_type_dropdown_on_one_row_with_the_dates(): void
    {
        $html = file_get_contents(resource_path('views/welcome.blade.php'));
        foreach (['id="vsFeaturedType"', 'for="vsFeaturedType"', '>Room type<', 'All room types', 'id="vsFeaturedDates"', 'id="vsFeaturedApply"', 'class="vs-card vs-featbar"'] as $needle) {
            $this->assertStringContainsString($needle, $html, $needle);
        }
        $this->assertStringNotContainsString('vsFeaturedChips', $html);
        $rooms = file_get_contents(public_path('js/portal/rooms.js'));
        foreach (["var ORDER = ['standard', 'deluxe', 'superior', 'family', 'executive']", 'fillTypes', 'showCards', "rt.name === typeFilter", "typeHost.addEventListener('change'"] as $needle) {
            $this->assertStringContainsString($needle, $rooms, $needle);
        }
        $css = file_get_contents(public_path('css/portal.css'));
        $this->assertStringContainsString('grid-template-columns: minmax(12rem, 1.1fr) minmax(0, 2fr) 10rem', $css, 'type, dates and Search share one row on desktop');
    }
}
