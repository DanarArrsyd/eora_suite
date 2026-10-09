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
        TestDatabaseGuard::assertSafe(
            $app->environment(),
            (array) $config->get('database.connections.'.$config->get('database.default')),
        );

        return $app;
    }
}
