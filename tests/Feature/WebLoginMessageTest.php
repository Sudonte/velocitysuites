<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Support\Facades\Schema;

/**
 * The web login shows one message for an unknown email, a wrong password and
 * a suspended account, so it never reveals which accounts exist.
 */
class WebLoginMessageTest extends ApiFlowTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        if (! Schema::hasColumn('users', 'failed_login_attempts')) {
            Schema::table('users', fn ($table) => $table->unsignedInteger('failed_login_attempts')->default(0));
        }
    }

    public function test_failed_logins_share_one_message(): void
    {
        User::create([
            'first_name' => 'Real', 'last_name' => 'User', 'email' => 'real@example.test',
            'password' => bcrypt('right-password'), 'role' => 'guest', 'status' => 'active', 'email_verified_at' => now(),
        ]);
        User::create([
            'first_name' => 'Sus', 'last_name' => 'Pended', 'email' => 'suspended@example.test',
            'password' => bcrypt('right-password'), 'role' => 'guest', 'status' => 'suspended', 'email_verified_at' => now(),
        ]);

        $messages = collect([
            ['email' => 'nobody@example.test', 'password' => 'x'],
            ['email' => 'real@example.test', 'password' => 'wrong'],
            ['email' => 'suspended@example.test', 'password' => 'right-password'],
        ])->map(function ($credentials) {
            $this->from(route('login'))->post(route('login.post'), $credentials)->assertRedirect(route('login'));

            return session('error');
        });

        $this->assertCount(1, $messages->unique());
        $this->assertStringContainsString('email or password you entered is incorrect', $messages->first());
    }
}
