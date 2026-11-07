<?php

declare(strict_types=1);

use App\Platform\Database\EoraPostgresConnection;
use Tests\Support\AccessFixtures;
use Tests\Support\TenantFixtures;

/*
 * M3 step 7 (doc 32 §1.7, doc 04 row 13, D-060, D-101): reversal through the HTTP API — anyone who may request corrections
 * in the plant asks with a reason, a second person approves (posts) or rejects; every leg is negated with its own code.
 */

beforeEach(function () {
    $this->t = TenantFixtures::provision(2);
    AccessFixtures::roles($this->t);
    $this->f = rcFixture($this->t);
});

function rvRequest(object $test, string $document, string $key = 'reverse-key-0001', string $reason = 'Wrong rack keyed in')
{
    return spa($test)->postJson('/api/v1/inventory/reversals', ['documentId' => $document, 'reason' => $reason], ['Idempotency-Key' => $key]);
}

function rvApprove(object $test, string $id, string $key = 'reverse-approve-0001')
{
    return spa($test)->postJson("/api/v1/inventory/reversals/{$id}/approve", [], ['Idempotency-Key' => $key]);
}

function rvStatus(array $t, string $id): string
{
    return rcRun($t, fn (EoraPostgresConnection $db) => (string) $db->selectOne('select status from inventory_documents where id = ?', [$id])->status);
}

it('reverses a posted receipt once a second person approves, negating every leg with its own code (doc 04 row 13)', function () {
    orgUser($this, $this->t, 'MAT');
    $receipt = rcReceive($this, $this->f)->assertCreated()->json('data');
    $year = substr($this->f['today'], 0, 4);

    $id = rvRequest($this, $receipt['documentId'])->assertCreated()->assertJsonPath('data.status', 'SUBMITTED')->json('data.documentId');
    rvRequest($this, $receipt['documentId'])->assertOk()->assertJsonPath('data.documentId', $id)->assertJsonPath('data.replayed', true);
    rvRequest($this, $receipt['documentId'], 'reverse-key-0002')->assertStatus(409)->assertJsonPath('code', 'REVERSAL_PENDING');
    expect(trStock($this, $receipt['lotId']))->toBe(['RM-01 BLOCKED' => '10']);
    rvApprove($this, $id)->assertForbidden();

    rtSwitch($this, $this->t, 'INV', ['PLANT', $this->f['plant']]);
    $posted = rvApprove($this, $id)->assertCreated()->assertJsonPath('data.docNo', "REV-P1-{$year}-0001")->json('data');
    rvApprove($this, $id)->assertOk()->assertJsonPath('data.replayed', true)->assertJsonPath('data.documentId', $posted['documentId']);
    expect(trStock($this, $receipt['lotId']))->toBe([])
        ->and(rvStatus($this->t, $receipt['documentId']))->toBe('REVERSED');
    $legs = collect(spa($this)->getJson("/api/v1/query/inventory.movements?filter[documentId]={$id}")->assertOk()->json('data'));
    expect($legs->pluck('movementCode')->unique()->values()->all())->toBe(['RCPT_SUPPLIER'])
        ->and($legs->pluck('qty')->all())->toBe(['10', '-10'])
        ->and($legs->every(fn ($l) => $l['reversesMovementId'] !== null))->toBeTrue();
    spa($this)->getJson("/api/v1/query/inventory.documents?filter[id]={$receipt['documentId']}")->assertOk()
        ->assertJsonPath('data.0.status', 'REVERSED')->assertJsonPath('data.0.reversalCode', "REV-P1-{$year}-0001")->assertJsonPath('data.0.reversible', false);

    // a reversal is never reversed, a reversed document never twice
    rvRequest($this, $id, 'reverse-key-0003')->assertStatus(409)->assertJsonPath('code', 'INVALID_STATE_TRANSITION');
    rvRequest($this, $receipt['documentId'], 'reverse-key-0004')->assertStatus(409)->assertJsonPath('code', 'INVALID_STATE_TRANSITION');
})->group('integration', 'inventory');

