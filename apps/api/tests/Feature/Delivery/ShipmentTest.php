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
 * M4 step 7b (doc 33 §1.7, D-115, D-116, doc 04 row 11, BR-FG-03, BR-DEM-10, doc 14 #32, #35): one shipment ships
 * staged goods of one customer's schedule lines (of different orders) to the customer, with the transport; delivered
 * and the line status follow it; tolerance 0; a reversal undoes it.
 */

beforeEach(function () {
    $this->t = TenantFixtures::provision(2);
    AccessFixtures::roles($this->t);
});

it('ships staged goods of two orders of one customer with the transport; delivered and status follow (D-116, BR-DEM-10)', function () {
    $f = shFixture($this, $this->t);
    $l1 = $f['schedule'];
    $l2 = $f['line2'];
    spa($this)->getJson("/api/v1/delivery/staged?plantId={$f['plant']}&customerId={$f['customer']}")->assertOk()->assertJsonCount(3, 'data')
        ->assertJsonPath('data.0.scheduleLineId', $l1)->assertJsonPath('data.0.stagedQty', '90');

    $lines = [
        ['scheduleLineId' => $l1, 'stockLineId' => $f['stgA'], 'qty' => '90'],
        ['scheduleLineId' => $l1, 'stockLineId' => $f['stgB'], 'qty' => '10'],
        ['scheduleLineId' => $l2, 'stockLineId' => $f['stgB'], 'qty' => '20'],
    ];
    $posted = shShip($this, $f, $lines, ['vehicleNo' => 'B 1234 XY', 'driverName' => 'Budi', 'note' => 'Trip 1'])->assertCreated()
        ->assertJsonPath('data.docNo', fn (string $no) => str_starts_with($no, 'SHP-P1-'))->json('data');
    shShip($this, $f, $lines, ['vehicleNo' => 'B 1234 XY', 'driverName' => 'Budi', 'note' => 'Trip 1'])->assertOk()->assertJsonPath('data.documentId', $posted['documentId']);
    expect(fgStock($this->t, $f['item']['id']))->toMatchArray(['STG-01 / UNRESTRICTED' => '10']);

    spa($this)->getJson("/api/v1/delivery/schedule-lines/{$l1}")->assertOk()->assertJsonPath('data.status', 'FULFILLED')
        ->assertJsonPath('data.deliveredQty', '100')->assertJsonPath('data.stagedQty', '0');
    spa($this)->getJson("/api/v1/delivery/schedule-lines/{$l2}")->assertOk()->assertJsonPath('data.status', 'PARTIALLY_FULFILLED')
        ->assertJsonPath('data.deliveredQty', '20')->assertJsonPath('data.stagedQty', '10');
    spa($this)->getJson('/api/v1/query/delivery.shipments')->assertOk()->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.vehicleNo', 'B 1234 XY')->assertJsonPath('data.0.driverName', 'Budi')->assertJsonPath('data.0.customerCode', 'OEM-A')->assertJsonPath('data.0.lines', 3);
    spa($this)->getJson('/api/v1/query/delivery.schedule_lines')->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $l2);

    // the order shows delivered and outstanding from the shipment
    signIn($this, $f['ppc'], $this->t);
    spa($this)->getJson("/api/v1/demand/sales-orders/{$f['so2']['id']}")->assertOk()
        ->assertJsonPath('data.lines.0.schedules.0.deliveredQty', '20')->assertJsonPath('data.lines.0.schedules.0.outstandingQty', '10')
        ->assertJsonPath('data.lines.0.schedules.0.status', 'PARTIALLY_FULFILLED');

    // a wrong shipment is reversed through the M3 flow: back to staging, delivered and status back
    AccessFixtures::grant($this->t, $f['fgw']['id'], 'INV');
    signIn($this, $f['fgw'], $this->t);
    $undo = spa($this)->postJson('/api/v1/inventory/reversals', ['documentId' => $posted['documentId'], 'reason' => 'Wrong truck'], ['Idempotency-Key' => 'sh-reverse-0001'])
        ->assertCreated()->json('data.documentId');
    AccessFixtures::grant($this->t, $f['ppc']['id'], 'CSA');
    signIn($this, $f['ppc'], $this->t);
    spa($this)->postJson("/api/v1/inventory/reversals/{$undo}/approve", [], ['Idempotency-Key' => 'sh-reverse-0002'])->assertCreated();
    signIn($this, $f['dlv'], $this->t);
    spa($this)->getJson("/api/v1/delivery/schedule-lines/{$l1}")->assertOk()->assertJsonPath('data.status', 'FIRM')
        ->assertJsonPath('data.deliveredQty', '0')->assertJsonPath('data.stagedQty', '100');
    expect(fgStock($this->t, $f['item']['id']))->toMatchArray(['STG-01 / UNRESTRICTED' => '130']);
})->group('integration', 'delivery');

