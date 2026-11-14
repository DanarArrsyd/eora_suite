<?php

declare(strict_types=1);

use App\Modules\Inventory\Application\InventoryPostingService;
use App\Platform\Database\EoraPostgresConnection;
use App\Platform\Exceptions\DomainProblem;
use App\Platform\Tenancy\TenantContext;
use App\Platform\Tenancy\TenantTransaction;
use Illuminate\Database\QueryException;
use Tests\Support\AccessFixtures;
use Tests\Support\TenantFixtures;

/*
 * M4 step 8 (doc 33 §1.8, D-063, D-117, BR-PLN-03): PPC closes a work order once no run is open and nothing is left at
 * production; material that is not returned is scrapped by an exception asked by PPC and decided by CSA/INV.
 */

beforeEach(function () {
    $this->t = TenantFixtures::provision(2);
    AccessFixtures::roles($this->t);
});

/**
 * prFixture with one run posted (12 KG of coil left at PRS-01, nuts all consumed), the run still open; the materials user
 * also plans (PPC) and the production leader also approves (CSA). Ends signed in as the planner.
 *
 * @return array<string, mixed>
 */
function wcFixture(object $test, array $t): array
{
    $f = prFixture($test, $t);
    $run = prOpen($test, $f)->assertCreated()->json('data');
    prPost($test, $f, $run['id'])->assertCreated();
    AccessFixtures::grant($t, $f['mat']['id'], 'PPC');
    AccessFixtures::grant($t, $f['prl']['id'], 'CSA');
    signIn($test, $f['mat'], $t);

    return $f + ['run' => $run];
}

function wcOrder(object $test, array $f): array
{
    return spa($test)->getJson("/api/v1/planning/work-orders/{$f['wo']['id']}")->assertOk()->json('data');
}

