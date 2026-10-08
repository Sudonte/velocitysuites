<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Contracts\Debug\ExceptionHandler;
use Illuminate\Database\QueryException;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * A failed query that saves a password hash / token must not write the value to the log
 * (2026-09-30: a failed User::create() in artisan tinker logged a bcrypt hash).
 */
class QueryExceptionLogRedactionTest extends TestCase
{
    private string $logFile;

    protected function setUp(): void
    {
        parent::setUp();
        Schema::create('users', function (Blueprint $table) {
            $table->id();
            $table->string('first_name'); // NOT NULL without default: inserting without it fails, like production did
            $table->string('last_name')->nullable();
            $table->string('email')->unique();
            $table->string('role')->default('guest');
            $table->string('status')->default('active');
            $table->string('password');
            $table->timestamp('email_verified_at')->nullable();
            $table->timestamps();
        });
        Schema::create('api_tokens', function (Blueprint $table) {
            $table->id();
            $table->string('token')->unique();
            $table->timestamps();
        });

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

    private function logged(): string
    {
        return (string) file_get_contents($this->logFile);
    }

    public function test_a_failing_insert_that_saves_a_password_hash_does_not_log_the_hash(): void
    {
        $hash = Hash::make('Sup3r-Secret-Pw!');
        $caught = null;
        try {
            User::create(['email' => 'qa@example.test', 'password' => $hash, 'role' => 'guest']); // no first_name
        } catch (QueryException $e) {
            $caught = $e;
        }
        $this->assertNotNull($caught, 'the insert really fails');
        $this->assertStringContainsString($hash, $caught->getMessage(), 'precondition: Laravel\'s own message contains the hash');

        app(ExceptionHandler::class)->report($caught); // exactly what the framework does for an uncaught exception

        $log = $this->logged();
        $this->assertNotSame('', $log, 'the failure is still logged');
        $this->assertStringNotContainsString($hash, $log);
        $this->assertStringNotContainsString('Sup3r-Secret-Pw', $log);
        $this->assertStringNotContainsString('$2y$', $log);
        $this->assertStringNotContainsString('qa@example.test', $log, 'no bound values at all');
        $this->assertStringContainsString('first_name', $log, 'the database error that explains the failure is kept');
        $this->assertStringContainsString('insert into "users"', $log, 'the SQL, with placeholders, is kept');
        $this->assertStringContainsString('binding(s) omitted', $log);
        $this->assertStringContainsString('QueryException', $log);
    }

    public function test_an_update_saving_a_hash_and_a_duplicate_token_do_not_leak_either(): void
    {
        $hash = Hash::make('another-secret');
        DB::table('api_tokens')->insert(['token' => 'tok_live_ABCDEF123456']);
        try {
            DB::table('api_tokens')->insert(['token' => 'tok_live_ABCDEF123456']);
            $this->fail('duplicate token insert should fail');
        } catch (QueryException $e) {
            app(ExceptionHandler::class)->report($e);
        }
        try {
            DB::update('update users set password = ?, bogus_column = 1 where id = ?', [$hash, 1]);
            $this->fail('bad update should fail');
        } catch (QueryException $e) {
            app(ExceptionHandler::class)->report($e);
        }

        $log = $this->logged();
        $this->assertStringNotContainsString('tok_live_ABCDEF123456', $log);
        $this->assertStringNotContainsString($hash, $log);
        $this->assertStringContainsString('update users set password = ?', $log);
    }

    public function test_redact_masks_secret_looking_text_and_other_exceptions_are_still_logged_normally(): void
    {
        $hash = Hash::make('x');
        $masked = \App\Support\SafeExceptionLog::redact("bad: password = 'hunter2' token='abc' $hash Duplicate entry 'zzz' for key 'k' Bearer abc.def");
        foreach (['hunter2', "'abc'", $hash, 'zzz', 'abc.def'] as $secret) {
            $this->assertStringNotContainsString($secret, $masked);
        }

        app(ExceptionHandler::class)->report(new \RuntimeException('something else broke'));
        $this->assertStringContainsString('something else broke', $this->logged(), 'exception logging is not turned off');
    }
}
