<?php

namespace Tests\Support;

/**
 * Decides whether it is safe to run the test suite at all.
 *
 * Tests create and DROP tables by hand and write rows. On 2026-10-08 a run on the production host
 * did exactly that to the LIVE database, because a cached config (bootstrap/cache/config.php) made
 * the app ignore phpunit.xml's sqlite settings. This guard makes that impossible: it is called from
 * tests/bootstrap.php (before any test or the framework even loads) and again from
 * Tests\TestCase::createApplication() (before a test can touch a connection).
 *
 * The suite may only run when ALL of these hold:
 *   - APP_ENV is "testing";
 *   - the database connection is sqlite and the database is the in-memory one (":memory:");
 *   - no DB_URL / APP_CONFIG_CACHE override is set;
 *   - there is no cached config file (bootstrap/cache/config.php).
 *
 * Run tests on a developer machine only - never on the server (see README).
 */
final class TestEnvironmentGuard
{
    public const EXIT_CODE = 2;

    /**
     * @param  array<string,string|false|null>  $env  effective environment values
     * @return list<string> human-readable reasons the run must be refused (empty = safe)
     */
    public static function violations(array $env, string $configCachePath): array
    {
        $get = static fn (string $key): string => (string) ($env[$key] ?? '');
        $problems = [];

        if ($get('APP_ENV') !== 'testing') {
            $problems[] = 'APP_ENV is "'.$get('APP_ENV').'", not "testing".';
        }
        if ($get('DB_CONNECTION') !== 'sqlite') {
            $problems[] = 'DB_CONNECTION is "'.$get('DB_CONNECTION').'", not "sqlite" - this could be a real database.';
        }
        if ($get('DB_DATABASE') !== ':memory:') {
            $problems[] = 'DB_DATABASE is not the in-memory sqlite test database (":memory:").';
        }
        if ($get('DB_URL') !== '') {
            $problems[] = 'DB_URL is set, which overrides the sqlite test connection.';
        }
        if ($get('APP_CONFIG_CACHE') !== '') {
            $problems[] = 'APP_CONFIG_CACHE is set, which relocates the config cache and defeats the cached-config check.';
        }
        if (is_file($configCachePath)) {
            $problems[] = 'A cached config exists ('.$configCachePath.'). A cached config makes the app ignore phpunit.xml and use the real .env database. Run "php artisan config:clear" (on a developer machine - never run tests on the server).';
        }

        return $problems;
    }

    public static function message(array $problems): string
    {
        return "\n*** Refusing to run the test suite - it could touch a real database. ***\n"
            .' - '.implode("\n - ", $problems)."\n"
            ."Tests create and drop tables. Run them on a local machine only, never on the server. See README.\n\n";
    }

    /** Called from the phpunit bootstrap: print the reasons and stop the whole run before any test executes. */
    public static function abortUnlessSafe(string $projectRoot): void
    {
        $env = [];
        foreach (['APP_ENV', 'DB_CONNECTION', 'DB_DATABASE', 'DB_URL', 'APP_CONFIG_CACHE'] as $key) {
            $value = getenv($key);
            $env[$key] = $value !== false ? $value : ($_ENV[$key] ?? $_SERVER[$key] ?? null);
        }
        $problems = self::violations($env, $projectRoot.'/bootstrap/cache/config.php');
        if ($problems !== []) {
            fwrite(STDERR, self::message($problems));
            exit(self::EXIT_CODE);
        }
    }

    /** Second line of defence, on the booted app: the connection the app will really use must be the sqlite test one. */
    public static function assertApplicationIsSafe($app): void
    {
        $default = $app['config']->get('database.default');
        $database = $app['config']->get('database.connections.'.$default.'.database');
        $problems = [];
        if (! $app->environment('testing')) {
            $problems[] = 'The booted application environment is "'.$app->environment().'", not "testing".';
        }
        if ($default !== 'sqlite' || $database !== ':memory:') {
            $problems[] = 'The app would use connection "'.$default.'" (database "'.$database.'") instead of in-memory sqlite.';
        }
        if ($problems !== []) {
            throw new \RuntimeException(self::message($problems));
        }
    }
}
