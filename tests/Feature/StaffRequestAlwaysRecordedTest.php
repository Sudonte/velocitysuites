<?php

namespace Tests\Feature;

use App\Models\Notification;
use App\Models\StaffPasswordResetRequest;
use App\Models\User;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Schema;

/** Every Manager / Receptionist reset request is recorded and shown to the System Administrator (production bug: an old unused "approved" request hid a new one). */
class StaffRequestAlwaysRecordedTest extends ApiFlowTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();
        Cache::forever('sidebar:has_new_checkins', false);
        Cache::forever('sidebar:has_new_checkouts', false);
        Cache::forever('stay-alerts:sweep', 1);
        Mail::shouldReceive('raw')->andReturnNull();
        Mail::shouldReceive('send')->andReturnNull();
        foreach (['must_change_password' => 'boolean', 'temporary_password_expires_at' => 'timestamp', 'failed_login_attempts' => 'int', 'last_failed_login_at' => 'timestamp', 'remember_token' => 'token'] as $col => $type) {
            if (! Schema::hasColumn('users', $col)) {
                Schema::table('users', fn (Blueprint $t) => match ($type) {
                    'boolean' => $t->boolean($col)->default(false),
                    'int' => $t->integer($col)->default(0),
                    'token' => $t->rememberToken(),
                    default => $t->timestamp($col)->nullable(),
                });
            }
        }
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

    private function make(string $role, string $email, string $first = 'Althea'): User
    {
        return User::create(['first_name' => $first, 'last_name' => 'Espedion', 'email' => $email, 'password' => 'OldPassword1', 'role' => $role]);
    }

    private function ask(string $email)
    {
        return $this->post(route('password.request.staff.send'), ['email' => $email]);
    }

    private function pending(User $u)
    {
        return StaffPasswordResetRequest::where('user_id', $u->id)->where('status', 'pending')->get();
    }

    private const MESSAGE = 'If this is a staff account, your request has been sent to the System Administrator.';

    public function test_a_manager_and_a_receptionist_request_each_appear_as_pending_with_a_notification_for_the_admin(): void
    {
        $admin = $this->make('admin', 'admin@x.test', 'Ada');
        $manager = $this->make('manager', 'espedion.manager@hotel.com');
        $desk = $this->make('receptionist', 'espedion.receptionist@hotel.com');

        foreach ([$manager, $desk] as $u) {
            $this->ask($u->email)->assertRedirect(route('password.request.staff'))->assertSessionHas('status', self::MESSAGE);
            $this->assertCount(1, $this->pending($u));
        }
        $titles = Notification::where('user_id', $admin->id)->pluck('message')->all();
        $this->assertContains('Password reset request from Althea Espedion (Manager)', $titles);
        $this->assertContains('Password reset request from Althea Espedion (Receptionist)', $titles);
        $note = Notification::where('user_id', $admin->id)->first();
        $this->assertSame('Password Reset Request', $note->title);
        $this->assertSame(route('admin.users.password-requests.index'), \App\Support\NotificationLink::for($note, $admin)['url'], 'the bell entry links to the requests page');
    }

    public function test_an_old_approved_request_that_was_never_used_no_longer_hides_a_new_request(): void
    {
        $this->make('admin', 'admin@x.test', 'Ada');
        $manager = $this->make('manager', 'espedion.manager@hotel.com');
        // production: approved on Oct 28, never completed; the manager never signed in with the temporary password
        $old = StaffPasswordResetRequest::create(['user_id' => $manager->id, 'status' => 'approved', 'processed_at' => now()->subDays(9)]);
        $old->forceFill(['created_at' => now()->subDays(9)])->save();
        $manager->update(['must_change_password' => true, 'temporary_password_expires_at' => now()->subDays(8)]);

        $this->ask($manager->email)->assertSessionHas('status', self::MESSAGE);

        $this->assertSame('expired', $old->fresh()->status, 'the old request is closed as expired');
        $this->assertCount(1, $this->pending($manager), 'and the new one is pending');
    }

    public function test_an_approved_request_whose_temporary_password_is_still_valid_is_left_alone_and_no_duplicate_is_made(): void
    {
        $manager = $this->make('manager', 'm@hotel.com');
        $fresh = StaffPasswordResetRequest::create(['user_id' => $manager->id, 'status' => 'approved', 'processed_at' => now()->subHour()]);
        $manager->update(['must_change_password' => true, 'temporary_password_expires_at' => now()->addHours(23)]);

        $this->ask($manager->email)->assertSessionHas('status', self::MESSAGE);

        $this->assertSame('approved', $fresh->fresh()->status);
        $this->assertCount(0, $this->pending($manager));
    }

    public function test_repeated_requests_keep_one_pending_request_and_refresh_its_time(): void
    {
        $this->make('admin', 'admin@x.test', 'Ada');
        $desk = $this->make('receptionist', 'desk@hotel.com');
        $this->ask($desk->email);
        $first = $this->pending($desk)->first();
        $first->forceFill(['created_at' => now()->subHours(5)])->save();

        $this->ask($desk->email);
        $this->ask($desk->email);

        $this->assertSame(1, StaffPasswordResetRequest::where('user_id', $desk->id)->count());
        $this->assertTrue($this->pending($desk)->first()->created_at->gt(now()->subMinutes(5)), 'the requested time moved to now');
        $this->assertSame(1, Notification::where('title', 'Password Reset Request')->count(), 'the administrator is told once');
    }

    public function test_upper_case_and_spaces_in_the_email_still_match(): void
    {
        $manager = $this->make('manager', 'espedion.manager@hotel.com');
        $this->ask('  Espedion.Manager@Hotel.COM ')->assertSessionHas('status', self::MESSAGE);
        $this->assertCount(1, $this->pending($manager));
    }

    public function test_guest_and_unknown_emails_create_nothing_but_get_the_same_message(): void
    {
        $guest = $this->make('guest', 'guest@x.test', 'Gus');
        $a = $this->ask($guest->email)->getSession()->get('status');
        $b = $this->ask('nobody@x.test')->getSession()->get('status');
        $this->assertSame(self::MESSAGE, $a);
        $this->assertSame($a, $b);
        $this->assertSame(0, StaffPasswordResetRequest::count());
    }

    public function test_a_failure_to_save_is_logged_as_an_error_and_the_message_stays_generic(): void
    {
        $manager = $this->make('manager', 'm@hotel.com');
        Schema::drop('staff_password_reset_requests'); // the save cannot work
        $logged = null;
        \Illuminate\Support\Facades\Log::shouldReceive('error')->andReturnUsing(function ($m) use (&$logged) { $logged = $m; });
        \Illuminate\Support\Facades\Log::shouldReceive('info', 'warning', 'debug', 'channel', 'stack')->andReturnNull();

        $this->ask($manager->email)->assertSessionHas('status', self::MESSAGE);

        $this->assertNotNull($logged);
        $this->assertStringContainsString("Staff password reset request for user #{$manager->id} could not be saved", $logged);
    }

    public function test_the_admin_list_defaults_to_pending_with_pending_first_a_count_and_all_statuses_still_works(): void
    {
        $admin = $this->make('admin', 'admin@x.test', 'Ada');
        $a = $this->make('manager', 'a@hotel.com', 'Anna');
        $b = $this->make('receptionist', 'b@hotel.com', 'Bea');
        $done = StaffPasswordResetRequest::create(['user_id' => $a->id, 'status' => 'completed']);
        $done->forceFill(['created_at' => now()])->save(); // the newest overall, but finished
        $this->ask($b->email);
        StaffPasswordResetRequest::where('user_id', $b->id)->update(['created_at' => now()->subDay()]);

        $html = $this->actingAs($admin)->get(route('admin.users.password-requests.index'))->assertOk()->getContent();
        $this->assertStringContainsString('b@hotel.com', $html);
        $this->assertStringNotContainsString('a@hotel.com', $html, 'the default view is Pending only');
        $this->assertStringContainsString('<option value="pending" selected>Pending (1)</option>', $html);
        $this->assertStringContainsString('Approve', $html);
        $this->assertStringContainsString('Reject', $html);

        $all = $this->actingAs($admin)->get(route('admin.users.password-requests.index', ['status' => '']))->getContent();
        $this->assertStringContainsString('a@hotel.com', $all);
        $this->assertStringContainsString('b@hotel.com', $all);
        $this->assertLessThan(strpos($all, 'a@hotel.com'), strpos($all, 'b@hotel.com'), 'pending first, even though it is older');
    }

    public function test_the_user_management_button_shows_the_pending_count(): void
    {
        $admin = $this->make('admin', 'admin@x.test', 'Ada');
        $this->actingAs($admin)->get(route('admin.users.index'))->assertOk();
        $this->app['auth']->forgetGuards();
        $this->flushSession(); // the request page is for signed-out visitors
        $this->ask($this->make('manager', 'm@hotel.com')->email);
        $html = $this->actingAs($admin)->get(route('admin.users.index'))->assertOk()->getContent();
        $this->assertMatchesRegularExpression('#Password Reset Requests\s*<span class="badge rounded-pill bg-danger ms-1">\s*1\s*</span>#', preg_replace('/\s+/', ' ', $html));
    }
}
