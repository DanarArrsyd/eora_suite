<?php

declare(strict_types=1);

use App\Modules\Inventory\Application\InventoryPostingService;
use App\Modules\Inventory\Contracts\PostingCommand;
use App\Modules\Inventory\Contracts\PostingLeg;
use App\Platform\Database\EoraPostgresConnection;
use App\Platform\Exceptions\DomainProblem;
use Illuminate\Database\QueryException;
use Tests\Support\AccessFixtures;
use Tests\Support\TenantFixtures;

/*
 * M4 step 5 (doc 33 §1.5, D-113, doc 04 row 9, BR-QC-01..03, doc 14 #6, #20): an inspector decides part or all of a lot
 * waiting at the QC area; releases out of policy are asked and decided by a second person.
 */

beforeEach(function () {
    $this->t = TenantFixtures::provision(2);
    AccessFixtures::roles($this->t);
});

/**
 * prFixture with an item that needs inspection; the production leader posts 90 good (PENDING_QC), 5 rework, 3 reject.
 * Ends signed in as an inspector (QCI).
 *
 * @return array<string, mixed>
 */
function qcFixture(object $test, array $t): array
{
    $f = prFixture($test, $t);
    AccessFixtures::run($t, fn (EoraPostgresConnection $db) => $db->update('update items set inspection_required = true where id = ?', [$f['item']['id']]));
    $run = prOpen($test, $f)->assertCreated()->json('data');
    $posted = prPost($test, $f, $run['id'])->assertCreated()->json('data');
    $line = fn (string $type, string $status) => (string) AccessFixtures::run($t, fn (EoraPostgresConnection $db) => $db->selectOne(
        'select b.id from stock_balances b join locations l on l.id = b.location_id where b.item_id = ? and l.location_type = ? and b.stock_status = ?',
        [$f['item']['id'], $type, $status],
    )->id);
    $qci = rtSwitch($test, $t, 'QCI');

    return $f + ['run' => $run, 'posted' => $posted, 'pending' => $line('QC_AREA', 'PENDING_QC'), 'line' => $line, 'qci' => $qci];
}

function qcDispose(object $test, string $line, array $lines, string $key = 'qc-dispose-0001')
{
    return spa($test)->postJson('/api/v1/quality/dispositions', ['stockLineId' => $line, 'lines' => $lines], ['Idempotency-Key' => $key]);
}

it('releases, holds, reworks and rejects parts of a lot; the rest keeps waiting (BR-QC-01, doc 14 #6)', function () {
    $f = qcFixture($this, $this->t);
    $year = substr($f['today'], 0, 4);
    expect(prStock($this->t, $f['item']['id']))->toBe(['NG-01 / REJECTED' => '3', 'NG-01 / REWORK' => '5', 'QC-01 / PENDING_QC' => '90']);
    $lines = [
        ['outcome' => 'RELEASE', 'qty' => '60'],
        ['outcome' => 'HOLD', 'qty' => '10', 'reasonCode' => 'DIMENSION', 'note' => 'Hole 0.2 mm off'],
        ['outcome' => 'REWORK', 'qty' => '10', 'reasonCode' => 'BURR'],
        ['outcome' => 'REJECT', 'qty' => '8', 'reasonCode' => 'CRACK'],
    ];
    // a hold, rework or reject gives its reason; Other explains it; never more than waits
    qcDispose($this, $f['pending'], [['outcome' => 'HOLD', 'qty' => '1']], 'qc-dispose-0009')->assertStatus(422)->assertJsonValidationErrors(['lines.0.reasonCode']);
    qcDispose($this, $f['pending'], [['outcome' => 'REJECT', 'qty' => '1', 'reasonCode' => 'OTHER']], 'qc-dispose-0008')->assertStatus(422)->assertJsonValidationErrors(['lines.0.note']);
    qcDispose($this, $f['pending'], [['outcome' => 'RELEASE', 'qty' => '91']], 'qc-dispose-0007')->assertStatus(409)->assertJsonPath('code', 'INSUFFICIENT_STOCK');

    $posted = qcDispose($this, $f['pending'], $lines)->assertCreated()->assertJsonPath('data.docNo', "QCD-P1-{$year}-0001")->json('data');
    qcDispose($this, $f['pending'], $lines)->assertOk()->assertJsonPath('data.documentId', $posted['documentId']);
    expect(prStock($this->t, $f['item']['id']))->toBe([
        'NG-01 / REJECTED' => '11', 'NG-01 / REWORK' => '15', 'QC-01 / HOLD' => '10', 'QC-01 / PENDING_QC' => '2', 'QC-01 / QC_RELEASED' => '60',
    ]);
    spa($this)->getJson('/api/v1/query/quality.dispositions')->assertOk()->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.releasedQty', '60')->assertJsonPath('data.0.holdQty', '10')->assertJsonPath('data.0.reworkQty', '10')
        ->assertJsonPath('data.0.rejectQty', '8')->assertJsonPath('data.0.fromStatus', 'PENDING_QC')->assertJsonPath('data.0.outOfPolicy', false);

    // from HOLD only reject in policy (BR-QC-02); its release is asked of a second person
    $hold = ($f['line'])('QC_AREA', 'HOLD');
    qcDispose($this, $hold, [['outcome' => 'RELEASE', 'qty' => '1']], 'qc-dispose-0002')->assertStatus(422)->assertJsonValidationErrors(['lines.0.outcome']);
    qcDispose($this, $hold, [['outcome' => 'REJECT', 'qty' => '4', 'reasonCode' => 'DIMENSION']], 'qc-dispose-0003')->assertCreated();
    expect(prStock($this->t, $f['item']['id'])['QC-01 / HOLD'])->toBe('6');
})->group('integration', 'quality');