it('refuses a reversal once the stock has moved on, until what came after is reversed (REVERSAL_BLOCKED_BY_DOWNSTREAM)', function () {
    orgUser($this, $this->t, 'MAT');
    $receipt = rcReceive($this, $this->f)->assertCreated()->json('data');
    $transfer = trMove($this, $this->f + ['lotId' => $receipt['lotId']])->assertCreated()->json('data.documentId');
    $blocked = rvRequest($this, $receipt['documentId'])->assertCreated()->json('data.documentId');
    $first = rvRequest($this, $transfer, 'reverse-key-0011')->assertCreated()->json('data.documentId');

    rtSwitch($this, $this->t, 'CSA');
    rvApprove($this, $blocked)->assertStatus(409)->assertJsonPath('code', 'REVERSAL_BLOCKED_BY_DOWNSTREAM');
    expect(rvStatus($this->t, $blocked))->toBe('SUBMITTED');
    rvApprove($this, $first, 'reverse-approve-0011')->assertCreated();
    expect(trStock($this, $receipt['lotId']))->toBe(['RM-01 BLOCKED' => '10']);
    rvApprove($this, $blocked, 'reverse-approve-0012')->assertCreated();
    expect(trStock($this, $receipt['lotId']))->toBe([]);

    // a posted reversal is decided: rejecting it now is refused
    spa($this)->postJson("/api/v1/inventory/reversals/{$first}/reject", ['reason' => 'Already posted'])->assertStatus(409)->assertJsonPath('code', 'INVALID_STATE_TRANSITION');
})->group('integration', 'inventory');

it('reverses a lot split while its children are untouched, and an approved adjustment', function () {
    orgUser($this, $this->t, 'MAT');
    $lot = rcReceive($this, $this->f)->assertCreated()->json('data.lotId');
    rtSwitch($this, $this->t, 'PRL');
    $split = lsSplit($this, $this->f + ['lotId' => $lot], ['children' => [['qty' => '3']]])->assertCreated()->json('data.documentId');
    $id = rvRequest($this, $split, 'reverse-key-0021', 'Cut at the wrong length')->assertCreated()->json('data.documentId');
    rtSwitch($this, $this->t, 'INV', ['PLANT', $this->f['plant']]);
    rvApprove($this, $id, 'reverse-approve-0021')->assertCreated();
    expect(trStock($this, $lot))->toBe(['RM-01 BLOCKED' => '10']);

    $adjustment = adRequest($this, $this->f + ['lotId' => $lot], ['qty' => '2'], 'adjust-key-0021')->assertCreated()->json('data.documentId');
    rtSwitch($this, $this->t, 'CSA');
    adApprove($this, $adjustment, 'approve-key-0021')->assertCreated();
    $undo = rvRequest($this, $adjustment, 'reverse-key-0022', 'Damage was on another coil')->assertCreated()->json('data.documentId');
    rtSwitch($this, $this->t, 'INV', ['PLANT', $this->f['plant']]);
    rvApprove($this, $undo, 'reverse-approve-0022')->assertCreated();
    expect(trStock($this, $lot))->toBe(['RM-01 BLOCKED' => '10']);
})->group('integration', 'inventory');

it('asks with request_correction in the plant and decides with approve, never the requester (doc 18, D-101)', function () {
    orgUser($this, $this->t, 'MAT');
    $receipt = rcReceive($this, $this->f)->assertCreated()->json('data.documentId');

    rtSwitch($this, $this->t, 'OPR');
    rvRequest($this, $receipt)->assertForbidden();
    rtSwitch($this, $this->t, 'QCI', ['PLANT', $this->f['plant2']]);
    rvRequest($this, $receipt)->assertForbidden();
    $requester = rtSwitch($this, $this->t, 'INV', ['PLANT', $this->f['plant']]);
    $id = rvRequest($this, $receipt)->assertCreated()->json('data.documentId');
    rvApprove($this, $id)->assertForbidden()->assertJsonPath('code', 'SOD_VIOLATION');
    spa($this)->postJson("/api/v1/inventory/reversals/{$id}/reject", ['reason' => 'Not mine'])->assertForbidden()->assertJsonPath('code', 'SOD_VIOLATION');

    rtSwitch($this, $this->t, 'AUD');
    spa($this)->getJson('/api/v1/query/inventory.reversals')->assertOk()->assertJsonPath('data.0.id', $id)->assertJsonPath('data.0.originalType', 'RM_RECEIPT');
    rvApprove($this, $id)->assertForbidden();
    rtSwitch($this, $this->t, 'QCI', ['PLANT', $this->f['plant']]);
    spa($this)->getJson('/api/v1/query/inventory.reversals')->assertOk()->assertJsonPath('data.0.status', 'SUBMITTED');
    rtSwitch($this, $this->t, 'CSA');
    spa($this)->postJson("/api/v1/inventory/reversals/{$id}/reject", ['reason' => 'The receipt is right'])->assertNoContent();
    expect(rvStatus($this->t, $id))->toBe('REJECTED');
    rvApprove($this, '00000000-0000-7000-8000-000000000000')->assertNotFound();
    // rejected: the document may be asked for again
    rtSwitch($this, $this->t, 'MAT');
    rvRequest($this, $receipt, 'reverse-key-0031')->assertCreated();
    expect($requester)->not->toBeEmpty();
})->group('integration', 'inventory');
