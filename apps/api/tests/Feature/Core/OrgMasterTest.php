<?php

declare(strict_types=1);

use App\Platform\Database\EoraPostgresConnection;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Tests\Support\AccessFixtures;
use Tests\Support\TenantFixtures;

/*
 * M2 step 1 (doc 29 §1.1, D-072): organisation master — companies, plants, warehouses, locations — through the HTTP
 * API as real users (group B), scope and isolation under forced RLS, the query resource grammar (doc 24 §3) and the
 * master-data permission catalogue (M2-D1).
 */

beforeEach(function () {
    $this->t = TenantFixtures::provision(2);
    AccessFixtures::roles($this->t);
});

it('maps the master-data permissions to the templates (M2-D1); approve is sensitive', function () {
    $rows = DB::connection('pgsql')->select(
        "select tp.template_code as t, tp.permission_code as p from role_template_permissions tp where tp.permission_code like 'master.%' order by 1, 2",
    );
    $by = [];
    foreach ($rows as $r) {
        $by[$r->t][] = $r->p;
    }
    expect($by['CSA'])->toContain('master.org.create', 'master.routing.approve', 'master.conversion.approve')
        ->and($by['PPC'])->toContain('master.part.create', 'master.routing.submit')->not->toContain('master.routing.approve', 'master.org.create')
        ->and($by['QCS'])->toContain('master.conversion.approve')->not->toContain('master.conversion.create')
        ->and($by['AUD'])->toBe(['master.conversion.view', 'master.org.view', 'master.part.view', 'master.routing.view'])
        ->and($by)->not->toHaveKeys(['TENANT_ADMIN', 'OPR', 'MAD', 'APR']);

    $sensitive = array_column(DB::connection('pgsql')->select("select code from permissions where code like 'master.%' and sensitive order by code"), 'code');
    expect($sensitive)->toBe(['master.conversion.approve', 'master.routing.approve']);
})->group('integration', 'master');

it('creates every plant with its six virtual locations, which cannot be changed', function () {
    $count = AccessFixtures::run($this->t, fn (EoraPostgresConnection $db) => $db->select(
        "select plant_id, count(*) as n from locations where location_type like 'V\\_%' group by plant_id",
    ));
    expect($count)->toHaveCount(2)->and(array_map(static fn ($r) => (int) $r->n, $count))->toBe([6, 6]);

    orgUser($this, $this->t, 'CSA');
    $virtual = spa($this)->getJson('/api/v1/query/core.locations?filter[virtual]=true&filter[plantId]='.$this->t['plants'][0].'&sort=code')
        ->assertOk()->assertJsonPath('meta.total', 6)->json('data');
    expect(array_column($virtual, 'code'))->toBe(['V_ADJUSTMENT', 'V_CONSUMED', 'V_CUSTOMER', 'V_OPENING', 'V_PRODUCED', 'V_SUPPLIER']);

    patchWith($this, '/api/v1/core/locations/'.$virtual[0]['id'], ['status' => 'INACTIVE'], $virtual[0]['version'])
        ->assertStatus(409)->assertJsonPath('code', 'INVALID_STATE_TRANSITION');
    // the database refuses it as well (no path around the API rule)
    expect(fn () => AccessFixtures::run($this->t, fn (EoraPostgresConnection $db) => $db->update(
        "update locations set status = 'INACTIVE' where id = ?", [$virtual[0]['id']],
    )))->toThrow(QueryException::class);
})->group('integration', 'master');

