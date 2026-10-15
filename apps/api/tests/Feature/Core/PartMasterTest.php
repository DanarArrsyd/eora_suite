<?php

declare(strict_types=1);

use App\Platform\Database\EoraPostgresConnection;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Date;
use Tests\Support\AccessFixtures;
use Tests\Support\TenantFixtures;

/*
 * M2 step 2a (doc 29 §1.2, doc 26 §2.2, M2-D4, D-062): units, items, material specifications, item unit conversions
 * and die links through the HTTP API (group B), with the database rules behind them.
 */

beforeEach(function () {
    $this->t = TenantFixtures::provision(2);
    AccessFixtures::roles($this->t);
});

function uomId(array $t, string $code): string
{
    return (string) AccessFixtures::run($t, fn (EoraPostgresConnection $db) => $db->selectOne('select id from uoms where code = ?', [$code])->id);
}

function newItem(object $test, array $t, string $code, array $extra = []): array
{
    return spa($test)->postJson('/api/v1/core/items', $extra + ['code' => $code, 'name' => "Part {$code}", 'itemType' => 'FINISHED', 'baseUomId' => uomId($t, 'PCS')])
        ->assertCreated()->json('data');
}

it('starts every tenant with KG, PCS and SHEET; code, class and decimals never change', function () {
    orgUser($this, $this->t, 'CSA');
    $units = spa($this)->getJson('/api/v1/query/core.uoms')->assertOk()->json('data');
    expect(array_map(static fn ($u) => [$u['code'], $u['uomClass'], $u['decimals']], $units))
        ->toBe([['KG', 'MASS', 3], ['PCS', 'COUNT', 0], ['SHEET', 'COUNT', 0]]);

    spa($this)->postJson('/api/v1/core/uoms', ['code' => 'M', 'name' => 'Metre', 'uomClass' => 'LENGTH', 'decimals' => 3])->assertCreated();
    spa($this)->postJson('/api/v1/core/uoms', ['code' => 'KG', 'name' => 'Again', 'uomClass' => 'MASS', 'decimals' => 3])
        ->assertStatus(422)->assertJsonPath('errors.code.0', 'A unit with this code already exists.');
    expect(fn () => AccessFixtures::run($this->t, fn (EoraPostgresConnection $db) => $db->update(
        "update uoms set decimals = 6 where code = 'KG'",
    )))->toThrow(QueryException::class); // no UPDATE grant on decimals
})->group('integration', 'master');

it('keeps item codes upper-case and unique, the base unit fixed, and checks drawing data', function () {
    orgUser($this, $this->t, 'CSA');
    $item = newItem($this, $this->t, 'BRK-100.A/2', ['drawingNo' => 'DWG-77', 'drawingRev' => 'B', 'incomingReleaseRequired' => true]);
    expect($item['baseUomCode'])->toBe('PCS')->and($item['lotControlled'])->toBeTrue()->and($item['incomingReleaseRequired'])->toBeTrue()
        ->and($item['specId'])->toBeNull()->and($item['status'])->toBe('ACTIVE');

    spa($this)->postJson('/api/v1/core/items', ['code' => 'brk-1', 'name' => 'x', 'itemType' => 'RAW', 'baseUomId' => uomId($this->t, 'KG')])
        ->assertStatus(422)->assertJsonValidationErrors(['code']);
    spa($this)->postJson('/api/v1/core/items', ['code' => 'BRK-100.A/2', 'name' => 'x', 'itemType' => 'RAW', 'baseUomId' => uomId($this->t, 'KG')])
        ->assertStatus(422)->assertJsonPath('errors.code.0', 'An item with this code already exists.');
    spa($this)->postJson('/api/v1/core/items', ['code' => 'NO-DWG', 'name' => 'x', 'itemType' => 'RAW', 'baseUomId' => uomId($this->t, 'KG'), 'drawingRev' => 'A'])
        ->assertStatus(422)->assertJsonPath('errors.drawingRev.0', 'Enter the drawing number for this revision.');
    patchWith($this, '/api/v1/core/items/'.$item['id'], ['drawingNo' => null], 1)
        ->assertStatus(422)->assertJsonPath('errors.drawingRev.0', 'Enter the drawing number for this revision.');

    $kg = spa($this)->getJson('/api/v1/query/core.uoms?filter[code]=KG')->json('data.0');
    patchWith($this, '/api/v1/core/uoms/'.$kg['id'], ['status' => 'INACTIVE'], $kg['version'])->assertOk();
    spa($this)->postJson('/api/v1/core/items', ['code' => 'COIL-1', 'name' => 'Coil', 'itemType' => 'RAW', 'baseUomId' => $kg['id']])
        ->assertStatus(422)->assertJsonPath('errors.baseUomId.0', 'This unit is inactive.');

    $obsolete = patchWith($this, '/api/v1/core/items/'.$item['id'], ['status' => 'OBSOLETE', 'name' => 'Bracket 100 (old)'], 1)->assertOk()->json('data');
    expect($obsolete['status'])->toBe('OBSOLETE')->and($obsolete['code'])->toBe('BRK-100.A/2');
    $sheet = uomId($this->t, 'SHEET');
    expect(fn () => AccessFixtures::run($this->t, fn (EoraPostgresConnection $db) => $db->update(
        'update items set base_uom_id = ? where id = ?', [$sheet, $item['id']],
    )))->toThrow(QueryException::class); // base unit fixed
})->group('integration', 'master');

