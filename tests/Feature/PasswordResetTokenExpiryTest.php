<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\PasswordResetService;
use Carbon\Carbon;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * password_reset_tokens expiry is enforced (15 minutes, checked when the code is verified), so a token row
 * restored from an old backup - e.g. the 24 Sep rows restored after the 2026-10-08 incident - can never be used.
 */
class PasswordResetTokenExpiryTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
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

    private function seedToken(string $email, string $otp, string $createdAt): void
    {
        DB::table('password_reset_tokens')->insert(['email' => $email, 'token' => Hash::make($otp), 'created_at' => $createdAt]);
    }

    public function test_a_token_restored_from_a_backup_weeks_ago_cannot_be_used(): void
    {
        Carbon::setTestNow('2026-10-08 12:00:00');
        $this->seedToken('restored@example.test', '123456', '2026-09-24 11:32:12');

        $this->assertFalse(app(PasswordResetService::class)->verifyOtp('restored@example.test', '123456'), 'even the correct code is refused');
        $this->assertDatabaseMissing('password_reset_tokens', ['email' => 'restored@example.test']);
    }

    public function test_the_window_is_fifteen_minutes(): void
    {
        $service = app(PasswordResetService::class);

        Carbon::setTestNow('2026-10-08 12:14:00');
        $this->seedToken('a@example.test', '654321', '2026-10-08 12:00:00');
        $this->assertTrue($service->verifyOtp('a@example.test', '654321'));
        $this->assertFalse($service->verifyOtp('a@example.test', '000000'), 'a wrong code never verifies');

        Carbon::setTestNow('2026-10-08 12:16:00');
        $this->assertFalse($service->verifyOtp('a@example.test', '654321'), 'expired after 15 minutes');
    }
}
