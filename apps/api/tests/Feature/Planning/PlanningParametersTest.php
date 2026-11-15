<?php

declare(strict_types=1);

use App\Platform\Database\EoraPostgresConnection;
use Illuminate\Database\QueryException;
use Tests\Support\AccessFixtures;
use Tests\Support\TenantFixtures;

/*
 * M5 step 1 (doc 34 §1.1, D-121, D-087, G-MPS-2/4): planning parameters per item + plant changed directly by PPC, and
 * the supplier inbound schedule written by MAT or PPC, received against by RM receipts.
 */

beforeEach(function () {
    $this->t = TenantFixtures::provision(2);
    AccessFixtures::roles($this->t);
});

function ppParam(object $test, array $body)
{
    return spa($test)->postJson('/api/v1/planning/plan-parameters', $body);
}

it('keeps one set of planning parameters per item and plant, changed directly by a planner and audited (D-121)', function () {
    $f = rcFixture($this->t);
    $ppc = orgUser($this, $this->t, 'PPC');
    $fg = ppParam($this, ['plantId' => $f['plant'], 'itemId' => $f['fg'], 'productionLeadTimeDays' => 3])->assertCreated()
        ->assertJsonPath('data.code', 'FG-1')->assertJsonPath('data.productionLeadTimeDays', 3)->assertJsonPath('data.purchaseLeadTimeDays', null)
        ->assertJsonPath('data.safetyStock', null)->assertJsonPath('data.safetyStockConfigured', false)->assertJsonPath('data.lotPolicy', 'LOT_FOR_LOT')->json('data');
    ppParam($this, ['plantId' => $f['plant'], 'itemId' => $f['fg']])->assertStatus(422)->assertJsonValidationErrors(['itemId']);
    // a made item takes a production lead time, a bought one a purchase lead time; the safety stock keeps the unit's decimals
    ppParam($this, ['plantId' => $f['plant'], 'itemId' => $f['coil'], 'productionLeadTimeDays' => 2])->assertStatus(422)->assertJsonValidationErrors(['productionLeadTimeDays']);
    ppParam($this, ['plantId' => $f['plant'], 'itemId' => $f['coil'], 'purchaseLeadTimeDays' => 5, 'safetyStock' => '2.5'])->assertStatus(422)->assertJsonValidationErrors(['safetyStock']);
    ppParam($this, ['plantId' => $f['plant'], 'itemId' => $f['coil'], 'purchaseLeadTimeDays' => 5, 'safetyStock' => '200'])->assertCreated()
        ->assertJsonPath('data.purchaseLeadTimeDays', 5)->assertJsonPath('data.safetyStock', '200')->assertJsonPath('data.safetyStockConfigured', true);

    $patch = fn (array $body, int $version) => spa($this)->patchJson("/api/v1/planning/plan-parameters/{$fg['id']}", $body, ['If-Match' => '"'.$version.'"']);
    $patch(['purchaseLeadTimeDays' => 2], $fg['version'])->assertStatus(422)->assertJsonValidationErrors(['purchaseLeadTimeDays']);
    $changed = $patch(['safetyStock' => '150', 'productionLeadTimeDays' => 4], $fg['version'])->assertOk()
        ->assertJsonPath('data.safetyStock', '150')->assertJsonPath('data.productionLeadTimeDays', 4)->json('data');
    $patch(['safetyStock' => '10'], $fg['version'])->assertStatus(412);
    // empty again: NOT CONFIGURED
    $patch(['safetyStock' => null], $changed['version'])->assertOk()->assertJsonPath('data.safetyStockConfigured', false);
    expect(AccessFixtures::run($this->t, fn (EoraPostgresConnection $db) => array_column($db->select(
        'select action from audit_events where entity_id = ? order by occurred_at, id', [$fg['id']]), 'action')))
        ->toBe(['plan_parameter.created', 'plan_parameter.updated', 'plan_parameter.updated']);
    spa($this)->getJson('/api/v1/query/planning.plan_parameters?filter[safetyStockConfigured]=true')->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.code', 'COIL-1');

    // only a planner of the plant changes them, in the database too
    $mat = rtSwitch($this, $this->t, 'MAT');
    spa($this)->getJson('/api/v1/query/planning.plan_parameters')->assertOk()->assertJsonCount(2, 'data');
    ppParam($this, ['plantId' => $f['plant'], 'itemId' => $f['oil'], 'purchaseLeadTimeDays' => 1])->assertForbidden();
    rtSwitch($this, $this->t, 'PPC', ['PLANT', $this->t['plants'][1]]);
    ppParam($this, ['plantId' => $f['plant'], 'itemId' => $f['oil'], 'purchaseLeadTimeDays' => 1])->assertForbidden();
    try {
        asApp($this->t, (string) $mat['id'], fn (EoraPostgresConnection $db) => $db->update('update item_plan_parameters set safety_stock = 1 where id = ?', [$fg['id']]));
        $this->fail('changed by materials');
    } catch (QueryException $e) {
        expect($e->getCode())->toBe('42501');
    }
    expect($ppc)->toBeArray();
})->group('integration', 'planning');

