<?php

declare(strict_types=1);

use App\Platform\Database\EoraPostgresConnection;
use Tests\Support\AccessFixtures;
use Tests\Support\TenantFixtures;

/*
 * M4 step 9 (doc 33 §1.9, D-118, doc 14 #18): the lot trace reconstructs customer shipment → FG lot → WO/run → material
 * lot with actors and times, backward and forward, for CSA/INV/AUD of the plant only.
 */

beforeEach(function () {
    $this->t = TenantFixtures::provision(2);
    AccessFixtures::roles($this->t);
});

/**
 * shFixture with line 1 shipped (90 of lot A, 10 of lot B); the FG warehouse user also holds INV (it may trace).
 *
 * @return array<string, mixed>
 */
function ltFixture(object $test, array $t): array
{
    $f = shFixture($test, $t);
    $ship = shShip($test, $f, [
        ['scheduleLineId' => $f['schedule'], 'stockLineId' => $f['stgA'], 'qty' => '90'],
        ['scheduleLineId' => $f['schedule'], 'stockLineId' => $f['stgB'], 'qty' => '10'],
    ], ['vehicleNo' => 'B 1234 XY'], 'lt-ship-0001')->assertCreated()->json('data');
    $names = AccessFixtures::run($t, fn (EoraPostgresConnection $db) => $db->selectOne(
        'select (select lot_no from lots where id = ?) as coil, (select lot_no from lots where id = ?) as a, (select lot_no from lots where id = ?) as b',
        [$f['lot'], $f['lots'][0], $f['lots'][1]]));

    return ['shipment' => $ship, 'coilNo' => $names->coil, 'aNo' => $names->a, 'bNo' => $names->b] + $f;
}

function ltTrace(object $test, string $q, string $direction = 'backward', array $more = [])
{
    return spa($test)->getJson('/api/v1/traceability/lot-trace?'.http_build_query(['q' => $q, 'direction' => $direction] + $more));
}

it('traces a shipment back to the coil it was made of, with run, WO, receipt, actors and times (doc 14 #18)', function () {
    $f = ltFixture($this, $this->t);
    ltTrace($this, $f['shipment']['docNo'])->assertForbidden();
    AccessFixtures::grant($this->t, $f['fgw']['id'], 'INV');

    $trace = ltTrace($this, strtolower($f['shipment']['docNo']))->assertOk()->json('data');
    expect($trace['start'])->toBe(['kind' => 'shipment', 'docNo' => $f['shipment']['docNo']])->and($trace['truncated'])->toBeFalse();
    $roots = array_values(array_filter($trace['nodes'], fn (array $n) => $n['depth'] === 0));
    expect(array_column($roots, 'lotNo'))->toEqualCanonicalizing([$f['aNo'], $f['bNo']]);
    $a = collect($roots)->firstWhere('lotNo', $f['aNo']);
    expect($a['origin'])->toBe('OUTPUT')->and($a['shipments'])->toHaveCount(1)
        ->and($a['shipments'][0])->toMatchArray(['docNo' => $f['shipment']['docNo'], 'customerCode' => 'OEM-A', 'customerPoNo' => 'PO-1', 'qty' => '90', 'reversed' => false]);

    // each FG lot goes back to the coil through its production posting: run, WO, who posted it and when
    $coils = array_values(array_filter($trace['nodes'], fn (array $n) => $n['itemCode'] === $f['coil']['code']));
    expect($coils)->toHaveCount(2);
    foreach ($coils as $coil) {
        expect($coil['depth'])->toBe(1)->and($coil['origin'])->toBe('RECEIPT')->and($coil['shipments'])->toBe([])
            ->and($coil['via']['docType'])->toBe('PRODUCTION_POSTING')->and($coil['via']['workOrderNo'])->toBe($f['wo']['code'])
            ->and($coil['via']['runNo'])->toStartWith('RUN-')->and($coil['via']['postedByName'])->not->toBeNull()->and($coil['via']['postedAt'])->not->toBeNull()
            ->and($coil['receipt']['docNo'])->toStartWith('RCV-')->and($coil['receipt']['postedByName'])->not->toBeNull();
    }
    expect(collect($coils)->firstWhere('parentKey', $a['key'])['via'])->toMatchArray(['qtyUsed' => '48', 'uomUsed' => 'KG']); // 60 KG issued, 12 KG weighed left at production
})->group('integration', 'traceability');