it('searches items by code prefix and name, filters by type, and lets part planners maintain them in any scope', function () {
    // MAT holds master.part.create in plant 1 only: items are tenant-wide master data
    orgUser($this, $this->t, 'MAT', ['PLANT', $this->t['plants'][0]]);
    newItem($this, $this->t, 'BRK-100');
    newItem($this, $this->t, 'BRK-200', ['name' => 'Bracket left']);
    spa($this)->postJson('/api/v1/core/items', ['code' => 'SHEET-SPCC', 'name' => 'SPCC sheet 1.2', 'itemType' => 'RAW', 'baseUomId' => uomId($this->t, 'SHEET')])->assertCreated();

    spa($this)->getJson('/api/v1/query/core.items?q=brk')->assertJsonPath('meta.total', 2);
    spa($this)->getJson('/api/v1/query/core.items?q=left')->assertJsonPath('meta.total', 1)->assertJsonPath('data.0.code', 'BRK-200');
    spa($this)->getJson('/api/v1/query/core.items?filter[itemType]=RAW')->assertJsonPath('meta.total', 1)->assertJsonPath('data.0.baseUomCode', 'SHEET');
    spa($this)->postJson('/api/v1/auth/logout')->assertNoContent();

    // QCS views items but does not maintain them
    orgUser($this, $this->t, 'QCS', ['PLANT', $this->t['plants'][1]]);
    spa($this)->getJson('/api/v1/query/core.items')->assertOk()->assertJsonPath('meta.total', 3);
    spa($this)->postJson('/api/v1/core/items', ['code' => 'X', 'name' => 'x', 'itemType' => 'RAW', 'baseUomId' => uomId($this->t, 'KG')])
        ->assertForbidden()->assertJsonPath('code', 'FORBIDDEN');
})->group('integration', 'master');

it('creates the material specification once, then edits it at its version', function () {
    orgUser($this, $this->t, 'CSA');
    $item = spa($this)->postJson('/api/v1/core/items', ['code' => 'COIL-SPCC', 'name' => 'SPCC coil', 'itemType' => 'RAW', 'baseUomId' => uomId($this->t, 'KG')])->json('data');
    $uri = '/api/v1/core/items/'.$item['id'].'/material-spec';

    spa($this)->putJson($uri, ['grade' => 'SPCC', 'thicknessMm' => 1.2, 'widthMm' => 1219, 'densityGCm3' => 7.85])->assertCreated()
        ->assertJsonPath('data.specGrade', 'SPCC')->assertJsonPath('data.specThicknessMm', '1.200')->assertJsonPath('data.specDensityGCm3', '7.8500')
        ->assertJsonPath('data.specVersion', 1)->assertHeader('ETag', '"1"');
    // an item edit moves the item version only; the spec ETag stays the spec's own
    patchWith($this, '/api/v1/core/items/'.$item['id'], ['name' => 'SPCC coil 1.2'], 1)->assertOk()->assertHeader('ETag', '"2"');
    spa($this)->putJson($uri, ['grade' => 'SPCC-SD'])->assertStatus(428)->assertJsonPath('code', 'PRECONDITION_REQUIRED');
    spa($this)->putJson($uri, ['grade' => 'SPCC-SD', 'thicknessMm' => 1.2, 'widthMm' => 1219, 'densityGCm3' => 7.85], ['If-Match' => '"1"'])->assertOk()
        ->assertJsonPath('data.specGrade', 'SPCC-SD')->assertJsonPath('data.specVersion', 2)->assertHeader('ETag', '"2"');
    spa($this)->putJson($uri, ['thicknessMm' => -1], ['If-Match' => '"2"'])->assertStatus(422)->assertJsonValidationErrors(['thicknessMm']);
})->group('integration', 'master');

