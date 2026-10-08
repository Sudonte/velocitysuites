<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;
use Tests\Support\TestEnvironmentGuard;

/**
 * Proves the production-safety guard works: a MySQL (or any non-sqlite / non-testing / cached-config)
 * environment is refused, both by the pure rule check and by a REAL phpunit process that must abort
 * before running a single test.
 */
class TestEnvironmentGuardTest extends TestCase
{
    private const SAFE = ['APP_ENV' => 'testing', 'DB_CONNECTION' => 'sqlite', 'DB_DATABASE' => ':memory:', 'DB_URL' => '', 'APP_CONFIG_CACHE' => ''];

    private static function root(): string
    {
        return dirname(__DIR__, 2);
    }

    public function test_the_safe_sqlite_testing_environment_is_allowed(): void
    {
        $this->assertSame([], TestEnvironmentGuard::violations(self::SAFE, self::root().'/bootstrap/cache/does-not-exist.php'));
    }

    public function test_a_mysql_connection_is_refused(): void
    {
        $problems = TestEnvironmentGuard::violations(array_merge(self::SAFE, ['DB_CONNECTION' => 'mysql', 'DB_DATABASE' => 'u391777642_velocitysuites']), '/nonexistent');
        $this->assertNotEmpty($problems);
        $this->assertStringContainsString('not "sqlite"', implode("\n", $problems));
    }

    public function test_production_env_a_db_url_and_a_config_cache_override_are_each_refused(): void
    {
        $this->assertNotEmpty(TestEnvironmentGuard::violations(array_merge(self::SAFE, ['APP_ENV' => 'production']), '/nonexistent'));
        $this->assertNotEmpty(TestEnvironmentGuard::violations(array_merge(self::SAFE, ['DB_URL' => 'mysql://u:p@host/db']), '/nonexistent'));
        $this->assertNotEmpty(TestEnvironmentGuard::violations(array_merge(self::SAFE, ['APP_CONFIG_CACHE' => '/nonexistent/x.php']), '/nonexistent'));
        $this->assertNotEmpty(TestEnvironmentGuard::violations(array_merge(self::SAFE, ['DB_DATABASE' => '/var/db.sqlite']), '/nonexistent'));
    }

    public function test_a_cached_config_file_is_refused_with_a_clear_reason(): void
    {
        $cache = tempnam(sys_get_temp_dir(), 'cfg');
        try {
            $problems = TestEnvironmentGuard::violations(self::SAFE, $cache);
            $this->assertCount(1, $problems);
            $this->assertStringContainsString('cached config', $problems[0]);
            $this->assertStringContainsString('never run tests on the server', $problems[0]);
        } finally {
            @unlink($cache);
        }
    }

    /** @return array{0:int,1:string} exit code and combined output of a real phpunit run with the given env overrides */
    private function runPhpunit(array $envOverrides): array
    {
        $env = array_merge(getenv(), $envOverrides);
        $cmd = [PHP_BINARY, self::root().'/vendor/phpunit/phpunit/phpunit', '--configuration', self::root().'/phpunit.xml', '--filter', 'test_a_cached_config_file_is_refused_with_a_clear_reason'];
        $process = proc_open($cmd, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, self::root(), $env);
        $out = stream_get_contents($pipes[1]).stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);

        return [proc_close($process), $out];
    }

    public function test_a_real_phpunit_run_pointed_at_mysql_aborts_before_any_test(): void
    {
        [$code, $out] = $this->runPhpunit(['DB_CONNECTION' => 'mysql', 'DB_DATABASE' => 'u391777642_velocitysuites']);
        $this->assertSame(TestEnvironmentGuard::EXIT_CODE, $code, $out);
        $this->assertStringContainsString('Refusing to run the test suite', $out);
        $this->assertStringContainsString('not "sqlite"', $out);
        $this->assertStringNotContainsString('PHPUnit 1', $out, 'phpunit itself never started its run');
        $this->assertStringNotContainsString('OK (', $out);
    }

    public function test_a_real_phpunit_run_with_a_production_app_env_aborts(): void
    {
        [$code, $out] = $this->runPhpunit(['APP_ENV' => 'production']);
        $this->assertSame(TestEnvironmentGuard::EXIT_CODE, $code, $out);
        $this->assertStringContainsString('not "testing"', $out);
    }

    public function test_a_real_phpunit_run_with_a_cached_config_aborts(): void
    {
        $cache = self::root().'/bootstrap/cache/config.php';
        $this->assertFileDoesNotExist($cache, 'a cached config must not exist on a machine that runs tests');
        file_put_contents($cache, "<?php return [];\n");
        try {
            [$code, $out] = $this->runPhpunit([]);
        } finally {
            @unlink($cache);
        }
        $this->assertSame(TestEnvironmentGuard::EXIT_CODE, $code, $out);
        $this->assertStringContainsString('cached config', $out);
    }

    public function test_a_real_phpunit_run_with_the_safe_environment_is_not_blocked(): void
    {
        [$code, $out] = $this->runPhpunit([]);
        $this->assertSame(0, $code, $out);
        $this->assertStringContainsString('OK', $out);
    }
}