it('closes a work order once runs are closed and the material left is scrapped by an approved exception (D-117)', function () {
    $f = wcFixture($this, $this->t);
    $wo = wcOrder($this, $f);
    expect($wo['status'])->toBe('IN_PROGRESS')->and($wo['closeBlockers'])->toHaveCount(2)
        ->and($wo['atProduction'])->toBe([['locationCode' => 'MAP-PRS-01', 'itemCode' => $f['coil']['code'], 'lotNo' => $wo['atProduction'][0]['lotNo'], 'qty' => '12', 'uomCode' => 'KG']]);
    woAct($this, $wo['id'], 'close', $wo['version'])->assertStatus(409)->assertJsonPath('code', 'INVALID_STATE_TRANSITION');

    // the production leader closes the run; the planner asks to scrap what is left
    signIn($this, $f['prl'], $this->t);
    $run = spa($this)->getJson("/api/v1/production/runs/{$f['run']['id']}")->assertOk()->json('data');
    spa($this)->postJson("/api/v1/production/runs/{$run['id']}/actions/close", [], ['If-Match' => '"'.$run['version'].'"'])->assertOk()->assertJsonPath('data.status', 'CLOSED');
    signIn($this, $f['mat'], $this->t);
    $ask = fn (string $key, string $reason = 'Coil end too short to use') => spa($this)->postJson("/api/v1/planning/work-orders/{$wo['id']}/close-exceptions", ['reason' => $reason], ['Idempotency-Key' => $key]);
    $ask('wc-ask-0009', 'x')->assertStatus(422)->assertJsonValidationErrors(['reason']);
    $asked = $ask('wc-ask-0001')->assertCreated()->assertJsonPath('data.status', 'SUBMITTED')->json('data.documentId');
    $ask('wc-ask-0001')->assertOk()->assertJsonPath('data.documentId', $asked);
    $ask('wc-ask-0001', 'Another reason')->assertStatus(409)->assertJsonPath('code', 'IDEMPOTENCY_KEY_REUSED');
    $ask('wc-ask-0002')->assertStatus(409);
    $wo = wcOrder($this, $f);
    expect($wo['closeException']['status'])->toBe('SUBMITTED')->and($wo['closeBlockers'])->toHaveCount(2);
    woAct($this, $wo['id'], 'close', $wo['version'])->assertStatus(409);

    // who asked does not decide; the approver posts it: the coil goes to the scrap yard, no longer the WO's
    spa($this)->postJson("/api/v1/planning/wo-close-exceptions/{$asked}/approve", [], ['Idempotency-Key' => 'wc-approve-0001'])->assertForbidden();
    signIn($this, $f['prl'], $this->t);
    spa($this)->postJson("/api/v1/planning/wo-close-exceptions/{$asked}/approve", [], ['Idempotency-Key' => 'wc-approve-0002'])->assertCreated()
        ->assertJsonPath('data.docNo', fn (string $no) => str_starts_with($no, 'WCX-P1-'));
    expect(mfCoil($this->t, $f))->toMatchArray(['SCR-01' => '12'])->not->toHaveKey('MAP-PRS-01');
    spa($this)->getJson('/api/v1/query/planning.wo_close_exceptions')->assertOk()->assertJsonPath('data.0.status', 'POSTED')
        ->assertJsonPath('data.0.workOrderCode', $wo['code'])->assertJsonPath('data.0.reason', 'Coil end too short to use');

    // nothing keeps it open now: the planner closes it
    signIn($this, $f['mat'], $this->t);
    $wo = wcOrder($this, $f);
    expect($wo['closeBlockers'])->toBe([]);
    $closed = woAct($this, $wo['id'], 'close', $wo['version'])->assertOk()->assertJsonPath('data.status', 'CLOSED')->json('data');
    expect($closed['closedByName'])->not->toBeNull()->and($closed['closedAt'])->not->toBeNull();
    woAct($this, $wo['id'], 'close', $closed['version'])->assertStatus(409);

    // a closed work order gets no material again: an issue, or reversing the exception, is refused
    mfIssue($this, $f, ['lines' => [['itemId' => $f['nut']['id'], 'fromLocationId' => $f['rack'], 'qty' => '1']]], 'wc-issue-0001')->assertStatus(409);
    AccessFixtures::grant($this->t, $f['mat']['id'], 'INV');
    $undo = spa($this)->postJson('/api/v1/inventory/reversals', ['documentId' => $asked, 'reason' => 'Wrong exception'], ['Idempotency-Key' => 'wc-reverse-0001'])
        ->assertCreated()->json('data.documentId');
    signIn($this, $f['prl'], $this->t);
    spa($this)->postJson("/api/v1/inventory/reversals/{$undo}/approve", [], ['Idempotency-Key' => 'wc-reverse-0002'])->assertStatus(422)->assertJsonPath('code', 'POSTING_REFUSED');

    // a retry of the exception asked earlier still answers as it was asked, though the order is closed now
    signIn($this, $f['mat'], $this->t);
    $ask('wc-ask-0001')->assertOk()->assertJsonPath('data.documentId', $asked);
})->group('integration', 'planning');

