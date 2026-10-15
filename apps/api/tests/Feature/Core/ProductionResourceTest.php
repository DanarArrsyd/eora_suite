<?php

declare(strict_types=1);

use App\Platform\Database\EoraPostgresConnection;
use Illuminate\Database\QueryException;
use Tests\Support\AccessFixtures;
use Tests\Support\TenantFixtures;

/*
 * M2 step 1b (doc 29 §1.1, doc 26 §2.1): work centers with their Material at Production location, machines and dies
 * through the HTTP API (group B), scope and isolation under forced RLS.
 */

beforeEach(function () {
    $this->t = TenantFixtures::provision(2);
    AccessFixtures::roles($this->t);
});

/** Creates a work center as the signed-in user and returns its row. */
function workCenter(object $test, string $plant, string $code, array $extra = []): array
{
    return spa($test)->postJson('/api/v1/core/work-centers', ['plantId' => $plant, 'code' => $code, 'name' => "Line {$code}", 'workCenterType' => 'PRESS'] + $extra)
        ->assertCreated()->json('data');
}

it('gives every work center exactly one Material at Production location, never a WIP label', function () {
    orgUser($this, $this->t, 'CSA');
    $wc = workCenter($this, $this->t['plants'][0], 'PRS-01', ['availableHoursPerShift' => 7.5]);
    expect($wc['matAtProdLocationCode'])->toBe('MAP-PRS-01')->and($wc['availableHoursPerShift'])->toBe('7.50')
        ->and($wc['machineCount'])->toBe(0)->and($wc['plantCode'])->toBe('P1');

    $loc = spa($this)->getJson('/api/v1/query/core.locations?filter[workCenterId]='.$wc['id'])->assertOk()->assertJsonPath('meta.total', 1)->json('data.0');
    expect($loc['locationType'])->toBe('MAT_AT_PROD')->and($loc['name'])->toBe('Material at Production — Line PRS-01')
        ->and($loc['warehouseId'])->toBeNull()->and($loc['workCenterCode'])->toBe('PRS-01')
        ->and(strtoupper($loc['name']))->not->toContain('WIP');

    // a second MAT_AT_PROD location for the same work center is impossible, also in direct SQL
    expect(fn () => AccessFixtures::run($this->t, fn (EoraPostgresConnection $db) => $db->insert(
        "insert into locations (tenant_id, plant_id, work_center_id, code, name, location_type) values (?, ?, ?, 'MAP-X', 'x', 'MAT_AT_PROD')",
        [$this->t['tenant'], $this->t['plants'][0], $wc['id']],
    )))->toThrow(QueryException::class);

    // capacity is optional (NULL = not configured) and can be cleared again
    $cleared = patchWith($this, '/api/v1/core/work-centers/'.$wc['id'], ['availableHoursPerShift' => null], 1)->assertOk()->json('data');
    expect($cleared['availableHoursPerShift'])->toBeNull();
    spa($this)->postJson('/api/v1/core/work-centers', ['plantId' => $this->t['plants'][0], 'code' => 'TOO-LONG-CODE-123', 'name' => 'x', 'workCenterType' => 'PRESS'])
        ->assertStatus(422)->assertJsonValidationErrors(['code']);
    spa($this)->postJson('/api/v1/core/work-centers', ['plantId' => $this->t['plants'][0], 'code' => 'PRS-01', 'name' => 'x', 'workCenterType' => 'PRESS'])
        ->assertStatus(422)->assertJsonPath('errors.code.0', 'A work center with this code already exists in this plant.');
})->group('integration', 'master');

it('keeps machines inside one work center of their plant; moving is an audited update', function () {
    orgUser($this, $this->t, 'CSA');
    [$p1, $p2] = $this->t['plants'];
    $a = workCenter($this, $p1, 'PRS-A');
    $b = workCenter($this, $p1, 'PRS-B');
    $other = workCenter($this, $p2, 'WLD-A');

    $m = spa($this)->postJson('/api/v1/core/machines', ['workCenterId' => $a['id'], 'code' => 'M-200T', 'name' => 'Press 200 t', 'strokeCounter' => true])
        ->assertCreated()->assertJsonPath('data.workCenterCode', 'PRS-A')->assertJsonPath('data.strokeCounter', true)->json('data');
    spa($this)->getJson('/api/v1/query/core.work_centers?filter[id]='.$a['id'])->assertJsonPath('data.0.machineCount', 1);

    patchWith($this, '/api/v1/core/machines/'.$m['id'], ['workCenterId' => $other['id']], 1)
        ->assertStatus(422)->assertJsonPath('errors.workCenterId.0', 'A machine moves only between work centers of its own plant.');
    patchWith($this, '/api/v1/core/machines/'.$m['id'], ['workCenterId' => $b['id']], 1)->assertOk()->assertJsonPath('data.workCenterCode', 'PRS-B');

    // the database refuses a machine whose work center is in another plant
    expect(fn () => AccessFixtures::run($this->t, fn (EoraPostgresConnection $db) => $db->update(
        'update machines set work_center_id = ? where id = ?', [$other['id'], $m['id']],
    )))->toThrow(QueryException::class);

    // inactive work centers take no machines
    patchWith($this, '/api/v1/core/work-centers/'.$a['id'], ['status' => 'INACTIVE'], 1)->assertOk();
    spa($this)->postJson('/api/v1/core/machines', ['workCenterId' => $a['id'], 'code' => 'M-2', 'name' => 'x'])
        ->assertStatus(422)->assertJsonPath('errors.workCenterId.0', 'This work center is inactive.');

    $audit = AccessFixtures::run($this->t, fn (EoraPostgresConnection $db) => $db->selectOne(
        "select count(*) as n from audit_events where entity_type = 'machine' and entity_id = ? and action in ('machine.created', 'machine.updated')", [$m['id']],
    ));
    expect((int) $audit->n)->toBe(2);
})->group('integration', 'master');

