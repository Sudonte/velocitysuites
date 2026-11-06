<?php

namespace Tests\Feature;

use App\Models\StaffPasswordResetRequest;
use App\Models\User;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Schema;

/** Admin -> Users -> Password Reset Requests: everything by default (Pending first), status / role / search filters with counts, Approve and Reject only on Pending. */
class AdminPasswordRequestsListTest extends ApiFlowTestCase
{
    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        Cache::forever('sidebar:has_new_checkins', false);
        Cache::forever('sidebar:has_new_checkouts', false);
        Cache::forever('stay-alerts:sweep', 1);
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
        $this->admin = User::create(['first_name' => 'Ada', 'last_name' => 'Admin', 'email' => 'admin@list.test', 'password' => 'x', 'role' => 'admin']);
    }

    private function req(string $first, string $role, string $status, string $when): StaffPasswordResetRequest
    {
        $u = User::create(['first_name' => $first, 'last_name' => 'Staff', 'email' => strtolower($first) . '@hotel.com', 'password' => 'x', 'role' => $role]);
        $r = StaffPasswordResetRequest::create(['user_id' => $u->id, 'status' => $status, 'processed_by' => $status === 'pending' ? null : $this->admin->id, 'processed_at' => $status === 'pending' ? null : $when]);
        $r->forceFill(['created_at' => $when])->save();

        return $r;
    }

    private function page(array $q = []): string
    {
        return $this->actingAs($this->admin)->get(route('admin.users.password-requests.index', $q))->assertOk()->getContent();
    }

    private function seedSix(): void
    {
        $this->req('Anna', 'manager', 'completed', '2026-11-05 10:00:00');
        $this->req('Bea', 'receptionist', 'pending', '2026-11-01 09:00:00');       // older, but pending: must sit on top
        $this->req('Cara', 'manager', 'approved', '2026-11-04 10:00:00');
        $this->req('Dina', 'receptionist', 'rejected', '2026-11-03 10:00:00');
        $this->req('Eve', 'manager', 'expired', '2026-10-20 10:00:00');
        $this->req('Fay', 'manager', 'pending', '2026-11-06 12:00:00');
    }

    public function test_the_default_shows_every_status_newest_first_with_pending_always_on_top(): void
    {
        $this->seedSix();
        $html = $this->page();
        foreach (['anna', 'bea', 'cara', 'dina', 'eve', 'fay'] as $n) {
            $this->assertStringContainsString("$n@hotel.com", $html, $n);
        }
        $pos = fn ($e) => strpos($html, $e . '@hotel.com');
        $order = array_map($pos, ['fay', 'bea', 'anna', 'cara', 'dina', 'eve']);
        $sorted = $order;
        sort($sorted);
        $this->assertSame($sorted, $order, 'pending (newest first), then everything else newest first');
        $this->assertStringContainsString('<option value="" selected>All statuses (6)</option>', $html);
    }

    public function test_the_status_dropdown_has_every_option_with_its_count_and_each_filter_returns_the_right_rows(): void
    {
        $this->seedSix();
        $html = $this->page();
        foreach (['Pending (2)', 'Approved (1)', 'Rejected (1)', 'Completed (1)', 'Expired (1)'] as $label) {
            $this->assertStringContainsString($label, $html, $label);
        }
        $this->assertStringContainsString('for="prStatus">Status<', $html);

        $expect = ['pending' => ['bea', 'fay'], 'approved' => ['cara'], 'rejected' => ['dina'], 'completed' => ['anna'], 'expired' => ['eve']];
        foreach ($expect as $status => $names) {
            $page = $this->page(['status' => $status]);
            foreach (['anna', 'bea', 'cara', 'dina', 'eve', 'fay'] as $n) {
                in_array($n, $names, true)
                    ? $this->assertStringContainsString("$n@hotel.com", $page, "$status shows $n")
                    : $this->assertStringNotContainsString("$n@hotel.com", $page, "$status hides $n");
            }
            $this->assertStringContainsString("<option value=\"$status\" selected>", $page, 'the selected filter comes back with the page (it is in the URL)');
        }
    }

    public function test_role_filter_and_search_work_alone_and_together(): void
    {
        $this->seedSix();
        $managers = $this->page(['role' => 'manager']);
        $this->assertStringContainsString('anna@hotel.com', $managers);
        $this->assertStringNotContainsString('bea@hotel.com', $managers);
        $desk = $this->page(['role' => 'receptionist', 'status' => 'pending']);
        $this->assertStringContainsString('bea@hotel.com', $desk);
        $this->assertStringNotContainsString('dina@hotel.com', $desk);
        $this->assertStringNotContainsString('fay@hotel.com', $desk);

        $byName = $this->page(['search' => 'cara staff']);
        $this->assertStringContainsString('cara@hotel.com', $byName);
        $this->assertStringNotContainsString('anna@hotel.com', $byName);
        $byEmail = $this->page(['search' => 'DINA@hotel']);
        $this->assertStringContainsString('dina@hotel.com', $byEmail);
        $this->assertStringNotContainsString('eve@hotel.com', $byEmail);
        $together = $this->page(['role' => 'manager', 'status' => 'pending', 'search' => 'fay']);
        $this->assertStringContainsString('fay@hotel.com', $together);
        $this->assertStringNotContainsString('anna@hotel.com', $together);
    }

    public function test_approve_and_reject_are_only_on_pending_requests(): void
    {
        $this->seedSix();
        $done = $this->page(['status' => 'completed']);
        $this->assertStringNotContainsString('data-bs-target="#rejectModal', $done);
        $this->assertStringNotContainsString('fa-check"></i> Approve', $done);
        $this->assertStringContainsString('No action needed', $done);

        $pending = $this->page(['status' => 'pending']);
        $this->assertSame(4, substr_count($pending, '<i class="fas fa-check"></i> Approve'), 'Approve in the table and in the phone card, for each of the two pending requests');
        $this->assertSame(2, substr_count($pending, 'id="rejectModal'), 'one reject dialog each');
        $this->assertStringContainsString('data-confirm-title="Approve this request?"', $pending, 'Approve asks first');
    }

    public function test_empty_states_match_the_filter_and_it_lists_ten_per_page(): void
    {
        $this->assertStringContainsString('No password reset requests', $this->page());
        $this->req('Gus', 'manager', 'completed', '2026-11-02 10:00:00');
        $this->assertStringContainsString('No pending requests', $this->page(['status' => 'pending']));
        $this->assertStringContainsString('No approved requests', $this->page(['status' => 'approved']));
        $this->assertStringContainsString('No requests match your filters', $this->page(['search' => 'zzz']));
        $this->assertStringContainsString('No requests match your filters', $this->page(['role' => 'receptionist']));

        foreach (range(1, 12) as $i) {
            $this->req('P' . chr(96 + $i), 'manager', 'pending', '2026-11-06 ' . sprintf('%02d', $i) . ':00:00');
        }
        $first = $this->page();
        $this->assertSame(10, substr_count($first, 'class="pr-chip pending"') / 2, 'ten requests on the first page (the table and the phone cards both list them)');
        $this->assertStringContainsString('page=2', $first);
    }
}
