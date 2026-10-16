<?php

declare(strict_types=1);

use App\Platform\Database\EoraPostgresConnection;
use Illuminate\Database\QueryException;
use Tests\Support\AccessFixtures;
use Tests\Support\TenantFixtures;

/*
 * M2 step 4a (doc 29 §1.4, doc 26 §2.5, D-B2-01, D-078, D-079): BOM versions and BOM–routing maps through the HTTP API
 * and the database guards behind them (B2-AT-09, 10, 42..44, 46, 53..55, 57; cycle rule of doc 26 §3 point 8).
 */

beforeEach(function () {
    $this->t = TenantFixtures::provision(2);
    AccessFixtures::roles($this->t);
});

function bomItem(object $test, array $t, string $code, string $type, string $uom = 'PCS'): array
{
    $u = (string) AccessFixtures::run($t, fn (EoraPostgresConnection $db) => $db->selectOne('select id from uoms where code = ?', [$uom])->id);

    return spa($test)->postJson('/api/v1/core/items', ['code' => $code, 'name' => "Item {$code}", 'itemType' => $type, 'baseUomId' => $u])->assertCreated()->json('data');
}

/** A DRAFT BOM version of $item with the given lines (component row, qty, purpose). */
function bomDraft(object $test, array $t, array $item, array $lines, int $startIn = 0): array
{
    $v = spa($test)->postJson('/api/v1/planning/bom-versions', ['itemId' => $item['id'], 'effectiveFrom' => rtDay($t, $startIn)])->assertCreated()->json('data');
    $body = array_map(static fn (array $l, int $i) => [
        'lineNo' => ($i + 1) * 10, 'componentItemId' => $l[0]['id'], 'grossQtyPer' => $l[1], 'uomId' => $l[0]['baseUomId'], 'linePurpose' => $l[2] ?? null,
    ], $lines, array_keys($lines));

    return spa($test)->putJson('/api/v1/planning/bom-versions/'.$v['id'].'/lines', ['lines' => $body], ['If-Match' => '"'.$v['version'].'"'])->assertOk()->json('data');
}

it('drafts a BOM: same component on two lines needs a purpose; never its own item; units must fit (B2-AT-10)', function () {
    orgUser($this, $this->t, 'CSA');
    $s = rtSetup($this, $this->t);
    $coil = bomItem($this, $this->t, 'COIL-1', 'RAW', 'KG');
    $nut = bomItem($this, $this->t, 'NUT-M6', 'RAW');
    $v = spa($this)->postJson('/api/v1/planning/bom-versions', ['itemId' => $s['item']['id'], 'effectiveFrom' => rtDay($this->t)])->assertCreated()->json('data');
    $put = fn (array $lines) => spa($this)->putJson('/api/v1/planning/bom-versions/'.$v['id'].'/lines', ['lines' => $lines], ['If-Match' => '"'.$v['version'].'"']);
    $line = fn (int $no, array $c, string $qty, ?string $purpose = null, ?string $uom = null) => ['lineNo' => $no, 'componentItemId' => $c['id'], 'grossQtyPer' => $qty, 'uomId' => $uom ?? $c['baseUomId'], 'linePurpose' => $purpose];

    $put([$line(10, $nut, '2'), $line(20, $nut, '2', 'flange side')])->assertStatus(422)->assertJsonValidationErrors(['lines.0.linePurpose']);
    $put([$line(10, $s['item'], '1')])->assertStatus(422)->assertJsonPath('errors', ['lines.0.componentItemId' => ['An item is not a component of its own BOM.']]);
    $put([$line(10, $coil, '0.5', null, $nut['baseUomId'])])->assertStatus(422)->assertJsonValidationErrors(['lines.0.uomId']);

    $saved = $put([$line(10, $coil, '0.485'), $line(20, $nut, '2', 'bracket side'), $line(30, $nut, '2', 'flange side')])->assertOk()->json('data');
    expect(array_column($saved['lines'], 'grossQtyPer'))->toBe(['0.485000', '2.000000', '2.000000'])
        ->and($saved['lines'][0]['uomCode'])->toBe('KG')->and($saved['lineCount'])->toBe(3);

    // the database refuses the item as its own component, and bought items have no BOM
    expect(fn () => AccessFixtures::run($this->t, fn (EoraPostgresConnection $db) => $db->insert(
        'insert into bom_lines (tenant_id, bom_version_id, line_no, component_item_id, gross_qty_per, uom_id) values (?, ?, 99, ?, 1, ?)',
        [$this->t['tenant'], $v['id'], $s['item']['id'], $s['item']['baseUomId']],
    )))->toThrow(QueryException::class);
    spa($this)->postJson('/api/v1/planning/bom-versions', ['itemId' => $coil['id'], 'effectiveFrom' => rtDay($this->t)])->assertStatus(422);
    spa($this)->postJson('/api/v1/planning/bom-versions', ['itemId' => $s['item']['id'], 'effectiveFrom' => rtDay($this->t)])->assertStatus(409); // one open version
})->group('integration', 'master');