it('traces a coil forward to the FG lots made of it and the customer that received them (recall)', function () {
    $f = ltFixture($this, $this->t);
    AccessFixtures::grant($this->t, $f['fgw']['id'], 'INV');

    // the coil and FG lot A share their number (internal lots count per item, M3-D3): the trace asks which one
    $matches = ltTrace($this, $f['coilNo'], 'forward')->assertOk()->json('data.matches');
    expect(collect($matches)->pluck('itemCode')->all())->toContain($f['coil']['code']);
    $trace = ltTrace($this, $f['coilNo'], 'forward', ['lotId' => $f['lot']])->assertOk()->json('data');
    expect($trace['start'])->toMatchArray(['kind' => 'lot', 'lotNo' => $f['coilNo']])->and($trace['nodes'][0]['depth'])->toBe(0)
        ->and($trace['nodes'][0]['receipt']['supplierCode'])->not->toBeNull();
    $made = array_values(array_filter($trace['nodes'], fn (array $n) => $n['depth'] === 1));
    expect(array_column($made, 'lotNo'))->toEqualCanonicalizing([$f['aNo'], $f['bNo']]);
    $b = collect($made)->firstWhere('lotNo', $f['bNo']);
    // lot B: 10 shipped on this order; 30 still staged for the second order
    expect($b['shipments'])->toHaveCount(1)->and($b['shipments'][0])->toMatchArray(['customerCode' => 'OEM-A', 'qty' => '10', 'soNo' => $f['so']['code']]);

    // a shipment is traced backward only; unknown numbers are not found
    ltTrace($this, $f['shipment']['docNo'], 'forward')->assertStatus(422)->assertJsonValidationErrors(['direction']);
    ltTrace($this, 'L9999-9999')->assertNotFound();
    ltTrace($this, 'SHP-P1-1999-0001')->assertNotFound();
})->group('integration', 'traceability');

it('shows only the viewer\'s plants: another plant\'s inspector finds nothing; a reversed shipment stays, marked', function () {
    $f = ltFixture($this, $this->t);
    AccessFixtures::grant($this->t, $f['fgw']['id'], 'INV', ['PLANT', $this->t['plants'][1]]);
    ltTrace($this, $f['shipment']['docNo'])->assertNotFound();
    ltTrace($this, $f['coilNo'], 'forward', ['lotId' => $f['lot']])->assertNotFound();

    // the shipment is reversed: the trace still shows it, marked reversed
    AccessFixtures::grant($this->t, $f['fgw']['id'], 'INV');
    $undo = spa($this)->postJson('/api/v1/inventory/reversals', ['documentId' => $f['shipment']['documentId'], 'reason' => 'Wrong truck'], ['Idempotency-Key' => 'lt-reverse-0001'])
        ->assertCreated()->json('data.documentId');
    signIn($this, $f['dlv'], $this->t);
    AccessFixtures::grant($this->t, $f['dlv']['id'], 'CSA');
    spa($this)->postJson("/api/v1/inventory/reversals/{$undo}/approve", [], ['Idempotency-Key' => 'lt-reverse-0002'])->assertCreated();
    $a = collect(ltTrace($this, $f['aNo'], 'backward', ['lotId' => $f['lots'][0]])->assertOk()->json('data.nodes'))->firstWhere('depth', 0);
    expect($a['shipments'][0])->toMatchArray(['docNo' => $f['shipment']['docNo'], 'reversed' => true]);
})->group('integration', 'traceability');
