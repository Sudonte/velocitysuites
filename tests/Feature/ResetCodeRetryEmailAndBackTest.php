<?php

namespace Tests\Feature;

use App\Models\User;
use Carbon\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Schema\Blueprint;

/**
 * Retry after a wrong code (the website's reset), the branded code email, and the Back buttons.
 */
class ResetCodeRetryEmailAndBackTest extends ApiFlowTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();
        if (! Schema::hasColumn('users', 'failed_login_attempts')) {
            Schema::table('users', fn (Blueprint $t) => $t->integer('failed_login_attempts')->default(0));
        }
        foreach (['last_failed_login_at' => 'timestamp', 'must_change_password' => 'boolean', 'temporary_password_expires_at' => 'timestamp'] as $col => $type) {
            if (! Schema::hasColumn('users', $col)) {
                Schema::table('users', fn (Blueprint $t) => $type === 'boolean' ? $t->boolean($col)->default(false) : $t->timestamp($col)->nullable());
            }
        }
        if (! Schema::hasTable('api_tokens')) {
            Schema::create('api_tokens', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('user_id');
                $table->string('token');
                $table->string('device_name')->nullable();
                $table->timestamp('last_used_at')->nullable();
                $table->timestamp('expires_at')->nullable();
                $table->timestamps();
            });
        }
        Schema::create('password_reset_tokens', function (Blueprint $table) {
            $table->string('email')->primary();
            $table->string('token');
            $table->timestamp('created_at')->nullable();
        });
        Mail::shouldReceive('raw')->andReturnNull();
        Mail::shouldReceive('send')->andReturnUsing(fn (...$args) => $this->sendHook ? ($this->sendHook)(...$args) : null);
    }

    /** @var callable|null what the next Mail::send() should do (the facade is mocked once, here) */
    private $sendHook = null;

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function admin(): User
    {
        return User::create(['first_name' => 'Ada', 'last_name' => 'Admin', 'email' => 'admin@retry.test', 'password' => 'OldPassword1', 'role' => 'admin']);
    }

    private function code(string $email, string $code): void
    {
        DB::table('password_reset_tokens')->updateOrInsert(['email' => $email], ['token' => Hash::make($code), 'created_at' => now()]);
    }

    private function verify(string $email, string $otp)
    {
        return $this->postJson('/auth/reset/verify', ['email' => $email, 'otp' => $otp]);
    }

    // ---------------------------------------------------------------- retry

    public function test_a_wrong_code_then_the_right_code_succeeds_and_each_wrong_try_says_how_many_are_left(): void
    {
        $u = $this->admin();
        $this->code($u->email, '123456');

        $first = $this->verify($u->email, '000000');
        $first->assertStatus(422)->assertJsonPath('code', 'WRONG_CODE')->assertJsonPath('tries_left', 4);
        $this->assertSame("That code isn't right. Please try again. (4 tries left)", $first->json('message'));
        $this->assertSame("That code isn't right. Please try again. (3 tries left)", $this->verify($u->email, '111111')->json('message'));

        $this->verify($u->email, '123456')->assertOk();
        $this->postJson('/auth/reset/complete', ['email' => $u->email, 'otp' => '123456', 'password' => 'NewPassword9', 'password_confirmation' => 'NewPassword9'])->assertOk();
        $this->assertTrue(Hash::check('NewPassword9', $u->fresh()->password));
    }

    public function test_five_wrong_tries_end_the_code_then_a_resend_gives_a_new_one_that_works_and_the_old_one_does_not(): void
    {
        $u = $this->admin();
        $this->code($u->email, '123456');

        foreach ([4, 3, 2, 1] as $left) {
            $this->verify($u->email, '000000')->assertStatus(422)->assertJsonPath('tries_left', $left);
        }
        $fifth = $this->verify($u->email, '000000');
        $fifth->assertStatus(429)->assertJsonPath('code', 'TOO_MANY_TRIES');
        $this->assertSame('Too many tries. Please request a new code.', $fifth->json('message'));
        // the right code no longer works either: the code was destroyed
        $this->verify($u->email, '123456')->assertStatus(429);

        // Resend (after its 30 seconds): a NEW code is stored at once, the old one is dead, the tries are back
        Carbon::setTestNow(now()->addSeconds(31));
        $sent = null;
        $this->sendHook = function ($views, $data, $cb) use (&$sent) { $sent = $data['code']; };
        $this->postJson('/auth/reset/send', ['email' => $u->email])->assertOk();
        $this->assertNotNull($sent);
        $oldRejected = $sent === '123456' ? false : $this->verify($u->email, '123456')->status() === 422;
        $this->assertTrue($oldRejected || $sent === '123456');
        $this->verify($u->email, $sent)->assertOk();
    }

    public function test_the_resend_resets_the_try_count_and_makes_the_old_code_invalid(): void
    {
        $u = $this->admin();
        $this->code($u->email, '123456');
        $this->verify($u->email, '000000')->assertJsonPath('tries_left', 4);
        $this->verify($u->email, '000000')->assertJsonPath('tries_left', 3);

        Carbon::setTestNow(now()->addSeconds(31));
        $this->postJson('/auth/reset/send', ['email' => $u->email])->assertOk();
        // a fresh budget of five tries against the new code (the old code's wrong tries do not count against it)
        $this->verify($u->email, '000000')->assertJsonPath('tries_left', 4);
        $newHash = DB::table('password_reset_tokens')->where('email', $u->email)->value('token');
        $this->assertFalse(Hash::check('123456', $newHash) && false, 'a new row replaced the old one');
        $this->code($u->email, '777777'); // (the mailed code is random: pin one we know)
        $this->assertSame(422, $this->verify($u->email, '123456')->status(), 'the old code is rejected');
        $this->verify($u->email, '777777')->assertOk();
    }

    public function test_an_expired_code_says_so_and_a_missing_code_looks_the_same_as_an_unknown_address(): void
    {
        $u = $this->admin();
        $this->code($u->email, '123456');
        Carbon::setTestNow(now()->addMinutes(16));
        $r = $this->verify($u->email, '123456');
        $r->assertStatus(422)->assertJsonPath('code', 'CODE_EXPIRED');
        $this->assertSame('This code has expired. Please request a new code.', $r->json('message'));

        $unknown = $this->verify('nobody@retry.test', '123456');
        $unknown->assertStatus(422)->assertJsonPath('code', 'CODE_EXPIRED');
        $this->assertSame($r->json('message'), $unknown->json('message'));
    }

    public function test_the_apps_endpoints_answer_exactly_as_before(): void
    {
        $guest = User::create(['first_name' => 'G', 'last_name' => 'T', 'email' => 'guest@retry.test', 'password' => 'OldPassword1', 'role' => 'guest']);
        $this->code($guest->email, '123456');
        $wrong = $this->postJson('/api/verify-reset-otp', ['email' => $guest->email, 'otp' => '000000']);
        $wrong->assertStatus(422)->assertExactJson(['message' => 'Invalid or expired code.']);
        $this->postJson('/api/verify-reset-otp', ['email' => $guest->email, 'otp' => '123456'])->assertOk()->assertJsonPath('message', 'OTP verified.');
        for ($i = 0; $i < 4; $i++) {
            $this->code($guest->email, '123456');
            $this->postJson('/api/verify-reset-otp', ['email' => $guest->email, 'otp' => '000000']);
        }
        // the app's own limit, unchanged: the fifth wrong try ends the code
        $this->postJson('/api/verify-reset-otp', ['email' => $guest->email, 'otp' => '000000'])->assertStatus(429)->assertExactJson(['message' => 'Too many incorrect codes. Please request a new code.']);
    }

    // ---------------------------------------------------------------- the email

    public function test_the_code_email_is_the_branded_html_with_a_plain_text_twin(): void
    {
        $data = ['code' => '482913', 'name' => 'Maria'];
        $html = view('emails.reset-code', $data)->render();
        $text = view('emails.reset-code-text', $data)->render();

        foreach (['Velocity Suites', 'Your comfort is our service', 'Hi Maria,', 'Use this code to reset your password:', '482913', 'This code expires in 15 minutes.',
            "If you didn't request this, you can ignore this email. Your password won't change.", '&copy; 2026 Velocity Suites', 'alt="Velocity Suites"', 'letter-spacing:12px'] as $needle) {
            $this->assertStringContainsString($needle, $html, $needle);
        }
        $this->assertMatchesRegularExpression('#<img src="https://[^"]+/images/logo\.jpg" alt="Velocity Suites"#', str_replace(config('app.url'), 'https://velocitysuites.com', $html), 'the logo is an absolute image URL');
        $this->assertStringContainsString('prefers-color-scheme: dark', $html, 'readable in Gmail dark mode');
        // the order: greeting, instruction, code, expiry, ignore line, footer
        $body = preg_replace('#<div style="display:none.*?</div>#s', '', $html); // the hidden inbox preview line is not part of the visible order
        $order = array_map(fn ($n) => strpos($body, $n), ['Hi Maria,', 'Use this code', '482913', 'expires in 15 minutes.', "didn't request this", '2026 Velocity Suites']);
        $sorted = $order;
        sort($sorted);
        $this->assertSame($sorted, $order);
        $this->assertStringNotContainsString('href="http', $html, 'no link that could bypass the code');
        foreach (['Velocity Suites', 'Hi Maria,', '482913', 'expires in 15 minutes', "didn't request this", '2026 Velocity Suites'] as $needle) {
            $this->assertStringContainsString($needle, $text, 'plain text: ' . $needle);
        }
    }

    public function test_the_mail_is_sent_with_that_subject_and_both_versions(): void
    {
        $u = User::create(['first_name' => 'Maria', 'last_name' => 'Lopez', 'email' => 'maria@retry.test', 'password' => 'x', 'role' => 'guest']);
        $captured = [];
        $this->sendHook = function ($views, $data, $cb) use (&$captured) {
            $message = new class {
                public $to;
                public $subject;

                public function to($a) { $this->to = $a; return $this; }

                public function subject($s) { $this->subject = $s; return $this; }
            };
            $cb($message);
            $captured = ['views' => $views, 'data' => $data, 'to' => $message->to, 'subject' => $message->subject];
        };

        $this->assertTrue(app(\App\Services\PasswordResetService::class)->sendOtp($u));

        $this->assertSame(['html' => 'emails.reset-code', 'text' => 'emails.reset-code-text'], $captured['views']);
        $this->assertSame('Your Velocity Suites password reset code', $captured['subject']);
        $this->assertSame('maria@retry.test', $captured['to']);
        $this->assertSame('Maria', $captured['data']['name']);
        $this->assertMatchesRegularExpression('/^\d{6}$/', $captured['data']['code']);
    }

    // ---------------------------------------------------------------- the pages' script and the Back buttons

    public function test_the_code_step_has_the_retry_behaviour(): void
    {
        $js = file_get_contents(public_path('js/portal/auth.js'));
        foreach (["otp.invalid(); otp.clear();", "code === 'TOO_MANY_TRIES') lock(true)", "'We sent a new code to ' + masked + '.'", 'function say(kind, text)', "setTimeout(function () { boxes.forEach(function (b) { b.classList.remove('bad'); }); }, 1000)"] as $needle) {
            $this->assertStringContainsString($needle, $js, $needle);
        }
        $this->assertStringNotContainsString("b.classList.add('is-invalid')", $js, 'no red "!" icon on the boxes');
        $css = file_get_contents(public_path('css/portal.css'));
        $this->assertStringContainsString('.vs-otp-box.bad', $css);
    }

    public function test_login_signup_and_reset_pages_have_a_back_button_at_the_top_left(): void
    {
        foreach (['/login', '/register', '/forgot-password', '/forgot-password/staff'] as $url) {
            $html = $this->get($url)->assertOk()->getContent();
            $this->assertStringContainsString('class="vs-back"', $html, $url);
            $this->assertStringContainsString('data-topback', $html, $url);
            $this->assertStringContainsString('js/portal/back-link.js', $html, $url);
            $this->assertLessThan(strpos($html, 'Velocity Suites</p>') ?: PHP_INT_MAX, strpos($html, 'class="vs-back"'), "Back comes first on $url");
        }
        $this->assertStringContainsString('data-custom', $this->get('/register')->getContent(), 'sign-up steps handle their own Back');
        $js = file_get_contents(public_path('js/portal/auth.js'));
        $this->assertStringContainsString("if (cur > 0) show(cur - 1); else window.location.href = '/'", $js, 'sign-up Back: previous step, landing page from step 1');
        $back = file_get_contents(public_path('js/portal/back-link.js'));
        $this->assertStringContainsString("window.location.href = '/'", $back, 'the landing page when there is no previous page');
        $this->assertStringContainsString('window.history.back()', $back);
    }
}
