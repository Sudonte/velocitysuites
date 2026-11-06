<?php

namespace Tests\Feature;

use App\Http\Controllers\Admin\PasswordResetRequestController;
use App\Models\StaffPasswordResetRequest;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * Who can use the emailed-code reset (Guest and System Administrator on the website, Guest only in the app; a Receptionist or Manager never
 * gets a code and the answer is the same), the sign-in to each person's own dashboard, and the staff reset: request -> the System
 * Administrator approves with a unique temporary password shown once and valid 24 hours -> forced change -> their own dashboard.
 */
class StaffResetAndWebResetRolesTest extends ApiFlowTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();
        // the staff layouts' sidebars count new check-ins and check-outs; those tables are not part of this test
        foreach (['sidebar:has_new_checkins', 'sidebar:has_new_checkouts'] as $k) {
            Cache::forever($k, false);
        }
        Cache::forever('stay-alerts:sweep', 1);
        Mail::shouldReceive('raw')->andReturnNull();
        Mail::shouldReceive('send')->andReturnNull();

        // the shared test schema has the users, guests, bookings, notifications and activity log tables; this adds what the reset needs
        foreach ([
            'must_change_password' => fn (Blueprint $t) => $t->boolean('must_change_password')->default(false),
            'temporary_password_expires_at' => fn (Blueprint $t) => $t->timestamp('temporary_password_expires_at')->nullable(),
            'failed_login_attempts' => fn (Blueprint $t) => $t->integer('failed_login_attempts')->default(0),
            'last_failed_login_at' => fn (Blueprint $t) => $t->timestamp('last_failed_login_at')->nullable(),
            'last_login_at' => fn (Blueprint $t) => $t->timestamp('last_login_at')->nullable(),
            'deleted_at' => fn (Blueprint $t) => $t->timestamp('deleted_at')->nullable(),
            'restore_deadline' => fn (Blueprint $t) => $t->timestamp('restore_deadline')->nullable(),
            'deactivated_at' => fn (Blueprint $t) => $t->timestamp('deactivated_at')->nullable(),
            'remember_token' => fn (Blueprint $t) => $t->rememberToken(),
        ] as $column => $add) {
            if (! Schema::hasColumn('users', $column)) {
                Schema::table('users', fn (Blueprint $t) => $add($t));
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

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function make(string $role, string $email = null): User
    {
        $u = User::create(['first_name' => ucfirst($role), 'last_name' => 'Tester', 'email' => $email ?? "$role@example.test", 'password' => 'OldPassword1', 'role' => $role]);
        if ($role === 'guest') {
            DB::table('guests')->insert(['user_id' => $u->id, 'created_at' => now(), 'updated_at' => now()]);
        }

        return $u;
    }

    private function send(string $email)
    {
        return $this->postJson('/auth/reset/send', ['email' => $email]);
    }

    private function codeFor(string $email, string $code = '123456'): void
    {
        DB::table('password_reset_tokens')->updateOrInsert(['email' => $email], ['token' => Hash::make($code), 'created_at' => now()]);
    }

    // ---------------------------------------------------------------- who gets a code

    public function test_guest_and_system_administrator_get_a_code_receptionist_and_manager_never_do_and_the_answer_is_the_same(): void
    {
        foreach (['guest', 'admin', 'receptionist', 'manager'] as $role) {
            $this->make($role);
        }
        $answers = [];
        foreach (['guest', 'admin', 'receptionist', 'manager'] as $role) {
            Cache::forget('pwreset:cooldown:' . sha1("$role@example.test")); // the resend cooldown is per address
            $r = $this->send("$role@example.test")->assertOk();
            $answers[] = $r->json('message');
        }
        $answers[] = $this->send('nobody@example.test')->assertOk()->json('message');
        $this->assertCount(1, array_unique($answers), 'one generic answer for every address');
        $this->assertSame("If an account exists for that email, we've sent a 6-digit code.", $answers[0]);
        $this->assertDatabaseHas('password_reset_tokens', ['email' => 'guest@example.test']);
        $this->assertDatabaseHas('password_reset_tokens', ['email' => 'admin@example.test']);
        $this->assertDatabaseMissing('password_reset_tokens', ['email' => 'receptionist@example.test']);
        $this->assertDatabaseMissing('password_reset_tokens', ['email' => 'manager@example.test']);
        $this->assertDatabaseMissing('password_reset_tokens', ['email' => 'nobody@example.test']);
    }

    public function test_the_app_api_is_guest_only(): void
    {
        $this->make('guest');
        $this->make('admin');
        $this->postJson('/api/forgot-password', ['email' => 'guest@example.test'])->assertOk();
        $this->postJson('/api/forgot-password', ['email' => 'admin@example.test'])->assertOk();
        $this->assertDatabaseHas('password_reset_tokens', ['email' => 'guest@example.test']);
        $this->assertDatabaseMissing('password_reset_tokens', ['email' => 'admin@example.test']);

        $this->codeFor('admin@example.test');
        $this->postJson('/api/reset-password', ['email' => 'admin@example.test', 'otp' => '123456', 'password' => 'NewPassword9', 'password_confirmation' => 'NewPassword9'])->assertStatus(422);
        $this->assertTrue(Hash::check('OldPassword1', User::where('email', 'admin@example.test')->value('password')), 'the app cannot change an administrator password');
    }

    // ---------------------------------------------------------------- signed in to their own dashboard

    public function test_the_administrator_resets_by_code_and_lands_on_the_admin_dashboard_the_guest_on_theirs(): void
    {
        $admin = $this->make('admin');
        $guest = $this->make('guest');
        foreach ([[$admin, 'admin.dashboard'], [$guest, 'guest.dashboard']] as [$u, $route]) {
            $u->update(['failed_login_attempts' => 5, 'last_failed_login_at' => now()]);
            $this->codeFor($u->email);
            $this->postJson('/auth/reset/verify', ['email' => $u->email, 'otp' => '123456'])->assertOk();
            $weak = $this->postJson('/auth/reset/complete', ['email' => $u->email, 'otp' => '123456', 'password' => 'short', 'password_confirmation' => 'short']);
            $weak->assertStatus(422);
            $ok = $this->postJson('/auth/reset/complete', ['email' => $u->email, 'otp' => '123456', 'password' => 'NewPassword9', 'password_confirmation' => 'NewPassword9']);
            $ok->assertOk()->assertJsonPath('redirect', route($route));
            $this->assertAuthenticatedAs($u->fresh());
            $this->assertTrue(Hash::check('NewPassword9', $u->fresh()->password));
            $this->assertSame(0, (int) $u->fresh()->failed_login_attempts, 'the reset lifts the sign-in lock');
            $this->post('/logout');
            $this->app['auth']->forgetGuards();
        }
    }

    public function test_a_wrong_code_is_refused_for_the_administrator_too(): void
    {
        $this->make('admin');
        $this->codeFor('admin@example.test');
        $this->postJson('/auth/reset/verify', ['email' => 'admin@example.test', 'otp' => '000000'])->assertStatus(422)->assertJsonPath('code', 'WRONG_CODE');
    }

    public function test_a_staff_code_cannot_be_used_even_if_one_existed(): void
    {
        $this->make('receptionist');
        $this->codeFor('receptionist@example.test');
        $this->postJson('/auth/reset/complete', ['email' => 'receptionist@example.test', 'otp' => '123456', 'password' => 'NewPassword9', 'password_confirmation' => 'NewPassword9'])->assertStatus(422);
        $this->assertGuest();
    }

    // ---------------------------------------------------------------- the staff reset

    private function approved(User $staff): array
    {
        $admin = User::where('role', 'admin')->first() ?? $this->make('admin');
        $this->post(route('password.request.staff.send'), ['email' => $staff->email])->assertRedirect(route('password.request.staff'));
        $req = StaffPasswordResetRequest::where('user_id', $staff->id)->latest()->first();
        $this->assertSame('pending', $req->status);
        $resp = $this->actingAs($admin)->put(route('admin.users.password-requests.approve', $req));
        $resp->assertRedirect(route('admin.users.password-requests.index'));
        $tp = $resp->getSession()->get('temporary_password');
        $this->app['auth']->forgetGuards();
        $this->flushSession();

        return [$req->fresh(), $tp, $admin];
    }

    public function test_request_approve_temporary_password_forced_change_and_the_right_dashboard(): void
    {
        foreach (['receptionist', 'manager'] as $role) {
            $staff = $this->make($role);
            [$req, $tp, $admin] = $this->approved($staff);

            $this->assertSame('approved', $req->status);
            $this->assertSame($staff->email, $tp['email']);
            $this->assertMatchesRegularExpression('/^(?=.*[A-Za-z])(?=.*\d).{12}$/', $tp['password'], 'random, 12 characters, letter and number (the shared rule)');
            $this->assertNotSame('velocitysuites123', $tp['password'], 'not the shared default');
            $fresh = $staff->fresh();
            $this->assertTrue($fresh->must_change_password);
            $this->assertEqualsWithDelta(now()->addHours(24)->timestamp, $fresh->temporary_password_expires_at->timestamp, 5);
            $this->assertDatabaseHas('activity_logs', ['action' => 'Approved password reset request']);

            // signs in with it: only the "set a new password" screen opens, even by typing a dashboard address
            $this->post('/login', ['email' => $staff->email, 'password' => $tp['password']])->assertRedirect(route('force-password-change.show'));
            $this->get(route("$role.dashboard"))->assertRedirect(route('force-password-change.show'));
            $this->get(route('force-password-change.show'))->assertOk()->assertSee('temporary password', false);

            // the shared rule and a matching confirmation
            $this->post(route('force-password-change.update'), ['password' => 'short', 'password_confirmation' => 'short'])->assertSessionHasErrors('password');
            $this->post(route('force-password-change.update'), ['password' => 'Permanent-Pw-9', 'password_confirmation' => 'Different-Pw-9'])->assertSessionHasErrors('password');
            $this->post(route('force-password-change.update'), ['password' => 'Permanent-Pw-9', 'password_confirmation' => 'Permanent-Pw-9'])->assertRedirect(route("$role.dashboard"));

            $fresh = $staff->fresh();
            $this->assertFalse($fresh->must_change_password);
            $this->assertNull($fresh->temporary_password_expires_at);
            $this->assertSame('completed', $req->fresh()->status);
            $this->assertNotSame(route('force-password-change.show'), $this->get(route("$role.dashboard"))->headers->get('Location'), 'the gate is open now');
            $this->post('/logout');

            // the temporary password no longer works; the permanent one does
            $this->post('/login', ['email' => $staff->email, 'password' => $tp['password']]);
            $this->assertGuest();
            $this->post('/login', ['email' => $staff->email, 'password' => 'Permanent-Pw-9'])->assertRedirect(route("$role.dashboard"));
            $this->post('/logout');
            $this->app['auth']->forgetGuards();
        }
    }

    public function test_every_approval_makes_its_own_password(): void
    {
        $seen = [];
        foreach (range(1, 25) as $i) {
            $seen[] = PasswordResetRequestController::temporaryPassword();
        }
        $this->assertCount(25, array_unique($seen));
        foreach ($seen as $pw) {
            $this->assertMatchesRegularExpression('/^(?=.*[A-Za-z])(?=.*\d).{12}$/', $pw);
        }
    }

    public function test_an_expired_temporary_password_is_refused_and_ends_a_session(): void
    {
        $staff = $this->make('receptionist');
        [, $tp] = $this->approved($staff);

        // after 24 hours the sign-in is refused
        Carbon::setTestNow(now()->addHours(25));
        $this->post('/login', ['email' => $staff->email, 'password' => $tp['password']])->assertSessionHas('error');
        $this->assertGuest();
        $this->assertStringContainsString('expired', session('error'));

        // a session opened inside the 24 hours is ended once they pass
        Carbon::setTestNow();
        $this->post('/login', ['email' => $staff->email, 'password' => $tp['password']])->assertRedirect(route('force-password-change.show'));
        Carbon::setTestNow(now()->addHours(25));
        $this->get(route('force-password-change.show'))->assertRedirect(route('login'));
        $this->assertGuest();
    }

    public function test_the_administrator_can_reject_and_nothing_changes_for_the_staff_member(): void
    {
        $admin = $this->make('admin');
        $staff = $this->make('manager');
        $this->post(route('password.request.staff.send'), ['email' => $staff->email]);
        $req = StaffPasswordResetRequest::where('user_id', $staff->id)->first();
        $before = $staff->fresh()->password;

        $this->actingAs($admin)->put(route('admin.users.password-requests.reject', $req), ['reason' => 'Not requested by you'])->assertRedirect(route('admin.users.password-requests.index'));

        $this->assertSame('rejected', $req->fresh()->status);
        $this->assertSame($before, $staff->fresh()->password);
        $this->assertFalse($staff->fresh()->must_change_password);
        $this->assertDatabaseHas('activity_logs', ['action' => 'Rejected password reset request']);
    }

    public function test_the_admin_list_shows_the_details_the_temporary_password_once_with_copy_and_approve_reject(): void
    {
        $admin = $this->make('admin');
        $staff = $this->make('receptionist');
        $this->post(route('password.request.staff.send'), ['email' => $staff->email]);
        $list = $this->actingAs($admin)->get(route('admin.users.password-requests.index'))->assertOk()->getContent();
        foreach (['Receptionist Tester', 'Receptionist', 'receptionist@example.test', 'Approve', 'Reject', 'Requested'] as $needle) {
            $this->assertStringContainsString($needle, $list, $needle);
        }
        $req = StaffPasswordResetRequest::first();
        $after = $this->actingAs($admin)->followingRedirects()->put(route('admin.users.password-requests.approve', $req))->getContent();
        $this->assertStringContainsString('tempPasswordCopy', $after);
        $this->assertStringContainsString('Shown only now', $after);
        $this->assertStringContainsString('24 hours', $after);
        // gone on the next visit
        $again = $this->actingAs($admin)->get(route('admin.users.password-requests.index'))->getContent();
        $this->assertStringNotContainsString('id="tempPasswordValue"', $again);
    }

    public function test_the_forgot_pages_wording(): void
    {
        $page = $this->get('/forgot-password/staff')->assertOk()->getContent();
        foreach (['Staff password reset', 'Email address', 'Send request', 'Your comfort is our service', 'images/logo.jpg'] as $needle) {
            $this->assertStringContainsString($needle, $page, $needle);
        }
        $this->assertStringContainsString("If this is a staff account, your request has been sent to the System Administrator.", $this->post(route('password.request.staff.send'), ['email' => 'nobody@example.test'])->getSession()->get('status'));
    }
}