it('approves a BOM by a second person in any scope, supersedes on revise, and refuses the preparer (D-078)', function () {
    [$p1, $p2] = $this->t['plants'];
    $preparer = orgUser($this, $this->t, 'PPC', ['PLANT', $p1]);
    rtSwitch($this, $this->t, 'CSA');
    $s = rtSetup($this, $this->t);
    $coil = bomItem($this, $this->t, 'COIL-1', 'RAW', 'KG');
    rtSwitch($this, $this->t, 'PPC', ['PLANT', $p1]);

    $v1 = bomDraft($this, $this->t, $s['item'], [[$coil, '0.5']]);
    spa($this)->postJson('/api/v1/planning/bom-versions/'.$v1['id'].'/actions/approve')->assertForbidden()->assertJsonPath('code', 'FORBIDDEN'); // PPC never approves
    spa($this)->postJson('/api/v1/planning/bom-versions/'.$v1['id'].'/actions/submit')->assertOk()->assertJsonPath('data.status', 'SUBMITTED');
    patchWith($this, '/api/v1/planning/bom-versions/'.$v1['id'], ['changeReason' => 'late edit'], 99)->assertStatus(409);

    // an approver bound to plant 2 approves this tenant-wide BOM (any scope, D-078)
    $checker = rtSwitch($this, $this->t, 'CSA', ['PLANT', $p2]);
    spa($this)->postJson('/api/v1/planning/bom-versions/'.$v1['id'].'/actions/approve')->assertOk()->assertJsonPath('data.status', 'APPROVED')
        ->assertJsonPath('data.inUse', true);

    $v2 = spa($this)->postJson('/api/v1/planning/bom-versions', ['itemId' => $s['item']['id'], 'effectiveFrom' => rtDay($this->t, 1), 'changeReason' => 'Thinner coil'])
        ->assertCreated()->json('data');
    expect($v2['versionNo'])->toBe(2)->and($v2['lineCount'])->toBe(1);
    spa($this)->postJson('/api/v1/planning/bom-versions/'.$v2['id'].'/actions/submit')->assertOk();
    spa($this)->postJson('/api/v1/planning/bom-versions/'.$v2['id'].'/actions/approve')->assertForbidden()->assertJsonPath('code', 'SOD_VIOLATION');

    rtSwitch($this, $this->t, 'CSA');
    spa($this)->postJson('/api/v1/planning/bom-versions/'.$v2['id'].'/actions/approve')->assertOk();
    $old = spa($this)->getJson('/api/v1/planning/bom-versions/'.$v1['id'])->assertOk()->json('data');
    expect($old['status'])->toBe('SUPERSEDED')->and($old['effectiveTo'])->toBe(rtDay($this->t));

    $audit = AccessFixtures::run($this->t, fn (EoraPostgresConnection $db) => $db->select(
        "select action, actor from audit_events where entity_type = 'bom_version' and action in ('bom_version.approved', 'bom_version.superseded') order by occurred_at, id",
    ));
    expect(array_column(array_map(fn ($r) => (array) $r, $audit), 'action'))->toBe(['bom_version.approved', 'bom_version.superseded', 'bom_version.approved'])
        ->and($audit[0]->actor)->toBe($checker['id']);
    expect($preparer['id'])->not->toBe($checker['id']);

    // approved versions and their lines never change
    expect(fn () => AccessFixtures::run($this->t, fn (EoraPostgresConnection $db) => $db->update('update bom_lines set gross_qty_per = 9 where bom_version_id = ?', [$v1['id']])))
        ->toThrow(QueryException::class);
    expect(fn () => AccessFixtures::run($this->t, fn (EoraPostgresConnection $db) => $db->update("update bom_versions set change_reason = 'x' where id = ?", [$v2['id']])))
        ->toThrow(QueryException::class);
})->group('integration', 'master');

