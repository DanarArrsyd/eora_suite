<?php

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Tests\Support\TestDatabaseGuard;

abstract class TestCase extends BaseTestCase
{
    /**
     * Checked right after boot, before any trait (RefreshDatabase, ...) or test touches the database.
     */
    public function createApplication()
    {
        $app = parent::createApplication();

        $config = $app->make('config');
        $allowedHosts = getenv('EORA_TEST_DB_HOST_ALLOW') ?: null;
        // The runtime connection and the migrations-owner connection must both point at a local *_test database.
        foreach ([(string) $config->get('database.default'), 'pgsql_owner'] as $name) {
            TestDatabaseGuard::assertSafe(
                $app->environment(),
                (array) $config->get('database.connections.'.$name),
                $allowedHosts,
            );
        }

        return $app;
    }
}
