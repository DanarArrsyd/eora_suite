<?php

declare(strict_types=1);

use App\Platform\Database\EoraPostgresConnection;
use App\Platform\Tenancy\ContextSigner;
use App\Platform\Tenancy\TenantContext;
use App\Platform\Tenancy\TenantTransaction;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Tests\Support\TenantFixtures;

/*
 * T-CTX suite (doc 25 §5.5, D-055): the PoC-CTX tests ported to the application, run as eora_app.
 */

beforeEach(function () {
    $this->a = TenantFixtures::provision();
    $this->b = TenantFixtures::provision();
});

/** Opens a transaction, installs the given token and returns the number of plants visible. */
function plantsWithToken(Closure $token): int
{
    /** @var EoraPostgresConnection $db */
    $db = DB::connection();

    return $db->transaction(function () use ($db, $token): int {
        $binding = $db->transactionBinding();
        $db->installSignedContext($token($binding['pid'], $binding['xact_us']));

        return count($db->select('select id from plants'));
    });
}

it('PoC-CTX-01: rejects forged, swapped and wrongly keyed tokens', function () {
    $signer = app(ContextSigner::class);
    $foreign = new ContextSigner('kid1', bin2hex(random_bytes(32)), 300);
    $a = TenantContext::forTenant($this->a['tenant'], $this->a['actor']);
    $b = TenantContext::forTenant($this->b['tenant'], $this->b['actor']);

    $variants = [
        'valid own token' => fn (int $pid, string $x) => $signer->sign($a, $pid, $x),
        'random' => fn () => 'v1.kid1.'.$this->b['tenant'].'.'.$this->b['actor'].'.1.1.9999999999.'.str_repeat('ab', 32),
        'tenant swapped, original signature' => function (int $pid, string $x) use ($signer, $a) {
            $parts = explode('.', $signer->sign($a, $pid, $x));
            $parts[2] = $this->b['tenant'];

            return implode('.', $parts);
        },
        'signed with another key' => fn (int $pid, string $x) => $foreign->sign($b, $pid, $x),
    ];
    $seen = array_map(fn (Closure $t) => plantsWithToken($t), $variants);

    expect($seen)->toBe(['valid own token' => 2, 'random' => 0, 'tenant swapped, original signature' => 0, 'signed with another key' => 0]);
})->group('integration', 'ctx');

it('PoC-CTX-01: a forged context cannot insert into another tenant', function () {
    $signer = app(ContextSigner::class);
    $db = DB::connection();

    expect(fn () => $db->transaction(function () use ($db, $signer) {
        $binding = $db->transactionBinding();
        $parts = explode('.', $signer->sign(TenantContext::forTenant($this->a['tenant'], $this->a['actor']), $binding['pid'], $binding['xact_us']));
        $parts[2] = $this->b['tenant'];
        $db->installSignedContext(implode('.', $parts));
        $db->insert('insert into plants (tenant_id, company_id, code, name) values (?, ?, ?, ?)', [$this->b['tenant'], $this->b['company'], 'FRG', 'forged']);
    }))->toThrow(QueryException::class, 'row-level security');
})->group('integration', 'ctx');

it('PoC-CTX-02: a token replayed in a later transaction sees nothing', function () {
    $signer = app(ContextSigner::class);
    $captured = null;
    $first = plantsWithToken(function (int $pid, string $x) use ($signer, &$captured) {
        return $captured = $signer->sign(TenantContext::forTenant($this->a['tenant'], $this->a['actor']), $pid, $x);
    });
    $replayed = plantsWithToken(fn () => $captured);

    expect($first)->toBe(2)->and($replayed)->toBe(0);
})->group('integration', 'ctx');

it('PoC-CTX-06: expired, unknown-kid, truncated and uppercase-signature tokens see nothing', function () {
    $a = TenantContext::forTenant($this->a['tenant'], $this->a['actor']);
    $expired = new ContextSigner('kid1', (string) config('eora.context.key'), -1);
    $unknownKid = new ContextSigner('kid9', (string) config('eora.context.key'), 300);
    $signer = app(ContextSigner::class);

    $seen = [
        'expired' => plantsWithToken(fn (int $p, string $x) => $expired->sign($a, $p, $x)),
        'unknown kid' => plantsWithToken(fn (int $p, string $x) => $unknownKid->sign($a, $p, $x)),
        'truncated' => plantsWithToken(fn (int $p, string $x) => substr($signer->sign($a, $p, $x), 0, -2)),
        'uppercase signature' => plantsWithToken(function (int $p, string $x) use ($signer, $a) {
            $t = $signer->sign($a, $p, $x);

            return substr($t, 0, -64).strtoupper(substr($t, -64));
        }),
        'wrong pid' => plantsWithToken(fn (int $p, string $x) => $signer->sign($a, $p + 1, $x)),
        'wrong transaction start' => plantsWithToken(fn (int $p, string $x) => $signer->sign($a, $p, (string) ((int) $x + 1))),
    ];

    expect(array_sum($seen))->toBe(0);
})->group('integration', 'ctx');

