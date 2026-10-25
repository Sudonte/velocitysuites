<?php

namespace Tests\Feature;

use App\Http\Controllers\Auth\LoginController;
use App\Models\User;
use Illuminate\Support\Facades\Schema;

/**
 * The web login shows one message for an unknown email and a wrong password - also on a
 * suspended, deactivated or deleted account - so it never reveals which accounts exist.
 * With the right password, a blocked account is told why it can't sign in.
 */
class WebLoginMessageTest extends ApiFlowTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Schema::table('users', function ($table) {
            foreach (['failed_login_attempts' => 'int', 'last_failed_login_at' => 'ts', 'deleted_at' => 'ts', 'restore_deadline' => 'ts', 'last_login_at' => 'ts'] as $col => $type) {
                if (! Schema::hasColumn('users', $col)) {
                    $type === 'int' ? $table->unsignedInteger($col)->default(0) : $table->timestamp($col)->nullable();
                }
            }
        });
    }

    private function account(string $email, array $attrs = []): User
    {
        return User::forceCreate(array_merge([
            'first_name' => 'Test', 'last_name' => 'User', 'email' => $email,
            'password' => bcrypt('right-password'), 'role' => 'guest', 'status' => 'active', 'email_verified_at' => now(),
        ], $attrs));
    }

    private function loginMessage(string $email, string $password): ?string
    {
        $this->from(route('login'))->post(route('login.post'), compact('email', 'password'))->assertRedirect(route('login'));
        $this->assertGuest();

        return session('error');
    }

    public function test_wrong_guesses_share_one_message(): void
    {
        $this->account('real@example.test');
        $this->account('suspended@example.test', ['status' => 'suspended']);
        $this->account('deactivated@example.test', ['status' => 'deactivated']);
        $this->account('deleted@example.test', ['deleted_at' => now()->subDays(40), 'restore_deadline' => now()->subDays(10)]);

        $messages = collect([
            ['nobody@example.test', 'x'],
            ['real@example.test', 'wrong'],
            ['suspended@example.test', 'wrong'],
            ['deactivated@example.test', 'wrong'],
            ['deleted@example.test', 'wrong'],
        ])->map(fn ($c) => $this->loginMessage(...$c));

        $this->assertCount(1, $messages->unique());
        $this->assertStringContainsString('Incorrect email or password', $messages->first());
    }

    public function test_right_password_on_a_blocked_account_says_why(): void
    {
        $suspended = $this->account('suspended@example.test', ['status' => 'suspended']);
        $this->account('deactivated@example.test', ['status' => 'deactivated']);
        $this->account('deleted@example.test', ['deleted_at' => now()->subDays(40), 'restore_deadline' => now()->subDays(10)]);

        $this->assertSame(LoginController::SUSPENDED_MESSAGE, $this->loginMessage('suspended@example.test', 'right-password'));
        $this->assertSame(LoginController::DEACTIVATED_MESSAGE, $this->loginMessage('deactivated@example.test', 'right-password'));
        $this->assertSame(LoginController::DELETED_MESSAGE, $this->loginMessage('deleted@example.test', 'right-password'));

        // Refused sign-ins on a blocked account don't count toward the 3-attempt lock.
        $this->assertSame(0, (int) $suspended->fresh()->failed_login_attempts);
    }

    public function test_an_active_account_still_signs_in(): void
    {
        $this->account('real@example.test');

        $this->post(route('login.post'), ['email' => 'real@example.test', 'password' => 'right-password'])
            ->assertRedirect(route('guest.dashboard'));
        $this->assertAuthenticated();
    }
}
