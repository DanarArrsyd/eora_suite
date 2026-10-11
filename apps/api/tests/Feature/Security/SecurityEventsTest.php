<?php

declare(strict_types=1);

use App\Platform\Database\EoraPostgresConnection;
use App\Platform\Security\RetrySecurityEventFlush;
use App\Platform\Security\SecurityEvent;
use App\Platform\Security\SecurityEventRecorder;
use App\Platform\Security\SecurityHasher;
use App\Platform\Tenancy\TenantContext;
use App\Platform\Tenancy\TenantTransaction;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Tests\Support\TenantFixtures;

/*
 * Durable security events (doc 25 §8, D-B1-20).
 */

function ctxOf(array $t): TenantContext
{
    return TenantContext::forTenant($t['tenant'], $t['actor']);
}

function sqlAs(array $t, string $sql, array $bindings = []): mixed
{
    return app(TenantTransaction::class)->run(ctxOf($t), fn (EoraPostgresConnection $db) => $db->statement($sql, $bindings));
}

beforeEach(function () {
    $this->a = TenantFixtures::provision(1);
    $this->b = TenantFixtures::provision(1);
});

it('records buffered events after the business transaction, even when that transaction rolled back', function () {
    $recorder = app(SecurityEventRecorder::class);

    try {
        app(TenantTransaction::class)->run(ctxOf($this->a), function () use ($recorder) {
            $recorder->record(new SecurityEvent('access.forbidden', resource: 'plants', requestedId: 'abc-123', httpStatus: 403, correlationId: 'req-12345678'));
            throw new RuntimeException('business failure');
        });
    } catch (RuntimeException) {
    }
    $recorder->flush(ctxOf($this->a));

    $rows = app(TenantTransaction::class)->run(ctxOf($this->a), fn (EoraPostgresConnection $db) => $db->select('select source, action, user_id, http_status from security_events'));

    expect($rows)->toHaveCount(1)
        ->and($rows[0]->source)->toBe('app')
        ->and($rows[0]->action)->toBe('access.forbidden')
        ->and($rows[0]->user_id)->toBe($this->a['actor'])
        ->and($rows[0]->http_status)->toBe(403)
        ->and($recorder->pending())->toBe([]);
})->group('integration', 'security');

it('lets the runtime role write only app events of its own tenant and actor', function (string $sql, Closure $bindings) {
    sqlAs($this->a, $sql, $bindings($this));
})->with([
    'auth source' => ["insert into security_events (tenant_id, source, action) values (?, 'auth_web', 'access.forbidden')", fn ($t) => [$t->a['tenant']]],
    'reserved auth.* action' => ["insert into security_events (tenant_id, source, action) values (?, 'app', 'auth.failed')", fn ($t) => [$t->a['tenant']]],
    'other tenant' => ["insert into security_events (tenant_id, source, action) values (?, 'app', 'access.forbidden')", fn ($t) => [$t->b['tenant']]],
    'other user' => ["insert into security_events (tenant_id, user_id, source, action) values (?, ?, 'app', 'access.forbidden')", fn ($t) => [$t->a['tenant'], $t->b['actor']]],
])->throws(QueryException::class)->group('integration', 'security');

it('is append-only and tenant-isolated', function () {
    $recorder = app(SecurityEventRecorder::class);
    $recorder->record(new SecurityEvent('access.not_found', requestedId: 'x1'));
    $recorder->flush(ctxOf($this->a));

    expect(fn () => sqlAs($this->a, 'update security_events set action = ?', ['access.forbidden']))->toThrow(QueryException::class, 'permission denied')
        ->and(fn () => sqlAs($this->a, 'delete from security_events'))->toThrow(QueryException::class, 'permission denied');

    $seenByB = app(TenantTransaction::class)->run(ctxOf($this->b), fn (EoraPostgresConnection $db) => count($db->select('select id from security_events')));
    expect($seenByB)->toBe(0);
})->group('integration', 'security');

it('gives even the table owner no way to rewrite events: RLS hides the rows, TRUNCATE is blocked', function () {
    $recorder = app(SecurityEventRecorder::class);
    $recorder->record(new SecurityEvent('access.not_found', requestedId: 'x2'));
    $recorder->flush(ctxOf($this->a));
    $owner = DB::connection('pgsql_owner');

    // forced RLS without an owner policy: the owner's UPDATE/DELETE match no row (and a row trigger would refuse anyway)
    $changed = $owner->transaction(fn () => $owner->update("update security_events set action = 'access.x'") + $owner->delete('delete from security_events'));
    expect($changed)->toBe(0);
    expect(fn () => $owner->transaction(fn () => $owner->statement('truncate security_events')))->toThrow(QueryException::class, 'append-only');

    $kept = app(TenantTransaction::class)->run(ctxOf($this->a), fn (EoraPostgresConnection $db) => $db->select('select action from security_events'));
    expect(array_column($kept, 'action'))->toBe(['access.not_found']);
})->group('integration', 'security');

it('rejects reserved or malformed actions before they reach the database', function (string $action) {
    new SecurityEvent($action);
})->with(['auth.failed', 'Access.Forbidden', 'nodot', ''])->throws(InvalidArgumentException::class)->group('security');

it('queues a retry instead of failing the response when the flush fails', function () {
    Queue::fake();
    $recorder = app(SecurityEventRecorder::class);
    $recorder->record(new SecurityEvent('access.forbidden', httpStatus: 403));

    // a tenant that does not exist violates the foreign key: the write fails, the caller is unaffected
    $recorder->flush(TenantContext::forTenant('00000000-0000-7000-8000-000000000000', $this->a['actor']));

    Queue::assertPushed(RetrySecurityEventFlush::class, fn (RetrySecurityEventFlush $job) => $job->events[0]['action'] === 'access.forbidden');
})->group('integration', 'security');

it('never fails the caller when both the database write and the retry queue fail', function () {
    Bus::shouldReceive('dispatch')->andThrow(new RuntimeException('redis down'));
    $recorder = app(SecurityEventRecorder::class);
    $recorder->record(new SecurityEvent('access.forbidden', httpStatus: 403));

    $recorder->flush(TenantContext::forTenant('00000000-0000-7000-8000-000000000000', $this->a['actor']));

    expect($recorder->pending())->toBe([]);
})->group('integration', 'security');

it('stores client identifiers only as keyed hashes', function () {
    $hasher = app(SecurityHasher::class);
    $hash = $hasher->hash('203.0.113.7');

    expect($hash)->toMatch('/^[0-9a-f]{64}$/')
        ->and($hash)->not->toBe(hash('sha256', '203.0.113.7'))
        ->and($hasher->hash(''))->toBeNull();
})->group('security');
