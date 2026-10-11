<?php

declare(strict_types=1);

use App\Platform\Database\EoraPostgresConnection;
use App\Platform\Tenancy\TenantContext;
use App\Platform\Tenancy\TenantTransaction;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/*
 * Performance budget of the signed context (D-055, replaces the PoC's p95 < 2 % target): at the database level
 * ≤ 15 µs per RLS table reference and ≤ 0.3 ms p95 added per transaction compared with Option A (GUC context).
 * Medians/percentiles over interleaved runs keep host noise out of the verdict.
 */

it('keeps the verifier at or below 15 µs per evaluation (server side)', function () {
    $runs = app(TenantTransaction::class)->run(TenantContext::forTenant(Str::uuid7()->toString(), Str::uuid()->toString()), function (EoraPostgresConnection $db): array {
        $sample = function (string $expr) use ($db): float {
            $db->unprepared("DO \$do\$ DECLARE x uuid; t0 timestamptz := clock_timestamp(); BEGIN
                FOR i IN 1..5000 LOOP x := {$expr}; END LOOP;
                PERFORM set_config('eora.perf_us', (EXTRACT(epoch FROM clock_timestamp() - t0) * 1e6 / 5000)::text, true); END \$do\$");

            return (float) $db->selectOne("select current_setting('eora.perf_us') as v")->v;
        };
        $verifier = $baseline = [];
        for ($i = 0; $i < 5; $i++) {
            $verifier[] = $sample('(ctx_api.context()).tenant');
            $baseline[] = $sample("NULLIF(current_setting('eora.none', true), '')::uuid");
        }
        sort($verifier);
        sort($baseline);

        return ['verifier' => $verifier[2], 'baseline' => $baseline[2]];
    });

    expect($runs['verifier'] - $runs['baseline'])->toBeLessThanOrEqual(15.0);
})->group('integration', 'perf');

it('adds at most 0.3 ms p95 per transaction compared with Option A (plain setting) context setup', function () {
    $context = TenantContext::forTenant(Str::uuid7()->toString(), Str::uuid()->toString());
    $tx = app(TenantTransaction::class);
    $plain = $signed = [];

    // Option A baseline (D-055 measures B against A): same transaction with a transaction-local plain setting
    $optionA = fn () => DB::transaction(function () use ($context) {
        DB::selectOne("select set_config('app.tenant_id', ?, true)", [$context->tenantId]);

        return DB::select('select 1');
    });

    for ($i = 0; $i < 20; $i++) { // warm-up
        $optionA();
        $tx->run($context, fn (EoraPostgresConnection $db) => $db->select('select 1'));
    }
    for ($i = 0; $i < 300; $i++) {
        $t = hrtime(true);
        $optionA();
        $plain[] = (hrtime(true) - $t) / 1e6;
        $t = hrtime(true);
        $tx->run($context, fn (EoraPostgresConnection $db) => $db->select('select 1'));
        $signed[] = (hrtime(true) - $t) / 1e6;
    }
    sort($plain);
    sort($signed);
    $p95 = fn (array $xs) => $xs[(int) floor(0.95 * (count($xs) - 1))];

    expect($p95($signed) - $p95($plain))->toBeLessThanOrEqual(0.3);
})->group('integration', 'perf');