it('keeps die codes unique per tenant; a plant move needs the right scope in both plants', function () {
    [$p1, $p2] = $this->t['plants'];
    orgUser($this, $this->t, 'CSA');
    $die = spa($this)->postJson('/api/v1/core/dies', ['plantId' => $p1, 'code' => 'D-100', 'name' => 'Bracket die', 'dieType' => 'DIE', 'cavities' => 2, 'strokeLife' => 500000])
        ->assertCreated()->assertJsonPath('data.strokeLife', '500000')->assertJsonPath('data.currentStrokes', '0')->json('data');
    spa($this)->postJson('/api/v1/core/dies', ['plantId' => $p2, 'code' => 'D-100', 'name' => 'Same code, other plant', 'dieType' => 'DIE'])
        ->assertStatus(422)->assertJsonPath('errors.code.0', 'A die with this code already exists.');
    spa($this)->postJson('/api/v1/core/dies', ['plantId' => $p1, 'code' => 'J-1', 'name' => 'Jig', 'dieType' => 'MOULD'])->assertStatus(422);
    spa($this)->postJson('/api/v1/auth/logout')->assertNoContent();

    // a CSA bound to plant 1 cannot move the die into plant 2
    orgUser($this, $this->t, 'CSA', ['PLANT', $p1]);
    patchWith($this, '/api/v1/core/dies/'.$die['id'], ['plantId' => $p2], 1)->assertForbidden()->assertJsonPath('code', 'SCOPE_FORBIDDEN');
    patchWith($this, '/api/v1/core/dies/'.$die['id'], ['status' => 'MAINTENANCE', 'currentStrokes' => 1200], 1)->assertOk()
        ->assertJsonPath('data.status', 'MAINTENANCE')->assertJsonPath('data.currentStrokes', '1200');
    spa($this)->postJson('/api/v1/auth/logout')->assertNoContent();

    orgUser($this, $this->t, 'CSA');
    patchWith($this, '/api/v1/core/dies/'.$die['id'], ['plantId' => $p2], 2)->assertOk()->assertJsonPath('data.plantCode', 'P2');
    $move = AccessFixtures::run($this->t, fn (EoraPostgresConnection $db) => $db->selectOne(
        "select detail from audit_events where action = 'die.updated' and entity_id = ? order by occurred_at desc, id desc limit 1", [$die['id']],
    ));
    expect(json_decode((string) $move->detail, true)['changed'])->toBe(['plant_id']);
})->group('integration', 'master');

it('lists production resources only inside the caller\'s plant scope and hides other tenants', function () {
    [$p1, $p2] = $this->t['plants'];
    orgUser($this, $this->t, 'CSA');
    workCenter($this, $p1, 'WC-1');
    workCenter($this, $p2, 'WC-2');
    spa($this)->postJson('/api/v1/auth/logout')->assertNoContent();

    orgUser($this, $this->t, 'PRL', ['PLANT', $p2]);
    spa($this)->getJson('/api/v1/query/core.work_centers')->assertOk()->assertJsonPath('meta.total', 1)->assertJsonPath('data.0.code', 'WC-2');
    spa($this)->getJson('/api/v1/query/core.work_centers?q=wc&sort=-code')->assertOk()->assertJsonPath('meta.total', 1);
    spa($this)->postJson('/api/v1/core/dies', ['plantId' => $p2, 'code' => 'D-9', 'name' => 'x', 'dieType' => 'DIE'])->assertForbidden();

    $other = TenantFixtures::provision(1);
    spa($this)->postJson('/api/v1/core/work-centers', ['plantId' => $other['plants'][0], 'code' => 'X', 'name' => 'x', 'workCenterType' => 'PRESS'])
        ->assertForbidden(); // PRL has view only: refused before anything about the plant is looked up
})->group('integration', 'master');
