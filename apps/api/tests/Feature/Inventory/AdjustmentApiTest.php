<?php

declare(strict_types=1);

use App\Platform\Database\EoraPostgresConnection;
use Tests\Support\AccessFixtures;
use Tests\Support\TenantFixtures;

/*
 * M3 step 6 (doc 32 §1.6, doc 04 row 12, D-060, D-100): stock adjustment through the HTTP API — inventory control
 * requests one line with a reason code, a second person approves (posts) or rejects it; nothing moves before approval.
 */

beforeEach(function () {
    $this->t = TenantFixtures::provision(2);
    AccessFixtures::roles($this->t);
    $this->f = rcFixture($this->t);
});

function adRequest(object $test, array $f, array $body = [], string $key = 'adjust-key-0001')
{
    return spa($test)->postJson('/api/v1/inventory/adjustments', $body + [
        'plantId' => $f['plant'], 'itemId' => $f['coil'], 'lotId' => $f['lotId'] ?? null, 'locationId' => $f['rm1'], 'stockStatus' => 'BLOCKED',
        'direction' => 'MINUS', 'qty' => '3', 'reasonCode' => 'DAMAGE', 'note' => 'Edge of the coil crushed by the forklift',
    ], ['Idempotency-Key' => $key]);
}

function adApprove(object $test, string $id, string $key = 'approve-key-0001')
{
    return spa($test)->postJson("/api/v1/inventory/adjustments/{$id}/approve", [], ['Idempotency-Key' => $key]);
}

/** A received coil lot (10 PCS, BLOCKED at RM-01), then signed in as inventory control. */
function adLot(object $test, array $t, array $f): array
{
    orgUser($test, $t, 'MAT');
    $lot = rcReceive($test, $f)->assertCreated()->json('data.lotId');
    rtSwitch($test, $t, 'INV', ['PLANT', $f['plant']]);

    return $f + ['lotId' => $lot];
}

/** @return array<string, string> "<location> <status>" => qty of the lot */
function adStock(object $test, string $lot): array
{
    return collect(spa($test)->getJson('/api/v1/query/inventory.stock?filter[lotId]='.$lot)->assertOk()->json('data'))
        ->mapWithKeys(fn ($r) => [$r['locationCode'].' '.$r['stockStatus'] => $r['qty']])->sortKeys()->all();
}

