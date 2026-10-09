<?php

declare(strict_types=1);

use Tests\Support\TestDatabaseGuard;

$pgsql = fn (string $database, string $url = ''): array => ['driver' => 'pgsql', 'database' => $database, 'url' => $url];

it('accepts the dedicated test database and its parallel-testing copies', function (string $database) use ($pgsql) {
    TestDatabaseGuard::assertSafe('testing', $pgsql($database));
    expect(true)->toBeTrue();
})->with(['eora_test', 'eora_test_test_3']);

it('refuses development, production or ambiguous database names', function (string $database) use ($pgsql) {
    TestDatabaseGuard::assertSafe('testing', $pgsql($database));
})->with(['eora', 'eora_production', 'eora_test_prod', 'test', ''])
    ->throws(RuntimeException::class, 'Refusing to run tests');

it('refuses to run outside the testing environment', function () use ($pgsql) {
    TestDatabaseGuard::assertSafe('local', $pgsql('eora_test'));
})->throws(RuntimeException::class, 'APP_ENV');

it('refuses a DB_URL, which would override the database name', function () use ($pgsql) {
    TestDatabaseGuard::assertSafe('testing', $pgsql('eora_test', 'pgsql://user:secret@db:5432/eora'));
})->throws(RuntimeException::class, 'DB_URL');

it('never echoes the connection URL (it may hold credentials)', function () use ($pgsql) {
    try {
        TestDatabaseGuard::assertSafe('testing', $pgsql('eora_test', 'pgsql://user:secret@db:5432/eora'));
    } catch (RuntimeException $e) {
        expect($e->getMessage())->not->toContain('secret');

        return;
    }
    $this->fail('Expected the guard to refuse a DB_URL.');
});