it('lets a company administrator build plant → warehouse → location, audited, listed through the query resources', function () {
    orgUser($this, $this->t, 'CSA');

    $plant = spa($this)->postJson('/api/v1/core/plants', ['companyId' => $this->t['company'], 'code' => 'P9', 'name' => 'Plant 9'])
        ->assertCreated()->assertHeader('ETag', '"1"')
        ->assertJsonPath('data.code', 'P9')->assertJsonPath('data.timezone', 'Asia/Jakarta')->assertJsonPath('data.companyCode', 'CO1')->json('data');
    spa($this)->getJson('/api/v1/query/core.locations?filter[plantId]='.$plant['id'])->assertOk()->assertJsonPath('meta.total', 6);

    $wh = spa($this)->postJson('/api/v1/core/warehouses', ['plantId' => $plant['id'], 'code' => 'RM1', 'name' => 'Raw material', 'warehouseType' => 'RAW_MATERIAL'])
        ->assertCreated()->assertJsonPath('data.plantCode', 'P9')->json('data');
    spa($this)->postJson('/api/v1/core/locations', ['warehouseId' => $wh['id'], 'code' => 'RM1-A01', 'name' => 'Rack A01', 'locationType' => 'RM_WAREHOUSE'])
        ->assertCreated()->assertJsonPath('data.warehouseCode', 'RM1')->assertJsonPath('data.virtual', false);

    // duplicates, reserved codes, wrong types
    spa($this)->postJson('/api/v1/core/warehouses', ['plantId' => $plant['id'], 'code' => 'RM1', 'name' => 'Again', 'warehouseType' => 'GENERAL'])
        ->assertStatus(422)->assertJsonPath('errors.code.0', 'A warehouse with this code already exists in this plant.');
    spa($this)->postJson('/api/v1/core/locations', ['warehouseId' => $wh['id'], 'code' => 'V_X', 'name' => 'x', 'locationType' => 'STAGING'])
        ->assertStatus(422)->assertJsonValidationErrors(['code']);
    spa($this)->postJson('/api/v1/core/locations', ['warehouseId' => $wh['id'], 'code' => 'MAP', 'name' => 'x', 'locationType' => 'MAT_AT_PROD'])
        ->assertStatus(422)->assertJsonValidationErrors(['locationType']);

    // the list: search, filter, sort, paging
    $list = spa($this)->getJson('/api/v1/query/core.warehouses?q=rm&filter[warehouseType]=RAW_MATERIAL')->assertOk();
    expect($list->json('meta.total'))->toBe(1)->and($list->json('data.0.locationCount'))->toBe(1)
        ->and($list->json('meta.timezone'))->toBe('Asia/Jakarta')->and($list->json('meta.page'))->toBe(['number' => 1, 'size' => 50]);

    $actions = array_column(AccessFixtures::run($this->t, fn (EoraPostgresConnection $db) => $db->select(
        "select action from audit_events where action in ('plant.created', 'warehouse.created', 'location.created') order by occurred_at, id",
    )), 'action');
    expect($actions)->toBe(['plant.created', 'warehouse.created', 'location.created']);
})->group('integration', 'master');

it('edits with If-Match versions: missing → 428, stale → 412, current → 200 with the next ETag', function () {
    orgUser($this, $this->t, 'CSA');
    $wh = spa($this)->postJson('/api/v1/core/warehouses', ['plantId' => $this->t['plants'][0], 'code' => 'FG1', 'name' => 'FG', 'warehouseType' => 'FINISHED_GOODS'])->json('data');
    $uri = '/api/v1/core/warehouses/'.$wh['id'];

    patchWith($this, $uri, ['name' => 'Finished goods'], null)->assertStatus(428)->assertJsonPath('code', 'PRECONDITION_REQUIRED');
    patchWith($this, $uri, ['name' => 'Finished goods'], 1)->assertOk()->assertHeader('ETag', '"2"')->assertJsonPath('data.name', 'Finished goods');
    patchWith($this, $uri, ['status' => 'INACTIVE'], 1)->assertStatus(412)->assertJsonPath('code', 'VERSION_CONFLICT');
    patchWith($this, $uri, ['status' => 'INACTIVE'], 2)->assertOk()->assertJsonPath('data.status', 'INACTIVE');

    // an inactive warehouse takes no new locations
    spa($this)->postJson('/api/v1/core/locations', ['warehouseId' => $wh['id'], 'code' => 'FG1-01', 'name' => 'x', 'locationType' => 'FG_WAREHOUSE'])
        ->assertStatus(422)->assertJsonPath('errors.warehouseId.0', 'This warehouse is inactive.');

    $update = AccessFixtures::run($this->t, fn (EoraPostgresConnection $db) => $db->selectOne(
        "select count(*) as n from audit_events where action = 'warehouse.updated' and entity_id = ?", [$wh['id']],
    ));
    expect((int) $update->n)->toBe(2);
})->group('integration', 'master');