it('refuses a component chain that comes back to the item (Option M acyclic)', function () {
    orgUser($this, $this->t, 'CSA');
    $sf1 = bomItem($this, $this->t, 'PART-A-BLK', 'SEMI_FINISHED');
    $sf2 = bomItem($this, $this->t, 'PART-A-BND', 'SEMI_FINISHED');
    $b1 = bomDraft($this, $this->t, $sf2, [[$sf1, '1']]); // SF-2 consumes SF-1
    spa($this)->postJson('/api/v1/planning/bom-versions/'.$b1['id'].'/actions/submit')->assertOk();
    $b2 = bomDraft($this, $this->t, $sf1, [[$sf2, '1']]); // SF-1 consumes SF-2
    spa($this)->postJson('/api/v1/planning/bom-versions/'.$b2['id'].'/actions/submit')->assertOk();

    rtSwitch($this, $this->t, 'CSA');
    spa($this)->postJson('/api/v1/planning/bom-versions/'.$b1['id'].'/actions/approve')->assertOk();
    spa($this)->postJson('/api/v1/planning/bom-versions/'.$b2['id'].'/actions/approve')->assertStatus(422)
        ->assertJsonPath('errors.version.0', 'The components would consume the item itself (a cycle through the BOMs).');
})->group('integration', 'master');

/** An approved BOM (coil + two nuts) and an approved routing of the same item; returns [bomVersion, routingVersion]. */
function mapPair(object $test, array $t, array $s): array
{
    $coil = bomItem($test, $t, 'COIL-1', 'RAW', 'KG');
    $nut = bomItem($test, $t, 'NUT-M6', 'RAW');
    $bom = bomDraft($test, $t, $s['item'], [[$coil, '0.5'], [$nut, '2', 'bracket side'], [$nut, '2', 'flange side']]);
    spa($test)->postJson('/api/v1/planning/bom-versions/'.$bom['id'].'/actions/submit')->assertOk();
    [, $rv] = rtDraft($test, $t, $s);
    spa($test)->postJson('/api/v1/planning/routing-versions/'.$rv['id'].'/actions/submit')->assertOk();

    return [$bom, $rv];
}

it('draws a map only on locked versions of the same item (D-079)', function () {
    orgUser($this, $this->t, 'CSA');
    $s = rtSetup($this, $this->t);
    $draftBom = bomDraft($this, $this->t, $s['item'], [[bomItem($this, $this->t, 'PIN-1', 'RAW'), '1']]);
    [, $rv] = rtDraft($this, $this->t, $s);
    spa($this)->postJson('/api/v1/planning/routing-versions/'.$rv['id'].'/actions/submit')->assertOk();
    spa($this)->postJson('/api/v1/planning/bom-routing-maps', ['bomVersionId' => $draftBom['id'], 'routingVersionId' => $rv['id']])->assertStatus(409)
        ->assertJsonPath('code', 'INVALID_STATE_TRANSITION');
    expect(fn () => AccessFixtures::run($this->t, fn (EoraPostgresConnection $db) => $db->insert(
        'insert into bom_routing_maps (tenant_id, item_id, plant_id, bom_version_id, routing_id, routing_version_id, map_revision) values (?, ?, ?, ?, ?, ?, 1)',
        [$this->t['tenant'], $s['item']['id'], $s['plant'], $draftBom['id'], $rv['routingId'], $rv['id']],
    )))->toThrow(QueryException::class);
})->group('integration', 'master');

