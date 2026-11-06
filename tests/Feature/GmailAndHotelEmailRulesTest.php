<?php

namespace Tests\Feature;

use App\Models\StaffPasswordResetRequest;
use App\Models\User;
use App\Support\CodeEmail;
use App\Support\EmailDomain;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Schema;

/**
 * The two email domains: the guest / administrator reset form and guest sign-up accept only @gmail.com, the staff request form only
 * @hotel.com (case and spaces ignored, browser and server); codes and requests exist only for registered, active accounts of the right
 * role; every guest code email is the same branded email, and the sign-up code leaves right after the response.
 */
class GmailAndHotelEmailRulesTest extends ApiFlowTestCase
{
    /** @var array<int, array{views: mixed, data: array, to: ?string, subject: ?string}> every Mail::send of the request */
    private array $sent = [];

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();
        Cache::forever('sidebar:has_new_checkins', false);
        Cache::forever('sidebar:has_new_checkouts', false);
        Cache::forever('stay-alerts:sweep', 1);
        foreach (['failed_login_attempts' => 'int', 'last_failed_login_at' => 'timestamp', 'must_change_password' => 'boolean', 'temporary_password_expires_at' => 'timestamp'] as $col => $type) {
            if (! Schema::hasColumn('users', $col)) {
                Schema::table('users', fn (Blueprint $t) => match ($type) {
                    'boolean' => $t->boolean($col)->default(false),
                    'int' => $t->integer($col)->default(0),
                    default => $t->timestamp($col)->nullable(),
                });
            }
        }
        Schema::create('password_reset_tokens', function (Blueprint $table) {
            $table->string('email')->primary();
            $table->string('token');
            $table->timestamp('created_at')->nullable();
        });
        Schema::create('registration_otps', function (Blueprint $table) {
            $table->id();
            $table->string('email')->unique();
            $table->string('otp', 6);
            $table->json('payload');
            $table->timestamp('expires_at');
            $table->timestamps();
        });
        Schema::create('email_change_requests', function (Blueprint $table) {
            $table->unsignedBigInteger('user_id')->primary();
            $table->string('new_email');
            $table->string('otp');
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
        Mail::shouldReceive('raw')->andReturnNull();
        Mail::shouldReceive('send')->andReturnUsing(function ($views, $data, $cb) {
            $message = new class {
                public $to;
                public $subject;

                public function to($a) { $this->to = $a; return $this; }

                public function subject($s) { $this->subject = $s; return $this; }
            };
            $cb($message);
            $this->sent[] = ['views' => $views, 'data' => $data, 'to' => $message->to, 'subject' => $message->subject];
        });
    }

    private function make(string $role, string $email, string $status = 'active'): User
    {
        $u = User::create(['first_name' => 'Maria', 'last_name' => 'Lopez', 'email' => $email, 'password' => 'OldPassword1', 'role' => $role, 'status' => $status]);
        if ($role === 'guest') {
            DB::table('guests')->insert(['user_id' => $u->id, 'created_at' => now(), 'updated_at' => now()]);
        }

        return $u;
    }

    private function send(string $email)
    {
        Cache::flush();

        return $this->postJson('/auth/reset/send', ['email' => $email]);
    }

    private function ask(string $email)
    {
        return $this->post(route('password.request.staff.send'), ['email' => $email]);
    }

    private function signUp(string $email)
    {
        return $this->postJson('/api/register', [
            'first_name' => 'Ana', 'last_name' => 'Reyes', 'email' => $email,
            'password' => 'Str0ngPass!', 'password_confirmation' => 'Str0ngPass!',
            'age' => 30, 'gender' => 'female', 'date_of_birth' => now()->subYears(30)->toDateString(),
            'mobile_number' => '09171234567', 'address' => '1 Test St', 'country' => 'Philippines',
            'region' => 'NCR', 'city' => 'Manila', 'barangay' => 'Ermita', 'street' => '1 Test St',
            'otp_channel' => 'email',
        ]);
    }

    // ------------------------------------------------------------ "Reset your password" (Guest / System Administrator)

    public function test_only_a_gmail_address_is_accepted_on_the_reset_form_and_the_message_says_what_to_use(): void
    {
        $this->make('guest', 'maria@gmail.com');
        foreach (['maria@yahoo.com', 'maria@gmail.com.ph', 'maria@notgmail.com', 'maria'] as $bad) {
            $r = $this->send($bad);
            $r->assertStatus(422);
            $this->assertSame([], $this->sent, "no email for $bad");
        }
        $this->send('maria@yahoo.com')->assertJsonPath('errors.email.0', 'Please use the Gmail address registered to your account (@gmail.com).')->assertJsonPath('code', 'NOT_GMAIL');
        $this->assertDatabaseCount('password_reset_tokens', 0);
    }

    public function test_a_hotel_address_on_the_reset_form_is_pointed_to_the_staff_request(): void
    {
        $this->make('receptionist', 'desk@hotel.com');
        $r = $this->send('desk@hotel.com');
        $r->assertStatus(422)->assertJsonPath('code', 'STAFF_EMAIL');
        $this->assertSame('Hotel staff accounts can\'t reset here. Use “Hotel staff: Request a staff password reset” below.', $r->json('errors.email.0'));
        $this->send('  Desk@HOTEL.com ')->assertStatus(422)->assertJsonPath('code', 'STAFF_EMAIL');
        $this->assertDatabaseCount('password_reset_tokens', 0);
        $this->assertSame([], $this->sent);
    }

    public function test_a_valid_gmail_gets_the_same_answer_registered_or_not_and_a_code_only_for_an_active_guest_or_administrator(): void
    {
        $this->make('guest', 'guest@gmail.com');
        $this->make('admin', 'admin@gmail.com');
        $this->make('guest', 'suspended@gmail.com', 'suspended');
        $this->make('guest', 'gone@gmail.com', 'deactivated');
        $this->make('receptionist', 'desk.odd@gmail.com');
        $answers = [];
        foreach (['guest@gmail.com', '  ADMIN@Gmail.COM ', 'suspended@gmail.com', 'gone@gmail.com', 'desk.odd@gmail.com', 'nobody@gmail.com'] as $email) {
            $answers[] = $this->send($email)->assertOk()->json('message');
        }
        $this->assertCount(1, array_unique($answers));
        $this->assertSame("If an account exists for that email, we've sent a 6-digit code.", $answers[0]);
        // (the test app is shared by all requests, so an earlier request's end-of-request mail runs again at each terminate: compare the set)
        $this->assertEqualsCanonicalizing(['guest@gmail.com', 'admin@gmail.com'], array_values(array_unique(array_column($this->sent, 'to'))), 'only the active guest and administrator were mailed');
        $this->assertEqualsCanonicalizing(['guest@gmail.com', 'admin@gmail.com'], DB::table('password_reset_tokens')->pluck('email')->all());
    }

    public function test_the_app_api_only_codes_an_active_guest(): void
    {
        $this->make('guest', 'guest@gmail.com');
        $this->make('guest', 'suspended@gmail.com', 'suspended');
        $this->make('admin', 'admin@gmail.com');
        foreach (['guest@gmail.com', 'suspended@gmail.com', 'admin@gmail.com', 'nobody@gmail.com'] as $email) {
            Cache::flush();
            $this->postJson('/api/forgot-password', ['email' => $email])->assertOk()->assertJsonPath('message', "If an account exists for that email, we've sent a code.");
        }
        $this->assertSame(['guest@gmail.com'], DB::table('password_reset_tokens')->pluck('email')->all());
    }

    public function test_a_code_for_a_suspended_account_cannot_complete_a_reset(): void
    {
        $u = $this->make('admin', 'admin@gmail.com', 'suspended');
        DB::table('password_reset_tokens')->insert(['email' => $u->email, 'token' => \Hash::make('123456'), 'created_at' => now()]);
        $this->postJson('/auth/reset/complete', ['email' => $u->email, 'otp' => '123456', 'password' => 'NewPassword9', 'password_confirmation' => 'NewPassword9'])->assertStatus(422);
        $this->assertTrue(\Hash::check('OldPassword1', $u->fresh()->password));
    }

    // ------------------------------------------------------------ "Staff password reset"

    public function test_only_a_hotel_address_is_accepted_on_the_staff_form(): void
    {
        $staff = $this->make('manager', 'boss@hotel.com');
        foreach (['boss@yahoo.com', 'boss@hotel.com.ph', 'boss@nothotel.com'] as $bad) {
            $this->ask($bad)->assertSessionHasErrors(['email' => 'Please use your hotel staff email (@hotel.com).'])->assertSessionMissing('status');
        }
        $this->assertSame(0, StaffPasswordResetRequest::count());
        $this->assertSame([], $this->sent);
        $this->assertNotNull($staff);
    }

    public function test_a_gmail_address_on_the_staff_form_is_pointed_back_to_the_code_reset_with_a_link(): void
    {
        $this->make('guest', 'guest@gmail.com');
        $r = $this->ask('guest@gmail.com');
        $r->assertSessionHasErrors(['email' => 'Guest and administrator accounts reset with a code. Go back to “Reset your password”.']);
        $this->assertSame(0, StaffPasswordResetRequest::count());
        $page = $this->followingRedirects()->from(route('password.request.staff'))->post(route('password.request.staff.send'), ['email' => 'guest@gmail.com'])->getContent();
        $this->assertStringContainsString('Go back to <a href="' . route('password.request') . '">“Reset your password”</a>.', $page, 'the quoted words are the link');
    }

    public function test_a_valid_hotel_address_gets_the_same_answer_and_a_request_only_for_an_active_receptionist_or_manager(): void
    {
        $this->make('admin', 'admin@gmail.com');
        $desk = $this->make('receptionist', 'desk@hotel.com');
        $boss = $this->make('manager', 'boss@hotel.com');
        $off = $this->make('manager', 'off@hotel.com', 'suspended');
        $odd = $this->make('guest', 'odd.guest@hotel.com');
        $answers = [];
        foreach (['desk@hotel.com', '  BOSS@Hotel.COM ', 'off@hotel.com', 'odd.guest@hotel.com', 'nobody@hotel.com'] as $email) {
            $answers[] = $this->ask($email)->assertRedirect(route('password.request.staff'))->getSession()->get('status');
        }
        $this->assertCount(1, array_unique($answers));
        $this->assertSame('If this is a staff account, your request has been sent to the System Administrator.', $answers[0]);
        $this->assertEqualsCanonicalizing([$desk->id, $boss->id], StaffPasswordResetRequest::pluck('user_id')->all());
        $this->assertSame(0, StaffPasswordResetRequest::whereIn('user_id', [$off->id, $odd->id])->count());
    }

    // ------------------------------------------------------------ guest sign-up

    public function test_sign_up_needs_a_gmail_address_and_the_message_is_on_the_email_field(): void
    {
        foreach (['ana@yahoo.com', 'ana@hotel.com', 'ana@gmail.com.ph'] as $bad) {
            $this->signUp($bad)->assertStatus(422)->assertJsonPath('errors.email.0', 'Please use a Gmail address (@gmail.com) to create your account.')
                ->assertJsonPath('message', 'Please use a Gmail address (@gmail.com) to create your account.');
        }
        $this->assertDatabaseCount('registration_otps', 0);
        $this->assertSame([], $this->sent);
    }

    public function test_the_sign_up_code_is_stored_first_and_mailed_right_after_the_response_as_the_branded_email(): void
    {
        $this->signUp('  Ana.Reyes@GMAIL.com ')->assertOk();

        $this->assertDatabaseHas('registration_otps', ['email' => 'ana.reyes@gmail.com']);
        $this->assertCount(1, $this->sent, 'one email, sent at the end of the request (no queue)');
        $mail = $this->sent[0];
        $this->assertSame('ana.reyes@gmail.com', $mail['to']);
        $this->assertSame('Your Velocity Suites verification code', $mail['subject']);
        $this->assertSame(['html' => 'emails.code', 'text' => 'emails.code-text'], $mail['views']);
        $this->assertSame('Ana', $mail['data']['name']);
        $this->assertSame(5, $mail['data']['minutes']);
        $this->assertSame(DB::table('registration_otps')->value('otp'), $mail['data']['code']);

        $html = view('emails.code', $mail['data'])->render();
        foreach (['Velocity Suites', 'Your comfort is our service', 'Hi Ana,', 'Use this code to finish creating your account:', $mail['data']['code'], 'This code expires in 5 minutes.', e("If you didn't create an account, ignore this email."), '&copy; 2026 Velocity Suites', 'images/logo.jpg'] as $needle) {
            $this->assertStringContainsString($needle, $html, $needle);
        }
        $text = view('emails.code-text', $mail['data'])->render();
        foreach (['Velocity Suites', 'Hi Ana,', $mail['data']['code'], 'This code expires in 5 minutes.', "If you didn't create an account, ignore this email."] as $needle) {
            $this->assertStringContainsString($needle, $text, 'plain text: ' . $needle);
        }
        $this->assertStringNotContainsString($mail['data']['code'], $mail['subject'], 'the code is not in the subject');
    }

    // ------------------------------------------------------------ one branded email for every guest code

    public function test_reset_email_change_and_reactivation_codes_use_the_same_branded_email(): void
    {
        $u = $this->make('guest', 'maria@gmail.com');
        $this->send($u->email)->assertOk();
        app(\App\Services\EmailChangeService::class)->requestChange($u, 'maria.new@gmail.com');
        $re = new \ReflectionMethod(\App\Services\AccountReactivationService::class, 'sendOtpEmail');
        $re->setAccessible(true);
        $re->invoke(app(\App\Services\AccountReactivationService::class), $u->email, '654321');

        $this->assertCount(3, $this->sent);
        $expect = [
            ['Your Velocity Suites password reset code', 'maria@gmail.com', 15],
            ['Your Velocity Suites verification code', 'maria.new@gmail.com', 15],
            ['Your Velocity Suites verification code', 'maria@gmail.com', 10],
        ];
        foreach ($this->sent as $i => $mail) {
            $this->assertSame(['html' => 'emails.code', 'text' => 'emails.code-text'], $mail['views']);
            [$subject, $to, $minutes] = $expect[$i];
            $this->assertSame($subject, $mail['subject']);
            $this->assertSame($to, $mail['to']);
            $this->assertSame($minutes, $mail['data']['minutes']);
            $this->assertSame('Maria', $mail['data']['name']);
            $html = view('emails.code', $mail['data'])->render();
            $this->assertStringContainsString('Hi Maria,', $html);
            $this->assertStringContainsString("This code expires in {$minutes} minutes.", $html);
        }
    }

    public function test_no_code_email_is_still_a_plain_text_mail(): void
    {
        foreach (['app/Services/EmailChangeService.php', 'app/Services/AccountReactivationService.php', 'app/Http/Controllers/Guest/GuestController.php', 'app/Http/Controllers/ProfileController.php',
            'app/Http/Controllers/Api/AuthController.php', 'app/Http/Controllers/Auth/RegisterController.php', 'app/Services/PasswordResetService.php'] as $file) {
            $src = file_get_contents(base_path($file));
            // (the sign-up SMS in the API controller is a text message, not an email)
            $this->assertStringNotContainsString('Your VelocitySuites verification code is: {$otp}

', str_replace(['"

"', "
"], '', $file === 'app/Http/Controllers/Api/AuthController.php' ? '' : $src), $file);
            $this->assertStringNotContainsString("subject(\"Your VelocitySuites verification code", $src, $file);
        }
        $this->assertSame('Your Velocity Suites verification code', CodeEmail::VERIFY_SUBJECT);
    }

    // ------------------------------------------------------------ the pages (browser side)

    public function test_the_reset_form_script_checks_the_domain_and_asks_before_sending(): void
    {
        $js = file_get_contents(public_path('js/portal/auth.js'));
        foreach ([
            "function isGmail(v) { return /^[^\\s@]+@gmail\\.com$/i.test(String(v).trim()); }",
            "function isHotel(v) { return /^[^\\s@]+@hotel\\.com$/i.test(String(v).trim()); }",
            "var GMAIL_MSG = 'Please use the Gmail address registered to your account (@gmail.com).';",
            "Hotel staff accounts can\\u2019t reset here. Use \\u201cHotel staff: Request a staff password reset\\u201d below.",
            "if (isHotel(typed)) { flag(STAFF_ON_GMAIL_MSG, true); return; }",
            "title: 'Send a reset code?', message: 'Send a reset code to ' + maskEmail(state.email) + '?'",
            'id="feStaff"',
            "staffBtn.classList.toggle('vs-staff-hl', !!hotel)",
            "var SIGNUP_GMAIL_MSG = 'Please use a Gmail address (@gmail.com) to create your account.';",
            "else if (!isGmail(f('email').value)) { VS.fieldError(f('email'), SIGNUP_GMAIL_MSG); ok = false; }",
        ] as $needle) {
            $this->assertStringContainsString($needle, $js, $needle);
        }
        $this->assertStringContainsString('.vs-staff-hl', file_get_contents(public_path('css/portal.css')));
        $this->assertStringContainsString('Back to sign in', $js);
    }

    public function test_the_staff_page_script_checks_the_domain_and_asks_before_sending(): void
    {
        $page = $this->get('/forgot-password/staff')->assertOk()->getContent();
        foreach ([
            "Please use your hotel staff email (@hotel.com).",
            "a.textContent = '“Reset your password”'",
            "/^[^\\s@]+@hotel\\.com$/i.test(v)",
            "Send a password reset request to the System Administrator?",
            'js/portal/core.js',
        ] as $needle) {
            $this->assertStringContainsString($needle, $page, $needle);
        }
    }

    public function test_domain_helper_ignores_case_and_spaces(): void
    {
        $this->assertTrue(EmailDomain::isGmail('  Ana@GMAIL.com '));
        $this->assertFalse(EmailDomain::isGmail('ana@gmail.com.ph'));
        $this->assertTrue(EmailDomain::isHotel(' Desk@Hotel.COM'));
        $this->assertNull(EmailDomain::gmailFormError('a@gmail.com'));
        $this->assertNull(EmailDomain::staffFormError('a@hotel.com'));
    }
}
