<?php

declare(strict_types=1);

use App\Platform\Database\EoraPostgresConnection;
use Tests\Support\AccessFixtures;
use Tests\Support\TenantFixtures;

/*
 * M3 step 8 (doc 32 §1.8, M3-D8, BR-PER-01/02/03, D-102): monthly periods through the HTTP API — a past month is closed
 * on a request decided by a second person, a closed month takes no postings, a document of a closed month is corrected
 * in the open period with evidence, and a reopen is an exceptional two-person event.
 */

beforeEach(function () {
    $this->t = TenantFixtures::provision(2);
    AccessFixtures::roles($this->t);
    $this->f = rcFixture($this->t);
    $this->f['lastMonth'] = (new DateTimeImmutable($this->f['today']))->modify('first day of last month')->format('Y-m');
});

function pdPeriod(array $t, string $plant, string $month): object
{
    return rcRun($t, fn (EoraPostgresConnection $db) => $db->selectOne(
        "select id, status from inventory_periods where plant_id = ? and to_char(period, 'YYYY-MM') = ?", [$plant, $month],
    ));
}

function pdClose(object $test, array $f, ?string $month = null)
{
    return spa($test)->postJson('/api/v1/inventory/periods/close-requests', ['plantId' => $f['plant'], 'period' => $month ?? $f['lastMonth']]);
}

it('closes a past month on a request decided by a second person; a closing or closed month takes no postings (BR-PER-01)', function () {
    $f = $this->f;
    orgUser($this, $this->t, 'MAT');
    rcReceive($this, $f, ['effectiveDate' => $f['lastMonth'].'-15'])->assertCreated();

    rtSwitch($this, $this->t, 'INV', ['PLANT', $f['plant']]);
    pdClose($this, $f, substr($f['today'], 0, 7))->assertStatus(422)->assertJsonValidationErrors(['period']);
    $id = pdClose($this, $f)->assertCreated()->assertJsonPath('data.status', 'CLOSING')->assertJsonPath('data.period', $f['lastMonth'])->json('data.id');
    pdClose($this, $f)->assertStatus(409)->assertJsonPath('code', 'INVALID_STATE_TRANSITION');
    spa($this)->postJson("/api/v1/inventory/periods/{$id}/close")->assertForbidden();

    rtSwitch($this, $this->t, 'MAT');
    rcReceive($this, $f, ['effectiveDate' => $f['lastMonth'].'-20'], 'receipt-key-0002')->assertStatus(409)->assertJsonPath('code', 'PERIOD_CLOSED');
    pdClose($this, $f)->assertForbidden();

    rtSwitch($this, $this->t, 'CSA');
    spa($this)->postJson("/api/v1/inventory/periods/{$id}/close")->assertOk()->assertJsonPath('data.status', 'CLOSED');
    spa($this)->getJson('/api/v1/query/inventory.periods?filter[status]=CLOSED')->assertOk()
        ->assertJsonPath('data.0.code', $f['lastMonth'])->assertJsonPath('data.0.closedByName', fn ($n) => $n !== null);
    expect(pdPeriod($this->t, $f['plant'], $f['lastMonth'])->status)->toBe('CLOSED');
})->group('integration', 'inventory');

it('never closes a month with requests still waiting, and lets a close be withdrawn or rejected with a reason', function () {
    $f = $this->f;
    $inv = orgUser($this, $this->t, 'INV', ['PLANT', $f['plant']]);
    // a request of last month still waiting (made then; the request day is part of the request)
    rcRun($this->t, fn (EoraPostgresConnection $db) => $db->insert(
        "insert into inventory_documents (tenant_id, plant_id, doc_type, status, effective_date, requested_by, submitted_at, reason_code, note, request, idempotency_key)
         values (?, ?, 'STOCK_ADJUSTMENT', 'SUBMITTED', ?::date, (select c.actor from ctx_api.context() c), now(), 'FOUND', 'Found in the corner', '{}'::jsonb, 'pending-last-month')",
        [$this->t['tenant'], $f['plant'], $f['lastMonth'].'-10'],
    ));
    pdClose($this, $f)->assertStatus(409)->assertJsonPath('code', 'PERIOD_HAS_PENDING_REQUESTS');
    rcRun($this->t, fn (EoraPostgresConnection $db) => $db->update("update inventory_documents set status = 'DRAFT' where idempotency_key = 'pending-last-month'"));

    $id = pdClose($this, $f)->assertCreated()->json('data.id');
    // its requester withdraws it without a reason
    spa($this)->postJson("/api/v1/inventory/periods/{$id}/close-return")->assertOk()->assertJsonPath('data.status', 'OPEN');
    pdClose($this, $f)->assertCreated();
    rtSwitch($this, $this->t, 'CSA');
    spa($this)->postJson("/api/v1/inventory/periods/{$id}/close-return")->assertStatus(422)->assertJsonValidationErrors(['reason']);
    spa($this)->postJson("/api/v1/inventory/periods/{$id}/close-return", ['reason' => 'Counts not reconciled yet'])->assertOk()->assertJsonPath('data.status', 'OPEN');
    expect($inv)->not->toBeEmpty();
})->group('integration', 'inventory');

