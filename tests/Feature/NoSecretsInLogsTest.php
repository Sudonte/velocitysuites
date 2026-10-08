<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\PasswordResetService;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

/**
 * One-time codes, tokens and passwords must never reach a log file.
 */
class NoSecretsInLogsTest extends TestCase
{
    private string $logFile;

    protected function setUp(): void
    {
        parent::setUp();
        Schema::create('users', function (Blueprint $table) {
            $table->id();
            $table->string('first_name');
            $table->string('last_name');
            $table->string('email')->unique();
            $table->string('role')->default('guest');
            $table->string('status')->default('active');
            $table->string('password');
            $table->timestamps();
        });
        Schema::create('password_reset_tokens', function (Blueprint $table) {
            $table->string('email')->primary();
            $table->string('token');
            $table->timestamp('created_at')->nullable();
        });

        // Send EVERYTHING (debug and up) to a throwaway file so the assertion sees the complete log output.
        $this->logFile = tempnam(sys_get_temp_dir(), 'velocitylog');
        config(['logging.channels.capture' => ['driver' => 'single', 'path' => $this->logFile, 'level' => 'debug']]);
        config(['logging.default' => 'capture']);
        Log::forgetChannel('capture');
    }

    protected function tearDown(): void
    {
        @unlink($this->logFile);
        parent::tearDown();
    }

    public function test_send_otp_logs_that_a_code_was_sent_but_never_the_code(): void
    {
        $user = User::create(['first_name' => 'A', 'last_name' => 'B', 'email' => 'a@example.test', 'password' => bcrypt('x')]);

        $sentCodes = [];
        Mail::shouldReceive('raw')->once()->andReturnUsing(function ($body, $callback) use (&$sentCodes) {
            preg_match('/code is: (\d{6})/', $body, $m);
            $sentCodes[] = $m[1];
        });

        $this->assertTrue(app(PasswordResetService::class)->sendOtp($user));

        $this->assertCount(1, $sentCodes, 'a code was generated and handed to the mailer');
        $log = (string) file_get_contents($this->logFile);
        $this->assertStringNotContainsString($sentCodes[0], $log, 'the reset code must not appear anywhere in the log');
        $this->assertStringContainsString('Password reset code sent', $log);
        $this->assertStringContainsString('"user_id":'.$user->id, $log);
        $this->assertStringContainsString('"channel":"email"', $log);
        $this->assertMatchesRegularExpression('/"at":"\d{4}-\d{2}-\d{2}T/', $log);
    }

    public function test_a_failed_send_does_not_log_the_code_either(): void
    {
        $user = User::create(['first_name' => 'A', 'last_name' => 'B', 'email' => 'b@example.test', 'password' => bcrypt('x')]);
        $captured = null;
        Mail::shouldReceive('raw')->once()->andReturnUsing(function ($body) use (&$captured) {
            preg_match('/code is: (\d{6})/', $body, $m);
            $captured = $m[1];
            throw new \RuntimeException('smtp down');
        });

        $this->assertFalse(app(PasswordResetService::class)->sendOtp($user));
        $log = (string) file_get_contents($this->logFile);
        $this->assertNotNull($captured);
        $this->assertStringNotContainsString($captured, $log);
        $this->assertStringContainsString('Failed to email password reset OTP', $log);
    }

    /** Static guard: no logging call anywhere in the backend may interpolate a code, token or password. */
    public function test_no_log_statement_in_the_app_writes_a_secret(): void
    {
        $offenders = [];
        $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator(base_path('app')));
        foreach ($iterator as $file) {
            if ($file->getExtension() !== 'php') {
                continue;
            }
            foreach (file($file->getPathname()) as $n => $line) {
                if (preg_match('/\bLog::|logger\(|error_log\(/', $line)
                    && preg_match('/\$(otp|token|plainToken|password|newPassword|code)\b|->(password|token|otp)\b/i', $line)) {
                    $offenders[] = str_replace(base_path().DIRECTORY_SEPARATOR, '', $file->getPathname()).':'.($n + 1).' '.trim($line);
                }
            }
        }
        $this->assertSame([], $offenders, "log lines that write a secret:\n".implode("\n", $offenders));
    }
}
