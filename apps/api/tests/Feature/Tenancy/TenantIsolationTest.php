<?php

declare(strict_types=1);

use App\Platform\Database\EoraPostgresConnection;
use App\Platform\Tenancy\TenantContext;
use App\Platform\Tenancy\TenantTransaction;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Support\TenantFixtures;

/*
 * Group C isolation tests (ADR-0001 T-ISO-02/02b, doc 25 §11) — runtime role eora_app, signed context, raw SQL.
 */

function inTenant(array $t, Closure $work): mixed
{
    return app(TenantTransaction::class)->run(TenantContext::forTenant($t['tenant'], $t['actor']), $work);
}

beforeEach(function () {
    $this->a = TenantFixtures::provision();
    $this->b = TenantFixtures::provision();
});

it('T-ISO-02: shows a tenant only its own tenant row, companies and plants', function () {
    $seen = inTenant($this->a, fn (EoraPostgresConnection $db) => [
        'tenants' => array_column($db->select('select id from tenants'), 'id'),
        'plant_tenants' => array_values(array_unique(array_column($db->select('select tenant_id from plants'), 'tenant_id'))),
        'plants' => count($db->select('select id from plants')),
        'b_by_id' => count($db->select('select id from plants where id = ?', [$this->b['plants'][0]])),
    ]);

    expect($seen['tenants'])->toBe([$this->a['tenant']])
        ->and($seen['plant_tenants'])->toBe([$this->a['tenant']])
        ->and($seen['plants'])->toBe(2)
        ->and($seen['b_by_id'])->toBe(0);
})->group('integration', 'isolation');

it('T-ISO-02: cannot update, or insert into, another tenant', function () {
    $updated = inTenant($this->a, fn (EoraPostgresConnection $db) => $db->update(
        "update plants set name = 'hijacked' where id = ?", [$this->b['plants'][0]],
    ));
    expect($updated)->toBe(0);

    expect(fn () => inTenant($this->a, fn (EoraPostgresConnection $db) => $db->insert(
        'insert into plants (tenant_id, company_id, code, name) values (?, ?, ?, ?)',
        [$this->b['tenant'], $this->b['company'], 'EVIL', 'evil'],
    )))->toThrow(QueryException::class, 'row-level security');
})->group('integration', 'isolation');

it('T-ISO-02: composite foreign keys reject pointing at another tenant\'s company', function () {
    expect(fn () => inTenant($this->a, fn (EoraPostgresConnection $db) => $db->insert(
        'insert into plants (tenant_id, company_id, code, name) values (?, ?, ?, ?)',
        [$this->a['tenant'], $this->b['company'], 'XFK', 'cross tenant fk'],
    )))->toThrow(QueryException::class, 'foreign key');
})->group('integration', 'isolation');

it('T-ISO-02b: unset, reset, malformed or legacy GUC context sees nothing and raises nothing', function () {
    $db = DB::connection();

    expect(count($db->select('select id from plants')))->toBe(0); // autocommit, never any context

    $counts = $db->transaction(function () use ($db): array {
        $out = ['none' => count($db->select('select id from plants'))];
        $db->installSignedContext('');
        $out['empty'] = count($db->select('select id from plants'));
        $db->installSignedContext('not-a-token');
        $out['malformed'] = count($db->select('select id from plants'));
        // the pre-Amendment-2 setting is ignored by every policy (forging it is the R-17 attack)
        $db->selectOne("select set_config('app.tenant_id', ?, true)", [$this->a['tenant']]);
        $out['legacy_guc'] = count($db->select('select id from plants'));

        return $out;
    });

    expect($counts)->toBe(['none' => 0, 'empty' => 0, 'malformed' => 0, 'legacy_guc' => 0]);
})->group('integration', 'isolation');

it('T-ISO-02b: a context does not survive its transaction', function () {
    inTenant($this->a, fn (EoraPostgresConnection $db) => $db->select('select 1'));

    expect(count(DB::select('select id from plants')))->toBe(0)
        ->and(DB::selectOne("select coalesce(current_setting('app.ctx', true), '') as v")->v)->toBe('');
})->group('integration', 'isolation');

it('refuses to nest a tenant transaction inside another transaction', function () {
    DB::transaction(fn () => inTenant($this->a, fn () => null));
})->throws(LogicException::class, 'outermost')->group('integration', 'isolation');

it('lets the provisioner create a tenant only in PROVISIONING and change it only in its own context', function () {
    $db = DB::connection('pgsql_provisioner');
    $id = Str::uuid7()->toString();

    // without a signed context the audit trigger refuses; with one, RLS refuses a non-PROVISIONING status
    expect(fn () => $db->insert("insert into tenants (id, code, name, status) values (?, ?, ?, 'ACTIVE')", [$id, 'x-'.substr($id, -8), 'x']))
        ->toThrow(QueryException::class, 'SQLSTATE[42501]');
    expect(fn () => app(TenantTransaction::class)->run(
        TenantContext::forTenant($id, $this->a['actor']),
        fn (EoraPostgresConnection $c) => $c->insert("insert into tenants (id, code, name, status) values (?, ?, ?, 'ACTIVE')", [$id, 'y-'.substr($id, -8), 'y']),
        'pgsql_provisioner',
    ))->toThrow(QueryException::class, 'row-level security');

    $changed = app(TenantTransaction::class)->run(
        TenantContext::forTenant($this->a['tenant'], $this->a['actor']),
        fn (EoraPostgresConnection $c) => $c->update("update tenants set status = 'SUSPENDED' where id = ?", [$this->b['tenant']]),
        'pgsql_provisioner',
    );
    expect($changed)->toBe(0);
})->group('integration', 'isolation');

it('denies the runtime role any DELETE and any tenant creation', function (string $sql) {
    inTenant($this->a, fn (EoraPostgresConnection $db) => $db->statement($sql, [$this->a['plants'][0]]));
})->with([
    'delete plant' => 'delete from plants where id = ?',
    'create tenant' => "insert into tenants (id, code, name) values (?, 'zz-evil', 'evil')",
])->throws(QueryException::class, 'permission denied')->group('integration', 'isolation');