it('requests an adjustment that moves nothing until a second person approves it (D-060, D-100)', function () {
    $f = adLot($this, $this->t, $this->f);
    $year = substr($f['today'], 0, 4);

    $id = adRequest($this, $f)->assertCreated()->assertJsonPath('data.status', 'SUBMITTED')->json('data.documentId');
    adRequest($this, $f)->assertOk()->assertJsonPath('data.documentId', $id)->assertJsonPath('data.replayed', true);
    adRequest($this, $f, ['qty' => '2'])->assertStatus(409)->assertJsonPath('code', 'IDEMPOTENCY_KEY_REUSED');
    expect(adStock($this, $f['lotId']))->toBe(['RM-01 BLOCKED' => '10']);
    spa($this)->getJson('/api/v1/query/inventory.adjustments')->assertOk()
        ->assertJsonPath('data.0.status', 'SUBMITTED')->assertJsonPath('data.0.direction', 'MINUS')->assertJsonPath('data.0.qty', '3')
        ->assertJsonPath('data.0.locationCode', 'RM-01')->assertJsonPath('data.0.reasonCode', 'DAMAGE')->assertJsonPath('data.0.code', null);

    // the requester never decides, even holding the approver's role too
    $requester = rcRun($this->t, fn (EoraPostgresConnection $db) => (string) $db->selectOne('select requested_by from inventory_documents where id = ?', [$id])->requested_by);
    AccessFixtures::grant($this->t, $requester, 'CSA', ['TENANT']);
    adApprove($this, $id)->assertForbidden()->assertJsonPath('code', 'SOD_VIOLATION');
    spa($this)->postJson("/api/v1/inventory/adjustments/{$id}/reject", ['reason' => 'Not mine to decide'])->assertForbidden()->assertJsonPath('code', 'SOD_VIOLATION');

    rtSwitch($this, $this->t, 'CSA');
    $posted = adApprove($this, $id)->assertCreated()->assertJsonPath('data.docNo', "ADJ-P1-{$year}-0001")->json('data');
    adApprove($this, $id)->assertOk()->assertJsonPath('data.documentId', $posted['documentId'])->assertJsonPath('data.replayed', true);
    adApprove($this, $id, 'approve-key-0002')->assertStatus(409)->assertJsonPath('code', 'INVALID_STATE_TRANSITION');
    expect(adStock($this, $f['lotId']))->toBe(['RM-01 BLOCKED' => '7']);
    // one key, one command: the approval's key never submits a request, a request's key never posts or approves
    rtSwitch($this, $this->t, 'INV', ['PLANT', $f['plant']]);
    adRequest($this, $f, [], 'approve-key-0001')->assertStatus(409)->assertJsonPath('code', 'IDEMPOTENCY_KEY_REUSED');
    $next = adRequest($this, $f, ['qty' => '1'], 'adjust-key-0009')->assertCreated()->json('data.documentId');
    rtSwitch($this, $this->t, 'MAT');
    trMove($this, $f + ['lotId' => $f['lotId']], [], 'adjust-key-0009')->assertStatus(409)->assertJsonPath('code', 'IDEMPOTENCY_KEY_REUSED');
    rtSwitch($this, $this->t, 'CSA');
    adApprove($this, $next, 'adjust-key-0001')->assertStatus(409)->assertJsonPath('code', 'IDEMPOTENCY_KEY_REUSED');
    // the approver reads the requests it decides without the requesters' view permission
    spa($this)->getJson('/api/v1/query/inventory.adjustments?filter[status]=POSTED')->assertOk()
        ->assertJsonPath('data.0.status', 'POSTED')->assertJsonPath('data.0.code', "ADJ-P1-{$year}-0001")->assertJsonPath('data.0.decidedByName', fn ($n) => $n !== null);
    expect(rcRun($this->t, fn (EoraPostgresConnection $db) => $db->select(
        'select movement_code, l.location_type, trim_scale(qty_signed)::text as q from inventory_movements m join locations l on l.id = m.location_id where document_id = ? order by leg_no', [$id],
    )))->sequence(
        fn ($leg) => $leg->movement_code->toBe('ADJ_MINUS')->location_type->toBe('RM_WAREHOUSE')->q->toBe('-3'),
        fn ($leg) => $leg->movement_code->toBe('ADJ_MINUS')->location_type->toBe('V_ADJUSTMENT')->q->toBe('3'),
    );
})->group('integration', 'inventory');

it('rejects a request with its reason and moves nothing; stock is checked again at approval', function () {
    $f = adLot($this, $this->t, $this->f);
    // found oil: an item without lots, added at another rack
    $found = adRequest($this, $f, ['itemId' => $f['oil'], 'lotId' => null, 'locationId' => $f['rm2'], 'stockStatus' => 'UNRESTRICTED',
        'direction' => 'PLUS', 'qty' => '5', 'reasonCode' => 'FOUND', 'note' => null], 'adjust-key-0002')->assertCreated()->json('data.documentId');
    $first = adRequest($this, $f, ['qty' => '6'], 'adjust-key-0003')->assertCreated()->json('data.documentId');
    $second = adRequest($this, $f, ['qty' => '6'], 'adjust-key-0004')->assertCreated()->json('data.documentId');

    rtSwitch($this, $this->t, 'CSA');
    spa($this)->postJson("/api/v1/inventory/adjustments/{$found}/reject", ['reason' => 'x'])->assertStatus(422)->assertJsonValidationErrors(['reason']);
    spa($this)->postJson("/api/v1/inventory/adjustments/{$found}/reject", ['reason' => 'No count sheet attached'])->assertNoContent();
    spa($this)->postJson("/api/v1/inventory/adjustments/{$found}/reject", ['reason' => 'No count sheet attached'])->assertNoContent();
    adApprove($this, $found, 'approve-key-0011')->assertStatus(409)->assertJsonPath('code', 'INVALID_STATE_TRANSITION');
    spa($this)->getJson('/api/v1/query/inventory.adjustments?filter[status]=REJECTED')->assertOk()
        ->assertJsonPath('data.0.id', $found)->assertJsonPath('data.0.rejectReason', 'No count sheet attached')->assertJsonPath('data.0.direction', 'PLUS');
    expect(spa($this)->getJson('/api/v1/query/inventory.stock?filter[itemId]='.$f['oil'])->assertOk()->json('data'))->toBe([]);

    // two requests for 6 of 10: the second finds only 4 at approval and stays waiting
    adApprove($this, $first, 'approve-key-0012')->assertCreated();
    adApprove($this, $second, 'approve-key-0013')->assertStatus(409)->assertJsonPath('code', 'INSUFFICIENT_STOCK');
    expect(adStock($this, $f['lotId']))->toBe(['RM-01 BLOCKED' => '4']);
    expect(rcRun($this->t, fn (EoraPostgresConnection $db) => $db->selectOne('select status from inventory_documents where id = ?', [$second])->status))->toBe('SUBMITTED');
})->group('integration', 'inventory');

