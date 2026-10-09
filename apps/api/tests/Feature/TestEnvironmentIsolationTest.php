<?php

declare(strict_types=1);

// phpunit.xml forces these values (force="true"), so they hold even when the process environment
// points elsewhere — e.g. inside the Docker api container, where DB_DATABASE=eora and APP_ENV=local.
it('boots the test suite against the isolated test configuration', function () {
    expect(app()->environment())->toBe('testing')
        ->and(config('database.default'))->toBe('pgsql')
        ->and(config('database.connections.pgsql.database'))->toBe('eora_test')
        ->and(config('database.connections.pgsql.url'))->toBeEmpty()
        ->and(config('cache.default'))->toBe('array')
        ->and(config('queue.default'))->toBe('sync')
        ->and(config('session.driver'))->toBe('array')
        // LOG_CHANNEL="null" reaches config as PHP null; Laravel then logs to the "null" channel under tests.
        ->and(config('logging.default'))->toBeNull()
        ->and(app('log')->getDefaultDriver())->toBeNull();
});