it('schedules supplier deliveries; receipts against a line move it to partially received and received (D-087, D-121)', function () {
    $f = rcFixture($this->t);
    $mat = rtSwitch($this, $this->t, 'MAT');
    $line = fn (array $body = []) => spa($this)->postJson('/api/v1/inventory/inbound-lines', $body + [
        'plantId' => $f['plant'], 'supplierId' => $f['supplier'], 'itemId' => $f['coil'], 'qty' => '100', 'expectedDate' => $f['today'],
    ]);
    $year = substr($f['today'], 0, 4);
    $a = $line()->assertCreated()->assertJsonPath('data.code', "INB-P1-{$year}-0001")->assertJsonPath('data.status', 'OPEN')
        ->assertJsonPath('data.outstandingQty', '100')->json('data');
    $line(['itemId' => $f['fg']])->assertStatus(422)->assertJsonValidationErrors(['itemId']);
    $line(['qty' => '1.5'])->assertStatus(422)->assertJsonValidationErrors(['qty']);

    // a receipt names the line: same supplier, item and plant only
    rcReceive($this, $f, ['qty' => '40', 'inboundLineId' => $a['id']], 'inb-receipt-0001')->assertCreated();
    rcReceive($this, $f, ['itemId' => $f['oil'], 'supplierLotNo' => null, 'inboundLineId' => $a['id']], 'inb-receipt-0002')->assertStatus(422)->assertJsonValidationErrors(['inboundLineId']);
    $read = fn () => spa($this)->getJson("/api/v1/query/materials.inbound_schedule?filter[id]={$a['id']}")->assertOk()->json('data.0');
    expect($read())->toMatchArray(['status' => 'PARTIALLY_RECEIVED', 'receivedQty' => '40', 'outstandingQty' => '60', 'receipts' => 1]);

    // received against: quantity and date stay; the note may change
    $now = $read();
    spa($this)->patchJson("/api/v1/inventory/inbound-lines/{$a['id']}", ['qty' => '90'], ['If-Match' => '"'.$now['version'].'"'])->assertStatus(409);
    $noted = spa($this)->patchJson("/api/v1/inventory/inbound-lines/{$a['id']}", ['note' => 'Second truck Friday'], ['If-Match' => '"'.$now['version'].'"'])->assertOk()->json('data');

    // more than scheduled is still received; the line reads received
    $receipt = rcReceive($this, $f, ['qty' => '70', 'inboundLineId' => $a['id']], 'inb-receipt-0003')->assertCreated()->json('data.documentId');
    expect($read())->toMatchArray(['status' => 'RECEIVED', 'receivedQty' => '110', 'outstandingQty' => '0']);
    // a reversed receipt no longer counts
    AccessFixtures::grant($this->t, $mat['id'], 'INV');
    $undo = spa($this)->postJson('/api/v1/inventory/reversals', ['documentId' => $receipt, 'reason' => 'Wrong truck'], ['Idempotency-Key' => 'inb-reverse-0001'])->assertCreated()->json('data.documentId');
    rtSwitch($this, $this->t, 'CSA');
    spa($this)->postJson("/api/v1/inventory/reversals/{$undo}/approve", [], ['Idempotency-Key' => 'inb-reverse-0002'])->assertCreated();
    expect($read())->toMatchArray(['status' => 'PARTIALLY_RECEIVED', 'receivedQty' => '40']);

    // cancel the rest with a reason; a cancelled line is history and takes no receipt
    rtSwitch($this, $this->t, 'PPC');
    $cancel = fn (string $reason, int $version) => spa($this)->postJson("/api/v1/inventory/inbound-lines/{$a['id']}/actions/cancel", ['reason' => $reason], ['If-Match' => '"'.$version.'"']);
    $cancel('x', $noted['version'])->assertStatus(422)->assertJsonValidationErrors(['reason']);
    $cancel('Supplier short of coil', $read()['version'])->assertOk()->assertJsonPath('data.status', 'CANCELLED')->assertJsonPath('data.receivedQty', '40')
        ->assertJsonPath('data.cancelReason', 'Supplier short of coil');
    $cancel('Again', $read()['version'])->assertStatus(409);
    signIn($this, $mat, $this->t);
    rcReceive($this, $f, ['qty' => '5', 'inboundLineId' => $a['id']], 'inb-receipt-0004')->assertStatus(422)->assertJsonValidationErrors(['inboundLineId']);

    // only materials or a planner of the plant writes the schedule
    rtSwitch($this, $this->t, 'INV');
    $line()->assertForbidden();
})->group('integration', 'inventory');