it('gives a tenant-less (picker) context an actor but no tenant rows', function () {
    $row = app(TenantTransaction::class)->run(TenantContext::tenantless($this->a['actor']), fn (EoraPostgresConnection $db) => [
        'context' => $db->selectOne('select c.tenant, c.actor from ctx_api.context() c'),
        'plants' => count($db->select('select id from plants')),
        'tenants' => count($db->select('select id from tenants')),
    ]);

    expect($row['context']->tenant)->toBeNull()
        ->and($row['context']->actor)->toBe($this->a['actor'])
        ->and($row['plants'])->toBe(0)
        ->and($row['tenants'])->toBe(0);
})->group('integration', 'ctx');

it('PoC-CTX-04: the runtime role cannot reach keys, crypto, verifier internals or other roles', function (string $sql) {
    DB::statement($sql);
})->with([
    'read ctx.keys' => 'select count(*) from ctx.keys',
    'call ext.hmac' => "select ext.hmac('a', 'b', 'sha256')",
    'replace the verifier' => 'create or replace function ctx_api.context(out tenant uuid, out actor uuid) language sql as $$ select null::uuid, null::uuid $$',
    'set role to the verifier' => 'set role eora_ctx_verifier',
    'disable row security' => 'alter table plants disable row level security',
])->throws(QueryException::class)->group('integration', 'ctx');

it('PoC-CTX-05/05b: verifier is PARALLEL RESTRICTED and forced parallel plans keep the full tenant view', function () {
    $proparallel = DB::connection('pgsql_owner')->selectOne("select proparallel from pg_proc where oid = 'ctx_api.context()'::regprocedure")->proparallel;

    $counts = app(TenantTransaction::class)->run(TenantContext::forTenant($this->a['tenant'], $this->a['actor']), function (EoraPostgresConnection $db): array {
        $serial = (int) $db->selectOne('select count(*) as n from plants')->n;
        foreach (['debug_parallel_query = on', 'parallel_setup_cost = 0', 'parallel_tuple_cost = 0', 'min_parallel_table_scan_size = 0'] as $setting) {
            $db->statement("set local {$setting}");
        }

        return ['serial' => $serial, 'parallel' => (int) $db->selectOne('select count(*) as n from plants')->n];
    });

    expect($proparallel)->toBe('r')->and($counts['parallel'])->toBe($counts['serial'])->and($counts['serial'])->toBe(2);
})->group('integration', 'ctx');

it('PoC-CTX-10: evaluates the verifier once per table reference (InitPlan), never per row', function () {
    $plan = app(TenantTransaction::class)->run(
        TenantContext::forTenant($this->a['tenant'], $this->a['actor']),
        fn (EoraPostgresConnection $db) => implode("\n", array_column($db->select('explain (analyze, costs off, timing off, summary off) select * from plants'), 'QUERY PLAN')),
    );

    expect($plan)->toContain('InitPlan')->toMatch('/Function Scan on context c \(actual rows=1(\.00)? loops=1\)/');
})->group('integration', 'ctx');

it('PoC-CTX-03: the token never reaches Laravel query events or the query log', function () {
    $bindings = [];
    Event::listen(QueryExecuted::class, function (QueryExecuted $e) use (&$bindings) {
        $bindings[] = $e->sql.' '.json_encode($e->bindings);
    });
    DB::enableQueryLog();

    app(TenantTransaction::class)->run(TenantContext::forTenant($this->a['tenant'], $this->a['actor']), fn (EoraPostgresConnection $db) => $db->select('select id from plants'));

    $all = implode("\n", [...$bindings, json_encode(DB::getQueryLog())]);
    DB::disableQueryLog();

    expect($all)->not->toContain('v1.kid1')->and($all)->toContain('select id from plants');
})->group('integration', 'ctx');
