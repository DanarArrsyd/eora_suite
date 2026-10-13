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
        // Every connection the suite uses (runtime, owner, provisioner, auth web, delivery, relay) must point at a local *_test database.
        foreach ([(string) $config->get('database.default'), 'pgsql_owner', 'pgsql_provisioner', 'pgsql_auth', 'pgsql_delivery', 'pgsql_relay'] as $name) {
            TestDatabaseGuard::assertSafe(
                $app->environment(),
                (array) $config->get('database.connections.'.$name),
                $allowedHosts,
            );
        }

        return $app;
    }

    /**
     * Closes every database connection the test opened (runtime, owner, provisioner, auth, delivery). Without this the
     * PDO handles live until garbage collection and a direct PostgreSQL (CI, no pooler) runs out of connection slots.
     */
    protected function tearDown(): void
    {
        if (isset($this->app)) {
            foreach (array_keys($this->app['db']->getConnections()) as $name) {
                $this->app['db']->disconnect($name);
            }
        }

        parent::tearDown();
    }
}
