<?php

declare(strict_types=1);

use App\Platform\Database\EoraPostgresConnection;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Tests\Support\AccessFixtures;
use Tests\Support\TenantFixtures;

/*
 * M2 step 7a (doc 29 §1.7 and §4 item 5, D-093): big master lists — thousands of items, routings and BOM versions in
 * one tenant next to another tenant of the same size — page, filter, sort and search through indexes under forced
 * RLS. Plans are read from the very requests the screens send (EXPLAIN of the executed SQL, same connection, same
 * signed context). Name searches filter the tenant's rows by design (D-093); only code paths are index-checked.
 */

/** @param  array{wc: string, process: string}|null  $engineering */
function lpSeed(array $t, ?array $engineering): void
{
    AccessFixtures::run($t, function (EoraPostgresConnection $db) use ($engineering) {
        $db->statement("
            insert into items (tenant_id, code, name, item_type, base_uom_id, drawing_no, created_at, created_by, updated_at, updated_by, version)
            select c.tenant, 'P'||lpad(g::text, 6, '0'), (array['Bracket','Plate','Nut','Coil','Bolt'])[1 + g % 5]||' part '||g,
                   (array['RAW','FINISHED','SEMI_FINISHED'])[1 + g % 3], (select id from uoms where code = 'PCS'), case when g % 4 = 0 then 'DWG-'||g end,
                   now(), c.actor, now(), c.actor, 1
              from ctx_api.context() c, generate_series(1, 10000) g");
        if ($engineering === null) {
            return;
        }
        $db->statement("
            insert into routings (tenant_id, plant_id, item_id, routing_code, routing_kind, created_at, created_by, updated_at, updated_by, version)
            select i.tenant_id, (select id from plants order by code limit 1), i.id, 'R1', 'PRIMARY', now(), c.actor, now(), c.actor, 1
              from items i, ctx_api.context() c where i.item_type <> 'RAW' and i.code like 'P%'");
        $db->statement('
            insert into routing_versions (tenant_id, plant_id, routing_id, version_no, effective_from, prepared_by, created_at, created_by, updated_at, updated_by, version)
            select r.tenant_id, r.plant_id, r.id, 1, current_date, c.actor, now(), c.actor, now(), c.actor, 1 from routings r, ctx_api.context() c');
        $db->statement("
            insert into routing_operations (tenant_id, plant_id, routing_version_id, op_seq, process_type_id, work_center_id, std_cycle_seconds, cycle_basis,
                                            created_at, created_by, updated_at, updated_by, version)
            select v.tenant_id, v.plant_id, v.id, s * 10, ?::uuid, ?::uuid, 4, 'PER_PIECE', now(), c.actor, now(), c.actor, 1
              from routing_versions v, ctx_api.context() c, generate_series(1, 2) s", [$engineering['process'], $engineering['wc']]);
        $db->statement("
            insert into bom_versions (tenant_id, item_id, version_no, effective_from, prepared_by, created_at, created_by, updated_at, updated_by, version)
            select i.tenant_id, i.id, 1, current_date, c.actor, now(), c.actor, now(), c.actor, 1
              from items i, ctx_api.context() c where i.item_type <> 'RAW' and i.code like 'P%'");
    });
}

/**
 * The list request's executed SQL (count and page), each explained in place.
 *
 * @return array{count: string, page: string, data: list<array<string, mixed>>}
 */
function lpPlans(object $test, string $url): array
{
    $plans = [];
    $listener = function (QueryExecuted $e) use (&$plans) {
        if (! str_starts_with($e->sql, 'select count(*)') && ! str_starts_with($e->sql, 'with page') && ! preg_match('/^select \w+\.id as "id"/', $e->sql)) {
            return;
        }
        $kind = str_starts_with($e->sql, 'select count(*)') ? 'count' : 'page';
        $plans[$kind] ??= implode("\n", array_column($e->connection->select('explain (analyze, costs off, timing off) '.$e->sql, $e->bindings), 'QUERY PLAN'));
    };
    Event::listen(QueryExecuted::class, $listener);
    $data = spa($test)->getJson('/api/v1/query/'.$url)->assertOk()->json('data');
    Event::forget(QueryExecuted::class);

    return ['count' => $plans['count'] ?? '', 'page' => $plans['page'] ?? '', 'data' => $data];
}

it('pages, filters, sorts and searches big master lists through their indexes under RLS', function () {
    $t = TenantFixtures::provision(1);
    AccessFixtures::roles($t);
    orgUser($this, $t, 'CSA');
    $s = rtSetup($this, $t);
    lpSeed($t, ['wc' => $s['wc']['id'], 'process' => $s['blank']['id']]);
    lpSeed(TenantFixtures::provision(1), null);
    // fresh statistics for every table the plans join (other tests leave rows of their own tenants behind)
    foreach (['plants', 'uoms', 'items', 'routings', 'routing_versions', 'routing_operations', 'bom_versions'] as $table) {
        DB::connection('pgsql_owner')->statement("analyze {$table}");
    }

    // items: the page in code order, a type filter, the name sort
    $list = lpPlans($this, 'core.items');
    expect($list['page'])->toContain('items_tenant_id_code_key')->and($list['data'][0]['code'])->toBe('BRK-100');
    expect(lpPlans($this, 'core.items?filter[itemType]=FINISHED')['count'])->toContain('items_type_idx');
    $byName = lpPlans($this, 'core.items?sort=name');
    expect($byName['page'])->toContain('items_name_idx')->and($byName['data'][0]['name'])->toBe('Bolt part 1004');

    // routings and BOMs by item code: the item code prefix (^@ on text_pattern_ops) finds the items, the rows come
    // through an index of their own — and counting needs no version laterals (the versions are read once, by the
    // search through an operation's process, never once per routing)
    $routings = lpPlans($this, 'planning.routings?q=p00012');
    // (which routings index the planner prefers depends on the statistics; a scan of every routing is never acceptable)
    expect($routings['count'])->toContain('items_code_prefix_idx')->toMatch('/(Index|Index Only|Bitmap Index) Scan (using|on) routings_/')
        ->not->toContain('Seq Scan on routings')->not->toMatch('/on routing_versions \w+ \(actual rows=[\d.]+ loops=([2-9]|\d{2,})/');
    // an operation's process (B2-AT-12): matching process types lead to their operations through their own index
    expect(lpPlans($this, 'planning.routings?q=blank')['count'])->toContain('routing_operations_process_idx')->not->toContain('Seq Scan on routing_operations');
    expect(array_column($routings['data'], 'itemCode'))->toBe(['P000121', 'P000122', 'P000124', 'P000125', 'P000127', 'P000128']);
    $boms = lpPlans($this, 'planning.bom_versions?q=P00012');
    expect($boms['count'])->toContain('items_code_prefix_idx')->toMatch('/(Index|Index Only|Bitmap Index) Scan (using|on) bom_versions_/')
        ->not->toContain('Seq Scan on bom_versions')->and($boms['data'])->toHaveCount(6);

    // the routing page reads the version in use and the open version for its own rows only
    $page = lpPlans($this, 'planning.routings?page[size]=50');
    expect($page['data'])->toHaveCount(50)->and($page['count'])->not->toContain('routing_versions');
    preg_match_all('/on routing_versions \w+.*?loops=(\d+)/', $page['page'], $loops);
    expect($loops[1])->not->toBeEmpty()->and(max(array_map('intval', $loops[1])))->toBeLessThanOrEqual(50);

    // search semantics: codes by prefix in any case, names and drawing numbers by contains, wildcards literal
    expect(array_column(lpPlans($this, 'core.items?q=p00999')['data'], 'code'))
        ->toBe(['P009990', 'P009991', 'P009992', 'P009993', 'P009994', 'P009995', 'P009996', 'P009997', 'P009998', 'P009999']);
    expect(array_column(lpPlans($this, 'core.items?q=dwg-999')['data'], 'code'))->toBe(['P009992', 'P009996']);
    expect(lpPlans($this, 'core.items?q=P%')['data'])->toBe([]);
    expect(array_column(lpPlans($this, 'planning.routings?q=bracket part 9995')['data'], 'itemCode'))->toBe(['P009995']);
})->group('integration', 'query');