it('runs a map revision through submit, approval by a second person and supersession', function () {
    orgUser($this, $this->t, 'CSA');
    $s = rtSetup($this, $this->t);
    [$bom, $rv] = mapPair($this, $this->t, $s);
    $map = spa($this)->postJson('/api/v1/planning/bom-routing-maps', ['bomVersionId' => $bom['id'], 'routingVersionId' => $rv['id']])->assertCreated()->json('data');
    expect($map['mapRevision'])->toBe(1)->and($map['status'])->toBe('DRAFT')->and($map['bomLineCount'])->toBe(3)->and($map['lineCount'])->toBe(0);
    $detail = spa($this)->getJson('/api/v1/planning/bom-routing-maps/'.$map['id'])->assertOk()->json('data');
    [$opBlank, $opInsp] = array_column($detail['operations'], 'id');
    [$coilLine, $nut1, $nut2] = array_column($detail['bomLines'], 'bomLineId');

    $put = fn (array $lines, int $version) => spa($this)->putJson('/api/v1/planning/bom-routing-maps/'.$map['id'].'/lines', ['lines' => $lines], ['If-Match' => '"'.$version.'"']);
    $partial = $put([['bomLineId' => $coilLine, 'routingOperationId' => $opBlank]], $map['version'])->assertOk()->json('data');
    spa($this)->postJson('/api/v1/planning/bom-routing-maps/'.$map['id'].'/actions/submit')->assertStatus(422)->assertJsonPath('errors.lines.0', '2 BOM lines have no operation yet.');
    // the same nut on two lines goes to two different operations (B2-AT-10)
    $put([
        ['bomLineId' => $coilLine, 'routingOperationId' => $opBlank],
        ['bomLineId' => $nut1, 'routingOperationId' => $opBlank],
        ['bomLineId' => $nut2, 'routingOperationId' => $opInsp],
    ], $partial['version'])->assertOk()->assertJsonPath('data.lineCount', 3);
    spa($this)->postJson('/api/v1/planning/bom-routing-maps/'.$map['id'].'/actions/submit')->assertOk();

    // the preparer never approves; nor anyone before both versions are approved
    spa($this)->postJson('/api/v1/planning/bom-routing-maps/'.$map['id'].'/actions/approve')->assertForbidden()->assertJsonPath('code', 'SOD_VIOLATION');
    rtSwitch($this, $this->t, 'CSA');
    spa($this)->postJson('/api/v1/planning/bom-routing-maps/'.$map['id'].'/actions/approve')->assertStatus(409)
        ->assertJsonPath('detail', 'Approve the BOM version and the routing version first.');
    spa($this)->postJson('/api/v1/planning/bom-versions/'.$bom['id'].'/actions/approve')->assertOk();
    spa($this)->postJson('/api/v1/planning/routing-versions/'.$rv['id'].'/actions/approve')->assertOk();
    spa($this)->postJson('/api/v1/planning/bom-routing-maps/'.$map['id'].'/actions/approve')->assertOk()->assertJsonPath('data.status', 'APPROVED');

    // approved lines never change (B2-AT-42)
    expect(fn () => AccessFixtures::run($this->t, fn (EoraPostgresConnection $db) => $db->delete('delete from bom_routing_map_lines where map_id = ?', [$map['id']])))
        ->toThrow(QueryException::class);

    // revision 2 copies the lines; approving it supersedes revision 1 (B2-AT-43)
    $rev2 = spa($this)->postJson('/api/v1/planning/bom-routing-maps', ['bomVersionId' => $bom['id'], 'routingVersionId' => $rv['id'], 'changeReason' => 'Nut 2 at blanking'])
        ->assertCreated()->json('data');
    expect($rev2['mapRevision'])->toBe(2)->and($rev2['lineCount'])->toBe(3);
    spa($this)->putJson('/api/v1/planning/bom-routing-maps/'.$rev2['id'].'/lines', ['lines' => [
        ['bomLineId' => $coilLine, 'routingOperationId' => $opBlank],
        ['bomLineId' => $nut1, 'routingOperationId' => $opBlank],
        ['bomLineId' => $nut2, 'routingOperationId' => $opBlank],
    ]], ['If-Match' => '"'.$rev2['version'].'"'])->assertOk();
    spa($this)->postJson('/api/v1/planning/bom-routing-maps/'.$rev2['id'].'/actions/submit')->assertOk();
    rtSwitch($this, $this->t, 'CSA');
    spa($this)->postJson('/api/v1/planning/bom-routing-maps/'.$rev2['id'].'/actions/approve')->assertOk();
    spa($this)->getJson('/api/v1/planning/bom-routing-maps/'.$map['id'])->assertJsonPath('data.status', 'SUPERSEDED');

    // two approved revisions for one pair are impossible (B2-AT-44)
    expect(AccessFixtures::run($this->t, fn (EoraPostgresConnection $db) => (int) $db->selectOne(
        "select count(*) as n from bom_routing_maps where bom_version_id = ? and routing_version_id = ? and status = 'APPROVED'", [$bom['id'], $rv['id']],
    )->n))->toBe(1);
})->group('integration', 'master');