it('versions unit conversions: one factor per unit pair per day, no back-dating, a new version closes the open one', function () {
    orgUser($this, $this->t, 'CSA');
    $item = spa($this)->postJson('/api/v1/core/items', ['code' => 'SHEET-A', 'name' => 'Sheet A', 'itemType' => 'RAW', 'baseUomId' => uomId($this->t, 'SHEET')])->json('data');
    [$sheet, $kg] = [uomId($this->t, 'SHEET'), uomId($this->t, 'KG')];
    $today = Date::now('Asia/Jakarta')->toDateString();
    $later = Date::now('Asia/Jakarta')->addDays(10)->toDateString();
    $body = fn (string $from, string $to, $factor, string $day) => ['itemId' => $item['id'], 'fromUomId' => $from, 'toUomId' => $to, 'factor' => $factor, 'effectiveFrom' => $day];

    $v1 = spa($this)->postJson('/api/v1/core/uom-conversions', $body($sheet, $kg, 12.5, $today))->assertCreated()
        ->assertJsonPath('data.factor', '12.5000000000')->assertJsonPath('data.effectiveTo', null)->assertJsonPath('data.current', true)->json('data');
    spa($this)->postJson('/api/v1/core/uom-conversions', $body($sheet, $kg, 12.5, Date::now('Asia/Jakarta')->subDay()->toDateString()))
        ->assertStatus(422)->assertJsonValidationErrors(['effectiveFrom']);
    // the reverse direction is the same pair: kg→sheet cannot disagree with sheet→kg
    spa($this)->postJson('/api/v1/core/uom-conversions', $body($kg, $sheet, 0.08, $today))
        ->assertStatus(422)->assertJsonPath('errors.effectiveFrom.0', 'A version of this conversion already starts on or after this day.');

    spa($this)->postJson('/api/v1/core/uom-conversions', $body($kg, $sheet, 0.078125, $later))->assertCreated();
    $versions = spa($this)->getJson('/api/v1/query/core.uom_conversions?filter[itemId]='.$item['id'])->assertJsonPath('meta.total', 2)->json('data');
    expect($versions[1]['id'])->toBe($v1['id'])
        ->and($versions[1]['effectiveTo'])->toBe(Date::now('Asia/Jakarta')->addDays(9)->toDateString())
        ->and($versions[0]['current'])->toBeFalse();
    spa($this)->getJson('/api/v1/query/core.uom_conversions?filter[current]=true')->assertJsonPath('meta.total', 1)->assertJsonPath('data.0.id', $v1['id']);

    // a closed version never changes, not even in direct SQL
    expect(fn () => AccessFixtures::run($this->t, fn (EoraPostgresConnection $db) => $db->update(
        'update uom_conversions set effective_to = effective_to + 1 where id = ?', [$v1['id']],
    )))->toThrow(QueryException::class);
    expect(fn () => AccessFixtures::run($this->t, fn (EoraPostgresConnection $db) => $db->update(
        'update uom_conversions set factor = 13 where id = ?', [$v1['id']],
    )))->toThrow(QueryException::class);
})->group('integration', 'master');

it('links dies to the parts they make inside the die plant scope; other tenants stay unknown', function () {
    [$p1, $p2] = $this->t['plants'];
    orgUser($this, $this->t, 'CSA');
    $item = newItem($this, $this->t, 'BRK-300');
    $die = spa($this)->postJson('/api/v1/core/dies', ['plantId' => $p2, 'code' => 'D-300', 'name' => 'Bracket die', 'dieType' => 'DIE', 'cavities' => 2])->json('data');
    $link = spa($this)->postJson('/api/v1/core/die-items', ['dieId' => $die['id'], 'itemId' => $item['id'], 'partsPerStroke' => 2])->assertCreated()
        ->assertJsonPath('data.itemCode', 'BRK-300')->assertJsonPath('data.plantCode', 'P2')->json('data');
    spa($this)->postJson('/api/v1/core/die-items', ['dieId' => $die['id'], 'itemId' => $item['id']])
        ->assertStatus(422)->assertJsonPath('errors.code.0', 'This die is already linked to this item.');
    patchWith($this, '/api/v1/core/die-items/'.$link['id'], ['partsPerStroke' => 4], 1)->assertOk()->assertJsonPath('data.partsPerStroke', 4);
    spa($this)->postJson('/api/v1/auth/logout')->assertNoContent();

    orgUser($this, $this->t, 'CSA', ['PLANT', $p1]);
    patchWith($this, '/api/v1/core/die-items/'.$link['id'], ['status' => 'INACTIVE'], 2)->assertForbidden()->assertJsonPath('code', 'SCOPE_FORBIDDEN');
    spa($this)->getJson('/api/v1/query/core.die_items')->assertOk()->assertJsonPath('meta.total', 0);

    $other = TenantFixtures::provision(1);
    $foreignUom = (string) AccessFixtures::run($other, fn (EoraPostgresConnection $db) => $db->selectOne("select id from uoms where code = 'PCS'")->id);
    spa($this)->postJson('/api/v1/core/items', ['code' => 'X-1', 'name' => 'x', 'itemType' => 'RAW', 'baseUomId' => $foreignUom])
        ->assertStatus(422)->assertJsonPath('errors.baseUomId.0', 'Unknown unit.');
})->group('integration', 'master');