it('corrects a document of a closed month in the open period, with its evidence (BR-PER-02)', function () {
    $f = $this->f;
    orgUser($this, $this->t, 'MAT');
    $receipt = rcReceive($this, $f, ['effectiveDate' => $f['lastMonth'].'-15'])->assertCreated()->json('data');
    rtSwitch($this, $this->t, 'INV', ['PLANT', $f['plant']]);
    $id = pdClose($this, $f)->assertCreated()->json('data.id');
    rtSwitch($this, $this->t, 'CSA');
    spa($this)->postJson("/api/v1/inventory/periods/{$id}/close")->assertOk();

    rtSwitch($this, $this->t, 'MAT');
    rvRequest($this, $receipt['documentId'])->assertStatus(422)->assertJsonValidationErrors(['evidence']);
    $correction = spa($this)->postJson('/api/v1/inventory/reversals', ['documentId' => $receipt['documentId'], 'reason' => 'Supplier sent the wrong coil', 'evidence' => 'BA-2026-114'],
        ['Idempotency-Key' => 'reverse-key-0002'])->assertCreated()->json('data.documentId');
    rtSwitch($this, $this->t, 'INV', ['PLANT', $f['plant']]);
    rvApprove($this, $correction)->assertCreated();
    expect(rcRun($this->t, fn (EoraPostgresConnection $db) => $db->selectOne(
        "select correction_of_document_id as corrects, original_effective_date::text as original, reason_code, request ->> 'evidence' as evidence,
                to_char(effective_date, 'YYYY-MM') as month from inventory_documents where id = ?", [$correction],
    )))->corrects->toBe($receipt['documentId'])->original->toBe($f['lastMonth'].'-15')->reason_code->toBe('CLOSED_PERIOD')
        ->evidence->toBe('BA-2026-114')->month->toBe(substr($f['today'], 0, 7));
    expect(trStock($this, $receipt['lotId']))->toBe([]);
})->group('integration', 'inventory');

it('reopens a closed month only on a CSA request decided by another approver, an exceptional event (BR-PER-03)', function () {
    $f = $this->f;
    orgUser($this, $this->t, 'INV', ['PLANT', $f['plant']]);
    $id = pdClose($this, $f)->assertCreated()->json('data.id');
    rtSwitch($this, $this->t, 'CSA');
    spa($this)->postJson("/api/v1/inventory/periods/{$id}/close")->assertOk();

    rtSwitch($this, $this->t, 'INV', ['PLANT', $f['plant']]);
    spa($this)->postJson("/api/v1/inventory/periods/{$id}/reopen-requests", ['reason' => 'Late supplier invoice'])->assertForbidden();
    rtSwitch($this, $this->t, 'CSA');
    spa($this)->postJson("/api/v1/inventory/periods/{$id}/reopen-requests", ['reason' => 'x'])->assertStatus(422);
    spa($this)->postJson("/api/v1/inventory/periods/{$id}/reopen-requests", ['reason' => 'Receipt of the 28th was missed'])->assertOk()->assertJsonPath('data.status', 'CLOSED');
    spa($this)->postJson("/api/v1/inventory/periods/{$id}/reopen")->assertForbidden()->assertJsonPath('code', 'SOD_VIOLATION');

    // another CSA rejects it with the reason, the month stays closed; asked again, a second CSA reopens it
    rtSwitch($this, $this->t, 'CSA');
    spa($this)->postJson("/api/v1/inventory/periods/{$id}/reopen-return", ['reason' => 'Post it as a correction instead'])->assertOk()->assertJsonPath('data.status', 'CLOSED');
    spa($this)->postJson("/api/v1/inventory/periods/{$id}/reopen")->assertStatus(409)->assertJsonPath('code', 'INVALID_STATE_TRANSITION');
    spa($this)->postJson("/api/v1/inventory/periods/{$id}/reopen-requests", ['reason' => 'Receipt of the 28th was missed'])->assertOk();
    rtSwitch($this, $this->t, 'CSA');
    spa($this)->postJson("/api/v1/inventory/periods/{$id}/reopen")->assertOk()->assertJsonPath('data.status', 'OPEN');
    expect(rcRun($this->t, fn (EoraPostgresConnection $db) => $db->selectOne(
        "select count(*) filter (where action = 'inventory_period.reopened') as reopened, count(*) filter (where action = 'inventory_period.reopen_returned') as returned
           from audit_events where entity_id = ?", [$id],
    )))->reopened->toBe(1)->returned->toBe(1);

    rtSwitch($this, $this->t, 'AUD');
    spa($this)->getJson('/api/v1/query/inventory.periods')->assertOk()->assertJsonPath('data.0.status', 'OPEN')->assertJsonPath('data.0.reopenedByName', fn ($n) => $n !== null);
    spa($this)->postJson("/api/v1/inventory/periods/{$id}/close")->assertForbidden();
})->group('integration', 'inventory');