it('keeps a map inside one item and one version pair (B2-AT-53..55, 57)', function () {
    orgUser($this, $this->t, 'CSA');
    $s = rtSetup($this, $this->t);
    [$bom, $rv] = mapPair($this, $this->t, $s);
    // a routing of another item
    $other = bomItem($this, $this->t, 'BRK-200', 'FINISHED');
    $r2 = spa($this)->postJson('/api/v1/planning/routings', ['plantId' => $s['plant'], 'itemId' => $other['id'], 'routingCode' => 'R1',
        'routingKind' => 'PRIMARY', 'effectiveFrom' => rtDay($this->t)])->assertCreated()->json('data');
    $rv2 = spa($this)->getJson('/api/v1/query/planning.routing_versions?filter[routingId]='.$r2['id'])->json('data.0');
    spa($this)->postJson('/api/v1/planning/bom-routing-maps', ['bomVersionId' => $bom['id'], 'routingVersionId' => $rv2['id']])
        ->assertStatus(422)->assertJsonPath('errors.routingVersionId.0', 'The BOM is for BRK-100, the routing for BRK-200. Choose a routing of BRK-100.');

    // the database refuses the same pairing and lines outside the pair
    expect(fn () => AccessFixtures::run($this->t, fn (EoraPostgresConnection $db) => $db->insert(
        'insert into bom_routing_maps (tenant_id, item_id, plant_id, bom_version_id, routing_id, routing_version_id, map_revision) values (?, ?, ?, ?, ?, ?, 1)',
        [$this->t['tenant'], $s['item']['id'], $s['plant'], $bom['id'], $r2['id'], $rv['id']],
    )))->toThrow(QueryException::class);
    $map = spa($this)->postJson('/api/v1/planning/bom-routing-maps', ['bomVersionId' => $bom['id'], 'routingVersionId' => $rv['id']])->assertCreated()->json('data');
    $line = spa($this)->getJson('/api/v1/planning/bom-routing-maps/'.$map['id'])->json('data.bomLines.0.bomLineId');
    $foreignOp = AccessFixtures::run($this->t, fn (EoraPostgresConnection $db) => $db->selectOne(
        'insert into routing_operations (tenant_id, plant_id, routing_version_id, op_seq, process_type_id, work_center_id, std_cycle_seconds, cycle_basis)
         values (?, ?, ?, 10, ?, ?, 1, \'PER_PIECE\') returning id',
        [$this->t['tenant'], $s['plant'], $rv2['id'], $s['blank']['id'], $s['wc']['id']],
    )->id);
    spa($this)->putJson('/api/v1/planning/bom-routing-maps/'.$map['id'].'/lines', ['lines' => [['bomLineId' => $line, 'routingOperationId' => $foreignOp]]], ['If-Match' => '"'.$map['version'].'"'])
        ->assertStatus(422)->assertJsonValidationErrors(['lines.0.routingOperationId']);
    expect(fn () => AccessFixtures::run($this->t, fn (EoraPostgresConnection $db) => $db->insert(
        'insert into bom_routing_map_lines (tenant_id, map_id, bom_version_id, routing_version_id, bom_line_id, routing_operation_id) values (?, ?, ?, ?, ?, ?)',
        [$this->t['tenant'], $map['id'], $bom['id'], $rv['id'], $line, $foreignOp],
    )))->toThrow(QueryException::class);

    // another tenant sees nothing
    $other = TenantFixtures::provision(1);
    AccessFixtures::roles($other);
    rtSwitch($this, $other, 'CSA');
    spa($this)->getJson('/api/v1/planning/bom-routing-maps/'.$map['id'])->assertNotFound();
    spa($this)->getJson('/api/v1/planning/bom-versions/'.$bom['id'])->assertNotFound();
    spa($this)->getJson('/api/v1/query/planning.bom_versions')->assertOk()->assertJsonPath('meta.total', 0);
})->group('integration', 'master');

it('drops stale map lines when a withdrawn BOM version changes; the map cannot be submitted incomplete', function () {
    orgUser($this, $this->t, 'CSA');
    $s = rtSetup($this, $this->t);
    [$bom, $rv] = mapPair($this, $this->t, $s);
    $map = spa($this)->postJson('/api/v1/planning/bom-routing-maps', ['bomVersionId' => $bom['id'], 'routingVersionId' => $rv['id']])->assertCreated()->json('data');
    $detail = spa($this)->getJson('/api/v1/planning/bom-routing-maps/'.$map['id'])->json('data');
    $op = $detail['operations'][0]['id'];
    spa($this)->putJson('/api/v1/planning/bom-routing-maps/'.$map['id'].'/lines', ['lines' => array_map(
        static fn ($l) => ['bomLineId' => $l['bomLineId'], 'routingOperationId' => $op], $detail['bomLines'],
    )], ['If-Match' => '"'.$map['version'].'"'])->assertOk()->assertJsonPath('data.lineCount', 3);

    // the BOM goes back to Draft and its lines are replaced: the map's lines pointing at them go
    $b = spa($this)->postJson('/api/v1/planning/bom-versions/'.$bom['id'].'/actions/withdraw')->assertOk()->json('data');
    $kept = array_map(static fn ($l) => [
        'lineNo' => $l['lineNo'], 'componentItemId' => $l['componentItemId'], 'grossQtyPer' => $l['grossQtyPer'], 'uomId' => $l['uomId'], 'linePurpose' => $l['linePurpose'],
    ], $b['lines']);
    spa($this)->putJson('/api/v1/planning/bom-versions/'.$bom['id'].'/lines', ['lines' => $kept], ['If-Match' => '"'.$b['version'].'"'])->assertOk();
    spa($this)->getJson('/api/v1/planning/bom-routing-maps/'.$map['id'])->assertJsonPath('data.lineCount', 0);
    spa($this)->postJson('/api/v1/planning/bom-routing-maps/'.$map['id'].'/actions/submit')->assertStatus(422);
})->group('integration', 'master');