it('asks a release out of policy and lets a second person decide it (D-060, D-113)', function () {
    $f = qcFixture($this, $this->t);
    qcDispose($this, $f['pending'], [['outcome' => 'HOLD', 'qty' => '10', 'reasonCode' => 'VISUAL']])->assertCreated();
    $hold = ($f['line'])('QC_AREA', 'HOLD');
    $ask = fn (string $line, string $qty, string $key) => spa($this)->postJson('/api/v1/quality/release-requests',
        ['stockLineId' => $line, 'qty' => $qty, 'reason' => 'Customer accepted the deviation'], ['Idempotency-Key' => $key]);
    $ask($f['pending'], '1', 'qc-ask-0009')->assertStatus(422)->assertJsonValidationErrors(['stockLineId']);
    $asked = $ask($hold, '10', 'qc-ask-0001')->assertCreated()->assertJsonPath('data.status', 'SUBMITTED')->json('data.documentId');
    expect(prStock($this->t, $f['item']['id'])['QC-01 / HOLD'])->toBe('10');
    // who asked never decides; an inspector cannot approve either
    spa($this)->postJson("/api/v1/quality/release-requests/{$asked}/approve", [], ['Idempotency-Key' => 'qc-approve-0001'])->assertForbidden();

    rtSwitch($this, $this->t, 'QCS');
    spa($this)->postJson("/api/v1/quality/release-requests/{$asked}/approve", [], ['Idempotency-Key' => 'qc-approve-0002'])->assertCreated();
    expect(prStock($this->t, $f['item']['id']))->toBe(['NG-01 / REJECTED' => '3', 'NG-01 / REWORK' => '5', 'QC-01 / PENDING_QC' => '80', 'QC-01 / QC_RELEASED' => '10']);

    // a rejected quantity back to released, also only through a request; a second one is rejected with its reason
    signIn($this, $f['qci'], $this->t);
    $rejected = ($f['line'])('NG_AREA', 'REJECTED');
    $asked2 = $ask($rejected, '2', 'qc-ask-0002')->assertCreated()->json('data.documentId');
    rtSwitch($this, $this->t, 'QCS');
    spa($this)->postJson("/api/v1/quality/release-requests/{$asked2}/reject", ['reason' => 'Crack confirmed'])->assertNoContent();
    spa($this)->getJson("/api/v1/query/quality.dispositions?filter[id]={$asked2}")->assertOk()->assertJsonPath('data.0.status', 'REJECTED')
        ->assertJsonPath('data.0.outOfPolicy', true)->assertJsonPath('data.0.releasedQty', '2')->assertJsonPath('data.0.rejectReason', 'Crack confirmed');
    expect(prStock($this->t, $f['item']['id'])['NG-01 / REJECTED'])->toBe('3');
})->group('integration', 'quality');

it('refuses the output reporter and out-of-policy shortcuts, in the database too (SoD-01, BR-QC-02/03)', function () {
    $f = qcFixture($this, $this->t);
    // the production leader who reported the output, also given the inspector role, does not decide its quality
    AccessFixtures::grant($this->t, $f['prl']['id'], 'QCI');
    signIn($this, $f['prl'], $this->t);
    qcDispose($this, $f['pending'], [['outcome' => 'RELEASE', 'qty' => '1']])->assertStatus(403)->assertJsonPath('code', 'SOD_VIOLATION');

    $qc = AccessFixtures::run($this->t, fn (EoraPostgresConnection $db) => (string) $db->selectOne("select id from locations where code = 'QC-01'")->id);
    $lot = $f['posted']['lotId'];
    $post = fn (string $actor, array $legs, string $key) => asApp($this->t, $actor, fn (EoraPostgresConnection $db) => app(InventoryPostingService::class)->post($db,
        new PostingCommand('QC_DISPOSITION', $f['plant'], $key, $legs)));
    $release = fn (string $from) => [
        new PostingLeg('QC_RELEASE', $qc, $f['item']['id'], $lot, $from, '-1'),
        new PostingLeg('QC_RELEASE', $qc, $f['item']['id'], $lot, 'QC_RELEASED', '1'),
    ];
    $refused = function (Closure $attempt, string $why) {
        try {
            $attempt();
            $this->fail("posted: {$why}");
        } catch (DomainProblem $e) {
            expect($e->errorCode)->toBe('POSTING_REFUSED', $why);
        } catch (QueryException $e) {
            expect($e->getCode())->toBeIn(['23514', '42501'], $why);
        }
    };
    $refused(fn () => $post((string) $f['prl']['id'], $release('PENDING_QC'), 'qc-direct-0001'), 'released by the output reporter');
    $qci = (string) $f['qci']['id'];
    signIn($this, $f['qci'], $this->t);
    qcDispose($this, $f['pending'], [['outcome' => 'HOLD', 'qty' => '5', 'reasonCode' => 'BURR']], 'qc-dispose-0002')->assertCreated();
    $refused(fn () => $post($qci, $release('HOLD'), 'qc-direct-0002'), 'a hold released without approval');
    $refused(fn () => $post($qci, [
        new PostingLeg('QC_HOLD', $qc, $f['item']['id'], $lot, 'PENDING_QC', '-1'),
        new PostingLeg('QC_HOLD', $qc, $f['item']['id'], $lot, 'HOLD', '1'),
    ], 'qc-direct-0003'), 'a hold without its reason');
    $refused(fn () => $post((string) $f['mat']['id'], $release('PENDING_QC'), 'qc-direct-0004'), 'released by materials');
    expect(prStock($this->t, $f['item']['id'])['QC-01 / PENDING_QC'])->toBe('85');
})->group('integration', 'quality');