it('keeps plant-scoped users inside their plant, and users without the permission out', function () {
    $csa = member($this, $this->t);
    AccessFixtures::grant($this->t, $csa['id'], 'CSA');
    signIn($this, $csa, $this->t);
    foreach ($this->t['plants'] as $i => $plant) {
        spa($this)->postJson('/api/v1/core/warehouses', ['plantId' => $plant, 'code' => "W{$i}", 'name' => "W{$i}", 'warehouseType' => 'GENERAL'])->assertCreated();
    }
    $second = spa($this)->postJson('/api/v1/core/companies', ['code' => 'CO2', 'legalName' => 'Second company'])->assertCreated()->json('data.id');
    spa($this)->postJson('/api/v1/core/plants', ['companyId' => $second, 'code' => 'Q1', 'name' => 'Other company plant'])->assertCreated();
    spa($this)->getJson('/api/v1/query/core.companies')->assertOk()->assertJsonPath('meta.total', 2);
    spa($this)->postJson('/api/v1/auth/logout')->assertNoContent();

    // MAT holds master.org.view in plant 1 only
    orgUser($this, $this->t, 'MAT', ['PLANT', $this->t['plants'][0]]);
    spa($this)->getJson('/api/v1/query/core.warehouses')->assertOk()->assertJsonPath('meta.total', 1)->assertJsonPath('data.0.code', 'W0');
    spa($this)->getJson('/api/v1/query/core.plants')->assertOk()->assertJsonPath('meta.total', 1);
    // a plant scope reaches its own company only, never the tenant's other companies
    spa($this)->getJson('/api/v1/query/core.companies')->assertOk()->assertJsonPath('meta.total', 1)->assertJsonPath('data.0.code', 'CO1');
    spa($this)->postJson('/api/v1/core/warehouses', ['plantId' => $this->t['plants'][0], 'code' => 'X', 'name' => 'X', 'warehouseType' => 'GENERAL'])
        ->assertForbidden()->assertJsonPath('code', 'FORBIDDEN'); // view only
    spa($this)->postJson('/api/v1/auth/logout')->assertNoContent();

    // TENANT_ADMIN administers users, not master data
    orgUser($this, $this->t, 'TENANT_ADMIN');
    spa($this)->getJson('/api/v1/query/core.warehouses')->assertForbidden()->assertJsonPath('code', 'FORBIDDEN');
    spa($this)->getJson('/api/v1/query/core.warehouses/schema')->assertForbidden();
})->group('integration', 'master');

it('refuses a plant-scoped writer outside its plant (SCOPE_FORBIDDEN) and hides other tenants (404 / unknown)', function () {
    // a CSA copy bound to plant 1 (template roles keep the template permissions; the scope narrows them)
    orgUser($this, $this->t, 'CSA', ['PLANT', $this->t['plants'][0]]);
    spa($this)->postJson('/api/v1/core/warehouses', ['plantId' => $this->t['plants'][1], 'code' => 'X', 'name' => 'X', 'warehouseType' => 'GENERAL'])
        ->assertForbidden()->assertJsonPath('code', 'SCOPE_FORBIDDEN');
    spa($this)->postJson('/api/v1/core/companies', ['code' => 'CO2', 'legalName' => 'Second'])->assertForbidden()->assertJsonPath('code', 'SCOPE_FORBIDDEN');

    $other = TenantFixtures::provision(1);
    AccessFixtures::roles($other);
    $foreign = AccessFixtures::run($other, fn (EoraPostgresConnection $db) => (string) $db->selectOne(
        "select id from locations where location_type = 'V_OPENING'",
    )->id);
    patchWith($this, '/api/v1/core/locations/'.$foreign, ['name' => 'x'], 1)->assertNotFound()->assertJsonPath('code', 'NOT_FOUND');
    spa($this)->postJson('/api/v1/core/warehouses', ['plantId' => $other['plants'][0], 'code' => 'X', 'name' => 'X', 'warehouseType' => 'GENERAL'])
        ->assertStatus(422)->assertJsonPath('errors.plantId.0', 'Unknown plant.');
    spa($this)->getJson('/api/v1/query/core.locations?filter[id]='.$foreign)->assertOk()->assertJsonPath('meta.total', 0);
})->group('integration', 'master');

it('applies the query grammar strictly (doc 24 §3.1)', function () {
    orgUser($this, $this->t, 'CSA');

    spa($this)->getJson('/api/v1/query/core.nothing')->assertNotFound();
    spa($this)->getJson('/api/v1/query/core.locations?filter[name]=x')->assertStatus(422)->assertJsonPath('code', 'FILTER_NOT_ALLOWED');
    spa($this)->getJson('/api/v1/query/core.locations?filter[status]=GONE')->assertStatus(422)->assertJsonValidationErrors(['filter.status']);
    spa($this)->getJson('/api/v1/query/core.locations?sort=-version')->assertStatus(422)->assertJsonValidationErrors(['sort']);
    spa($this)->getJson('/api/v1/query/core.locations?page[size]=5000')->assertOk()->assertJsonPath('meta.page.size', 200);
    spa($this)->getJson('/api/v1/query/core.locations?page[size]=5&page[number]=3&sort=-code')->assertOk()
        ->assertJsonPath('meta.total', 12)->assertJsonCount(2, 'data');
    // wildcards in q are literal
    spa($this)->getJson('/api/v1/query/core.locations?q=%25')->assertOk()->assertJsonPath('meta.total', 0);

    $schema = spa($this)->getJson('/api/v1/query/core.locations/schema')->assertOk()->json('data');
    expect($schema['defaultSort'])->toBe(['plantCode', 'code'])
        ->and(collect($schema['fields'])->firstWhere('key', 'locationType')['options'])->toContain('MAT_AT_PROD', 'V_OPENING');
})->group('integration', 'master');
