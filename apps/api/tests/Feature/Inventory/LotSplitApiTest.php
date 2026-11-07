<?php

declare(strict_types=1);

use App\Platform\Database\EoraPostgresConnection;
use Tests\Support\AccessFixtures;
use Tests\Support\TenantFixtures;

/*
 * M3 step 5 (doc 32 §1.5, doc 04 row 7, DD-04, D-099): lot split / remnant through the HTTP API — part of a lot becomes
 * child lots at the same place and status, numbered after the parent, with measured quantity and optional dimensions.
 */

beforeEach(function () {
    $this->t = TenantFixtures::provision(2);
    AccessFixtures::roles($this->t);
    $this->f = rcFixture($this->t);
});

/** A received coil lot (10 PCS, BLOCKED at RM-01), then signed in as the production leader who splits it. */
function lsLot(object $test, array $t, array $f, array $scope = ['TENANT']): array
{
    orgUser($test, $t, 'MAT');
    $lot = rcReceive($test, $f)->assertCreated()->json('data');
    rtSwitch($test, $t, 'PRL', $scope);

    return $f + ['lotId' => $lot['lotId'], 'lotNo' => $lot['lotNo']];
}

it('splits part of a lot into numbered child lots where it lies, with genealogy, and replays a retried request (D-099)', function () {
    $f = lsLot($this, $this->t, $this->f);
    $year = substr($f['today'], 0, 4);

    $split = lsSplit($this, $f)->assertCreated()
        ->assertJsonPath('data.docNo', "SPL-P1-{$year}-0001")
        ->assertJsonPath('data.lotNo', $f['lotNo'])
        ->assertJsonPath('data.children.0.lotNo', "{$f['lotNo']}-01")->assertJsonPath('data.children.0.qty', '3')
        ->assertJsonPath('data.children.1.lotNo', "{$f['lotNo']}-02")->assertJsonPath('data.children.1.qty', '2')
        ->json('data');
    lsSplit($this, $f)->assertOk()->assertJsonPath('data.documentId', $split['documentId'])->assertJsonPath('data.replayed', true)
        ->assertJsonPath('data.children.1.lotNo', "{$f['lotNo']}-02");
    lsSplit($this, $f, ['children' => [['qty' => '1']]])->assertStatus(409)->assertJsonPath('code', 'IDEMPOTENCY_KEY_REUSED');

    // the location total is unchanged; children keep the place, status and supplier lot of their parent
    $lines = collect(spa($this)->getJson('/api/v1/query/inventory.stock?filter[lotTracked]=true&filter[itemId]='.$f['coil'])->assertOk()->json('data'))
        ->mapWithKeys(fn ($r) => [$r['lotNo'] => [$r['locationCode'], $r['stockStatus'], $r['qty'], $r['supplierLotNo'], $r['lotOrigin'], $r['parentLotNo']]])->sortKeys()->all();
    expect($lines)->toBe([
        $f['lotNo'] => ['RM-01', 'BLOCKED', '5', 'HEAT-778', 'RECEIPT', null],
        "{$f['lotNo']}-01" => ['RM-01', 'BLOCKED', '3', 'HEAT-778', 'SPLIT', $f['lotNo']],
        "{$f['lotNo']}-02" => ['RM-01', 'BLOCKED', '2', 'HEAT-778', 'SPLIT', $f['lotNo']],
    ]);
    $child = $split['children'][0]['lotId'];
    expect(rcRun($this->t, fn (EoraPostgresConnection $db) => $db->selectOne(
        'select properties::text as p, (select count(*) from genealogy_edges where from_lot_id = ?) as edges from lots where id = ?', [$f['lotId'], $child],
    )))->p->toBe('{"widthMm": 1219, "lengthMm": 2438.5}')->edges->toBe(2);

    // the next split of the parent continues its numbers; a child can be split again
    lsSplit($this, $f, ['children' => [['qty' => '1']]], 'split-key-0002')->assertCreated()->assertJsonPath('data.children.0.lotNo', "{$f['lotNo']}-03");
    lsSplit($this, ['lotId' => $child] + $f, ['children' => [['qty' => '1', 'thicknessMm' => '1.2']]], 'split-key-0003')->assertCreated()
        ->assertJsonPath('data.children.0.lotNo', "{$f['lotNo']}-01-01");
})->group('integration', 'inventory');

it('splits only lots of lot-tracked items, at a place of the plant, never more than is there (D-099)', function () {
    $f = lsLot($this, $this->t, $this->f);

    lsSplit($this, $f, ['itemId' => $f['oil']], 'split-key-0011')->assertStatus(422)
        ->assertJsonPath('errors.itemId', ['This item is not tracked by lot, so it has no lot to split.']);
    lsSplit($this, $f, ['itemId' => $f['fg']], 'split-key-0012')->assertStatus(422)->assertJsonValidationErrors(['lotId']);
    lsSplit($this, $f, ['locationId' => $f['supplierLoc']], 'split-key-0013')->assertStatus(422)->assertJsonValidationErrors(['locationId']);
    lsSplit($this, $f, ['locationId' => $f['other']], 'split-key-0014')->assertStatus(422)->assertJsonValidationErrors(['locationId']);
    lsSplit($this, $f, ['children' => [['qty' => '1.5']]], 'split-key-0015')->assertStatus(422)
        ->assertJsonPath('errors', ['children.0.qty' => ['Enter a whole number of PCS.']]);
    lsSplit($this, $f, ['children' => [['qty' => '1', 'widthMm' => '0']]], 'split-key-0016')->assertStatus(422)->assertJsonValidationErrors(['children.0.widthMm']);
    lsSplit($this, $f, ['children' => []], 'split-key-0017')->assertStatus(422)->assertJsonValidationErrors(['children']);
    lsSplit($this, $f, ['children' => [['qty' => '6'], ['qty' => '5']]], 'split-key-0018')->assertStatus(409)->assertJsonPath('code', 'INSUFFICIENT_STOCK');
    // the lot is BLOCKED: there is no UNRESTRICTED stock of it to split
    lsSplit($this, $f, ['stockStatus' => 'UNRESTRICTED'], 'split-key-0019')->assertStatus(409)->assertJsonPath('code', 'INSUFFICIENT_STOCK');

    // nothing was split and no child lot was left behind
    expect(rcRun($this->t, fn (EoraPostgresConnection $db) => (int) $db->selectOne('select count(*) as n from lots where parent_lot_id = ?', [$f['lotId']])->n))->toBe(0);
})->group('integration', 'inventory');

it('posts splits only with production.lot_split.post inside the plant scope (doc 18)', function () {
    $f = lsLot($this, $this->t, $this->f, ['PLANT', $this->f['plant2']]);
    lsSplit($this, $f)->assertForbidden();
    foreach (['MAT', 'INV', 'OPR'] as $role) {
        rtSwitch($this, $this->t, $role);
        lsSplit($this, $f)->assertForbidden();
    }
    rtSwitch($this, $this->t, 'PRL', ['PLANT', $f['plant']]);
    lsSplit($this, $f)->assertCreated();
})->group('integration', 'inventory');