it('makes a reversal approval wait for a close in flight, then refuses it (D-117)', function () {
    $f = wcFixture($this, $this->t);
    signIn($this, $f['prl'], $this->t);
    $run = spa($this)->getJson("/api/v1/production/runs/{$f['run']['id']}")->assertOk()->json('data');
    spa($this)->postJson("/api/v1/production/runs/{$run['id']}/actions/close", [], ['If-Match' => '"'.$run['version'].'"'])->assertOk();
    signIn($this, $f['mat'], $this->t);
    $wo = wcOrder($this, $f);
    $asked = spa($this)->postJson("/api/v1/planning/work-orders/{$wo['id']}/close-exceptions", ['reason' => 'Coil end too short'], ['Idempotency-Key' => 'wc-ask-0011'])
        ->assertCreated()->json('data.documentId');
    signIn($this, $f['prl'], $this->t);
    spa($this)->postJson("/api/v1/planning/wo-close-exceptions/{$asked}/approve", [], ['Idempotency-Key' => 'wc-approve-0011'])->assertCreated();
    AccessFixtures::grant($this->t, $f['mat']['id'], 'INV');
    signIn($this, $f['mat'], $this->t);
    $undo = spa($this)->postJson('/api/v1/inventory/reversals', ['documentId' => $asked, 'reason' => 'Wrong exception'], ['Idempotency-Key' => 'wc-reverse-0011'])
        ->assertCreated()->json('data.documentId');

    $tx = app(TenantTransaction::class);
    config(['database.connections.pgsql_takeover' => config('database.connections.pgsql')]);
    $approve = fn () => $tx->run(TenantContext::forTenant($this->t['tenant'], (string) $f['prl']['id']), function (EoraPostgresConnection $db) use ($undo) {
        $db->statement("set local lock_timeout = '300ms'");

        return app(InventoryPostingService::class)->approve($db, $undo, 'wc-reverse-0012');
    }, 'pgsql_takeover');
    // the planner's close is in flight: the approval putting the coil back at production waits for it
    $tx->run(TenantContext::forTenant($this->t['tenant'], (string) $f['mat']['id']), function (EoraPostgresConnection $db) use ($wo, $approve) {
        $db->update("update work_orders set status = 'CLOSED', closed_by = (select c.actor from ctx_api.context() c), closed_at = now() where id = ?", [$wo['id']]);
        expect($approve)->toThrow(DomainProblem::class, 'Stock busy'); // the lock wait timed out (55P03)
    });
    // committed, the close stands and the approval is refused: the coil stays scrapped
    expect(wcOrder($this, $f)['status'])->toBe('CLOSED');
    signIn($this, $f['prl'], $this->t);
    spa($this)->postJson("/api/v1/inventory/reversals/{$undo}/approve", [], ['Idempotency-Key' => 'wc-reverse-0013'])->assertStatus(422)->assertJsonPath('code', 'POSTING_REFUSED');
    expect(mfCoil($this->t, $f))->toMatchArray(['SCR-01' => '12'])->not->toHaveKey('MAP-PRS-01');
})->group('integration', 'planning');

it('refuses closes and exceptions outside the rules, in the database too (BR-PLN-03)', function () {
    $f = wcFixture($this, $this->t);
    $wo = wcOrder($this, $f);
    // only a planner asks; the approver rejects with the reason and nothing moves
    signIn($this, $f['prl'], $this->t);
    spa($this)->postJson("/api/v1/planning/work-orders/{$wo['id']}/close-exceptions", ['reason' => 'Not mine'], ['Idempotency-Key' => 'wc-ask-0007'])->assertForbidden();
    signIn($this, $f['mat'], $this->t);
    $asked = spa($this)->postJson("/api/v1/planning/work-orders/{$wo['id']}/close-exceptions", ['reason' => 'Coil end too short'], ['Idempotency-Key' => 'wc-ask-0003'])
        ->assertCreated()->json('data.documentId');
    signIn($this, $f['prl'], $this->t);
    spa($this)->postJson("/api/v1/planning/wo-close-exceptions/{$asked}/reject", ['reason' => 'Return it to the rack'])->assertNoContent();
    expect(mfCoil($this->t, $f))->toHaveKey('MAP-PRS-01');

    // straight in the database: closed with an open run and material left, or by someone who does not plan
    $close = fn (string $actor) => asApp($this->t, $actor, fn (EoraPostgresConnection $db) => $db->update(
        "update work_orders set status = 'CLOSED', closed_by = (select c.actor from ctx_api.context() c), closed_at = now() where id = ?", [$wo['id']]));
    foreach ([[$f['mat']['id'], '23514'], [$f['prl']['id'], '42501']] as [$actor, $code]) {
        try {
            $close((string) $actor);
            $this->fail('closed outside the rules');
        } catch (QueryException $e) {
            expect($e->getCode())->toBe($code);
        }
    }
    expect(wcOrder($this, $f)['status'])->toBe('IN_PROGRESS');
})->group('integration', 'planning');
