<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * Coverage for the must_change_password gate: new/reset staff accounts
 * are provisioned with the shared UserManagementController::
 * DEFAULT_STAFF_PASSWORD and must_change_password=true. These tests make
 * sure the force-change gate actually gates on that flag - independent of
 * whatever password value the account was given - and clears it once a
 * real permanent password is set.
 */
class StaffPasswordHardeningTest extends TestCase
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
            $table->boolean('must_change_password')->default(false);
            $table->timestamp('last_login_at')->nullable();
            $table->timestamp('deleted_at')->nullable();
            $table->timestamp('restore_deadline')->nullable();
            $table->timestamp('deactivated_at')->nullable();
            $table->rememberToken();
            $table->timestamps();
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
            $table->string('ip_address')->nullable();
            $table->timestamps();
        });
    }

    protected function tearDown(): void
    {
        Schema::dropIfExists('activity_logs');
        Schema::dropIfExists('staff_password_reset_requests');
        Schema::dropIfExists('users');
        parent::tearDown();
    }

    public function test_login_redirects_to_force_change_when_flag_set(): void
    {
        $user = User::factory()->create([
            'role' => 'receptionist',
            'status' => 'active',
            'password' => Hash::make('Temp-Pw-1!'),
            'must_change_password' => true,
        ]);

        $response = $this->post('/login', ['email' => $user->email, 'password' => 'Temp-Pw-1!']);

        $response->assertRedirect(route('force-password-change.show'));
    }

    public function test_login_goes_straight_to_dashboard_when_flag_clear(): void
    {
        $user = User::factory()->create([
            'role' => 'receptionist',
            'status' => 'active',
            'password' => Hash::make('Real-Pw-1!'),
            'must_change_password' => false,
        ]);

        $response = $this->post('/login', ['email' => $user->email, 'password' => 'Real-Pw-1!']);

        $response->assertRedirect(route('receptionist.dashboard'));
    }

    public function test_force_change_screen_redirects_away_once_flag_already_clear(): void
    {
        $user = User::factory()->create([
            'role' => 'manager',
            'must_change_password' => false,
        ]);

        $response = $this->actingAs($user)->get('/force-change-password');

        $response->assertRedirect(route('manager.dashboard'));
    }

    public function test_submitting_new_password_clears_flag_and_reaches_dashboard(): void
    {
        $user = User::factory()->create([
            'role' => 'manager',
            'password' => Hash::make('Temp-Pw-1!'),
            'must_change_password' => true,
        ]);

        $response = $this->actingAs($user)->post('/force-change-password', [
            'password' => 'Brand-New-Real-Pw-1!',
            'password_confirmation' => 'Brand-New-Real-Pw-1!',
        ]);

        $response->assertRedirect(route('manager.dashboard'));
        $this->assertFalse($user->refresh()->must_change_password);

        // Fresh login with the new permanent password now goes straight to
        // the dashboard - the account is no longer stuck being asked to
        // change its password on every single login.
        $this->post('/logout');
        $second = $this->post('/login', ['email' => $user->email, 'password' => 'Brand-New-Real-Pw-1!']);
        $second->assertRedirect(route('manager.dashboard'));
    }
}
