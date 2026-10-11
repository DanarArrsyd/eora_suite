<?php

declare(strict_types=1);

namespace Tests\Support;

use RuntimeException;

/**
 * Refuses to boot the test suite unless it targets a dedicated test database.
 *
 * Tests run destructive operations (migrate:fresh, RefreshDatabase). phpunit.xml forces the test
 * configuration; this guard is the second line of defence for anything that still slips through
 * (another phpunit config, a DB_URL, a missing force attribute) — the run fails before any query.
 */
final class TestDatabaseGuard
{
    /** "eora_test", plus Laravel parallel-testing copies such as "eora_test_test_3". */
    private const string TEST_DATABASE = '/^[A-Za-z0-9_]+_test(?:_test_\d+)?$/';

    /** Local Docker/CI hosts only (doc 25 §10); override with EORA_TEST_DB_HOST_ALLOW (comma separated). */
    private const string DEFAULT_HOSTS = '127.0.0.1,localhost,::1,postgres,pgbouncer';

    /**
     * @param  array<string, mixed>  $connection  a database connection config used by the suite
     */
    public static function assertSafe(string $environment, array $connection, ?string $allowedHosts = null): void
    {
        if ($environment !== 'testing') {
            self::refuse("APP_ENV is \"{$environment}\", expected \"testing\".");
        }

        if (! empty($connection['url'])) {
            self::refuse('DB_URL is set; it would override the test database name.');
        }

        $database = is_string($connection['database'] ?? null) ? $connection['database'] : '';

        if (preg_match(self::TEST_DATABASE, $database) !== 1) {
            self::refuse("database \"{$database}\" is not a dedicated test database (name must end in \"_test\").");
        }

        $host = is_string($connection['host'] ?? null) ? $connection['host'] : '';
        $allowed = array_filter(array_map('trim', explode(',', $allowedHosts ?? self::DEFAULT_HOSTS)));

        if (! in_array($host, $allowed, true)) {
            self::refuse("database host \"{$host}\" is not an allowed local test host (EORA_TEST_DB_HOST_ALLOW).");
        }
    }

    private static function refuse(string $reason): never
    {
        throw new RuntimeException("Refusing to run tests: {$reason} Destructive test operations would hit a non-test database.");
    }
}