it('refuses shipping beyond what is staged, for another customer, and outside the rules in the database (BR-FG-03)', function () {
    $f = shFixture($this, $this->t);
    shShip($this, $f, [['scheduleLineId' => $f['line2'], 'stockLineId' => $f['stgB'], 'qty' => '31']], [], 'sh-ship-0009')
        ->assertStatus(422)->assertJsonValidationErrors(['lines.0.qty']);
    $other = AccessFixtures::run($this->t, fn (EoraPostgresConnection $db) => (string) $db->selectOne("insert into customers (tenant_id, code, name) values (?, 'OEM-B', 'Customer OEM-B') returning id", [$this->t['tenant']])->id);
    shShip($this, $f, [['scheduleLineId' => $f['line2'], 'stockLineId' => $f['stgB'], 'qty' => '1']], ['customerId' => $other], 'sh-ship-0008')
        ->assertStatus(422)->assertJsonValidationErrors(['lines.0.scheduleLineId']);
    shShip($this, $f, [['scheduleLineId' => $f['line2'], 'stockLineId' => $f['stgB'], 'qty' => '1']], ['vehicleNo' => str_repeat('X', 31)], 'sh-ship-0007')
        ->assertStatus(422)->assertJsonValidationErrors(['vehicleNo']);

    // only delivery or the FG warehouse ship
    $prl = $f['prl'];
    signIn($this, $prl, $this->t);
    shShip($this, $f, [['scheduleLineId' => $f['line2'], 'stockLineId' => $f['stgB'], 'qty' => '1']], [], 'sh-ship-0006')->assertForbidden();

    $refused = function (Closure $attempt, string $why) {
        try {
            $attempt();
            $this->fail("posted: {$why}");
        } catch (DomainProblem $e) {
            expect($e->errorCode)->toBeIn(['POSTING_REFUSED', 'INVALID_LEGS', 'INSUFFICIENT_STOCK'], $why);
        } catch (QueryException|PDOException $e) {
            expect((string) $e->getCode())->toBeIn(['23514', '42501'], $why);
        }
    };
    $customerLoc = AccessFixtures::run($this->t, fn (EoraPostgresConnection $db) => (string) $db->selectOne("select id from locations where plant_id = ? and location_type = 'V_CUSTOMER'", [$f['plant']])->id);
    [, $b] = $f['lots'];
    $post = fn (string $actor, string $customer, array $legs, string $key) => asApp($this->t, $actor, fn (EoraPostgresConnection $db) => app(InventoryPostingService::class)->post($db,
        new PostingCommand('SHIPMENT', $f['plant'], $key, $legs, sourceType: 'customer', sourceId: $customer)));
    $legs = fn (string $qty) => [
        new PostingLeg('SHIP', AccessFixtures::run($this->t, fn (EoraPostgresConnection $db) => (string) $db->selectOne("select id from locations where code = 'STG-01' and plant_id = ?", [$f['plant']])->id), $f['item']['id'], $b, 'UNRESTRICTED', '-'.$qty, scheduleLineId: $f['line2']),
        new PostingLeg('SHIP', $customerLoc, $f['item']['id'], $b, 'UNRESTRICTED', $qty, scheduleLineId: $f['line2']),
    ];
    $refused(fn () => $post((string) $f['fgw']['id'], $other, $legs('1'), 'sh-direct-0001'), 'shipped for another customer');
    $refused(fn () => $post((string) $f['prl']['id'], $f['customer'], $legs('1'), 'sh-direct-0002'), 'shipped by the production leader');
    $refused(fn () => $post((string) $f['fgw']['id'], $f['customer'], $legs('31'), 'sh-direct-0003'), 'shipped more than staged for the line');
    spa($this)->getJson('/api/v1/query/delivery.shipments')->assertForbidden();
})->group('integration', 'delivery');
