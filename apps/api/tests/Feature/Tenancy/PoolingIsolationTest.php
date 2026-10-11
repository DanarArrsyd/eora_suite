<?php

declare(strict_types=1);

use App\Platform\Database\EoraPostgresConnection;
use App\Platform\Tenancy\TenantContext;
use App\Platform\Tenancy\TenantTransaction;
use Illuminate\Support\Facades\DB;
use Tests\Support\TenantFixtures;

/*
 * T-ISO-03 / PoC-CTX-08 (ADR-0001, doc 25 §12): interleaved tenants through PgBouncer transaction pooling. The eora_test
 * pool has 2 server connections, so 6 clients constantly reuse each other's server connections. Native prepared
 * statements are created and destroyed inside every transaction (PoC-CTX-13, libpq >= 17).
 */
it('never leaks rows or context across pooled server connections', function () {
    if (config('database.connections.pgsql.host') !== 'pgbouncer') {
        $this->markTestSkipped('needs the PgBouncer service (docker job / Compose)');
    }

    $tenants = [TenantFixtures::provision(), TenantFixtures::provision(), TenantFixtures::provision()];
    $clients = [];
    for ($i = 0; $i < 6; $i++) {
        config(["database.connections.pool_{$i}" => config('database.connections.pgsql')]);
        $clients[] = "pool_{$i}";
    }

    $wrong = 0;
    $withoutContext = 0;
    $pidsByTenant = [];
    for ($round = 0; $round < 40; $round++) {
        foreach ($clients as $n => $client) {
            $t = $tenants[($round + $n) % 3];
            [$ids, $pid] = app(TenantTransaction::class)->run(TenantContext::forTenant($t['tenant'], $t['actor']), function (EoraPostgresConnection $db) {
                return [array_column($db->select('select tenant_id from plants'), 'tenant_id'), $db->transactionBinding()['pid']];
            }, $client);
            $pidsByTenant[$pid][$t['tenant']] = true;
            $wrong += count(array_diff($ids, [$t['tenant']])) + (count($ids) === 2 ? 0 : 1);
            $withoutContext += count(DB::connection($client)->transaction(fn ($db) => $db->select('select id from plants')));
        }
    }

    $sharedPids = count(array_filter($pidsByTenant, fn (array $seen) => count($seen) > 1));

    expect($wrong)->toBe(0)
        ->and($withoutContext)->toBe(0)
        ->and($sharedPids)->toBeGreaterThan(0); // server connections really were reused across tenants
})->group('integration', 'isolation', 'pgbouncer');