it('requests only valid lines: reason, lot, place, unit and what is on hand (D-100)', function () {
    $f = adLot($this, $this->t, $this->f);

    adRequest($this, $f, ['reasonCode' => 'OTHER', 'note' => null], 'adjust-key-0021')->assertStatus(422)
        ->assertJsonPath('errors.note', ['Explain the adjustment when the reason is Other.']);
    adRequest($this, $f, ['reasonCode' => 'THEFT'], 'adjust-key-0022')->assertStatus(422)->assertJsonValidationErrors(['reasonCode']);
    adRequest($this, $f, ['lotId' => null], 'adjust-key-0023')->assertStatus(422)->assertJsonValidationErrors(['lotId']);
    adRequest($this, $f, ['itemId' => $f['oil']], 'adjust-key-0024')->assertStatus(422)->assertJsonValidationErrors(['lotId']);
    adRequest($this, $f, ['locationId' => $f['supplierLoc']], 'adjust-key-0025')->assertStatus(422)->assertJsonValidationErrors(['locationId']);
    adRequest($this, $f, ['locationId' => $f['other']], 'adjust-key-0026')->assertStatus(422)->assertJsonValidationErrors(['locationId']);
    adRequest($this, $f, ['qty' => '1.5'], 'adjust-key-0027')->assertStatus(422)->assertJsonPath('errors.qty', ['Enter a whole number of PCS.']);
    adRequest($this, $f, ['qty' => '11'], 'adjust-key-0028')->assertStatus(422)->assertJsonPath('errors.qty', ['Only 10 PCS is on hand there.']);
    adRequest($this, $f, ['direction' => 'SIDEWAYS'], 'adjust-key-0029')->assertStatus(422)->assertJsonValidationErrors(['direction']);
    spa($this)->getJson('/api/v1/query/inventory.adjustments')->assertOk()->assertJsonPath('data', []);
})->group('integration', 'inventory');

it('requests with inventory.adjustment.create and decides with inventory.adjustment.approve, inside the plant (doc 18)', function () {
    $f = adLot($this, $this->t, $this->f);
    $id = adRequest($this, $f)->assertCreated()->json('data.documentId');

    foreach (['MAT', 'QCI', 'PRL'] as $role) {
        rtSwitch($this, $this->t, $role);
        adRequest($this, $f, [], 'adjust-key-0031')->assertForbidden();
        adApprove($this, $id)->assertForbidden();
    }
    rtSwitch($this, $this->t, 'INV', ['PLANT', $f['plant2']]);
    adRequest($this, $f, [], 'adjust-key-0032')->assertForbidden();
    rtSwitch($this, $this->t, 'INV', ['PLANT', $f['plant']]);
    adApprove($this, $id)->assertForbidden();
    rtSwitch($this, $this->t, 'CSA', ['PLANT', $f['plant2']]);
    adApprove($this, $id)->assertForbidden();
    spa($this)->getJson('/api/v1/query/inventory.adjustments')->assertOk()->assertJsonPath('data', []);
    adApprove($this, '00000000-0000-7000-8000-000000000000')->assertNotFound();
    rtSwitch($this, $this->t, 'CSA', ['PLANT', $f['plant']]);
    adApprove($this, $id)->assertCreated();
})->group('integration', 'inventory');
