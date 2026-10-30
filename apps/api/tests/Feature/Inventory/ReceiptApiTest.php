<?php

declare(strict_types=1);

use App\Modules\Inventory\Application\InventoryPostingService;
use App\Modules\Inventory\Contracts\PostingCommand;
use App\Modules\Inventory\Contracts\PostingLeg;
use App\Platform\Database\EoraPostgresConnection;
use App\Platform\Exceptions\DomainProblem;
use App\Platform\Tenancy\TenantContext;
use App\Platform\Tenancy\TenantTransaction;
use Tests\Support\AccessFixtures;
use Tests\Support\TenantFixtures;

/*
 * M3 step 3 (doc 32 §1.3, M3-D3/D4, D-062, D-097): raw-material receipt and incoming release through the HTTP API —
 * lot numbers, BLOCKED or UNRESTRICTED by the item, idempotent requests, plant scope, the receipt and stock lists.
 */

beforeEach(function () {
    $this->t = TenantFixtures::provision(2);
    AccessFixtures::roles($this->t);
    $this->f = rcFixture($this->t);
});

function rcRun(array $t, Closure $work): mixed
{
    return app(TenantTransaction::class)->run(TenantContext::forTenant($t['tenant'], $t['actor']), $work);
}

/** @return array<string, string> */
function rcFixture(array $t): array
{
    return rcRun($t, function (EoraPostgresConnection $db) use ($t) {
        [$plant, $plant2] = $t['plants'];
        $db->insert("insert into business_calendars (tenant_id, plant_id, timezone, production_day_starts_at, effective_from) values (?, null, 'Asia/Jakarta', '00:00', '2026-01-01')", [$t['tenant']]);
        $pcs = (string) $db->selectOne("select id from uoms where code = 'PCS'")->id;
        $item = fn (string $code, string $type, bool $lots, bool $release) => (string) $db->selectOne(
            'insert into items (tenant_id, code, name, item_type, base_uom_id, lot_controlled, incoming_release_required) values (?, ?, ?, ?, ?, ?, ?) returning id',
            [$t['tenant'], $code, "Item {$code}", $type, $pcs, $lots, $release],
        )->id;
        $coil = $item('COIL-1', 'RAW', true, true);
        $oil = $item('OIL-1', 'CONSUMABLE', false, false);
        $fg = $item('FG-1', 'FINISHED', true, false);
        $rack = function (string $p, string $code) use ($db, $t) {
            $wh = $db->selectOne('select id from warehouses where plant_id = ? and code = ?', [$p, 'RM'])?->id
                ?? $db->selectOne("insert into warehouses (tenant_id, plant_id, code, name, warehouse_type) values (?, ?, 'RM', 'Raw', 'RAW_MATERIAL') returning id", [$t['tenant'], $p])->id;

            return (string) $db->selectOne("insert into locations (tenant_id, plant_id, warehouse_id, code, name, location_type) values (?, ?, ?, ?, ?, 'RM_WAREHOUSE') returning id",
                [$t['tenant'], $p, $wh, $code, "Rack {$code}"])->id;
        };
        $rm1 = $rack($plant, 'RM-01');
        $rm2 = $rack($plant, 'RM-02');
        $other = $rack($plant2, 'RM-09');
        $supplierLoc = (string) $db->selectOne("select id from locations where plant_id = ? and location_type = 'V_SUPPLIER'", [$plant])->id;
        $supplier = (string) $db->selectOne("insert into suppliers (tenant_id, code, name) values (?, 'SUP-1', 'Steel supplier') returning id", [$t['tenant']])->id;
        $today = (string) $db->selectOne("select (now() at time zone 'Asia/Jakarta')::date::text as d")->d;

        return compact('plant', 'plant2', 'coil', 'oil', 'fg', 'rm1', 'rm2', 'other', 'supplierLoc', 'supplier', 'today');
    });
}

function rcReceive(object $test, array $f, array $body = [], ?string $key = 'receipt-key-0001')
{
    return spa($test)->postJson('/api/v1/inventory/receipts', $body + [
        'plantId' => $f['plant'], 'itemId' => $f['coil'], 'locationId' => $f['rm1'], 'supplierId' => $f['supplier'], 'qty' => '10',
        'supplierLotNo' => 'HEAT-778',
    ], $key === null ? [] : ['Idempotency-Key' => $key]);
}

it('receives a lot-controlled coil BLOCKED with a new internal lot, and replays a retried request (M3-D3, D-062)', function () {
    orgUser($this, $this->t, 'MAT');
    $f = $this->f;
    $year = substr($f['today'], 0, 4);
    $period = substr($f['today'], 2, 2).substr($f['today'], 5, 2);

    $first = rcReceive($this, $f)->assertCreated()->json('data');
    expect($first)->docNo->toBe("RCV-P1-{$year}-0001")->lotNo->toBe("L{$period}-0001")->replayed->toBeFalse();

    // the same request again: the same document and lot, nothing new created
    rcReceive($this, $f)->assertOk()->assertJsonPath('data.docNo', $first['docNo'])->assertJsonPath('data.lotId', $first['lotId'])->assertJsonPath('data.replayed', true);
    // the key with another request is refused; a request without a key too
    rcReceive($this, $f, ['qty' => '11'])->assertStatus(409)->assertJsonPath('code', 'IDEMPOTENCY_KEY_REUSED');
    rcReceive($this, $f, key: null)->assertStatus(400)->assertJsonPath('code', 'IDEMPOTENCY_KEY_REQUIRED');

    expect(rcReceive($this, $f, ['qty' => '4'], 'receipt-key-0002')->assertCreated()->json('data.lotNo'))->toBe("L{$period}-0002");
    expect(AccessFixtures::run($this->t, fn (EoraPostgresConnection $db) => (int) $db->selectOne('select count(*) as n from lots')->n))->toBe(2);

    $receipts = spa($this)->getJson('/api/v1/query/inventory.receipts')->assertOk()->assertJsonPath('meta.total', 2)->json('data');
    expect(collect($receipts)->firstWhere('docNo', $first['docNo']))
        ->supplierCode->toBe('SUP-1')->itemCode->toBe('COIL-1')->supplierLotNo->toBe('HEAT-778')->locationCode->toBe('RM-01')
        ->stockStatus->toBe('BLOCKED')->qty->toBe('10')->uomCode->toBe('PCS');

    // BLOCKED stock is on hand, never available (D-043)
    $stock = spa($this)->getJson('/api/v1/query/inventory.stock?filter[stockStatus]=BLOCKED')->assertOk()->json('data');
    expect(collect($stock)->pluck('qty')->sort()->values()->all())->toBe(['4', '10'])
        ->and(collect($stock)->pluck('availableQty')->unique()->all())->toBe(['0']);
})->group('integration', 'inventory');

it('receives a consumable without a lot as UNRESTRICTED and refuses other item types and places (M3-D4, D-097)', function () {
    orgUser($this, $this->t, 'MAT');
    $f = $this->f;
    rcReceive($this, $f, ['itemId' => $f['oil'], 'supplierLotNo' => null])->assertCreated()->assertJsonPath('data.lotNo', null);
    $stock = spa($this)->getJson('/api/v1/query/inventory.stock?filter[itemId]='.$f['oil'])->assertOk()->json('data');
    expect($stock)->toHaveCount(1)->and($stock[0])->stockStatus->toBe('UNRESTRICTED')->availableQty->toBe('10');

    rcReceive($this, $f, ['itemId' => $f['fg']], 'receipt-key-0003')->assertStatus(422)->assertJsonValidationErrors(['itemId']);
    rcReceive($this, $f, ['locationId' => $f['supplierLoc']], 'receipt-key-0004')->assertStatus(422)->assertJsonValidationErrors(['locationId']);
    rcReceive($this, $f, ['locationId' => $f['other']], 'receipt-key-0005')->assertStatus(422)->assertJsonValidationErrors(['locationId']);
    rcReceive($this, $f, ['qty' => '0'], 'receipt-key-0006')->assertStatus(422)->assertJsonValidationErrors(['qty']);
    rcReceive($this, $f, ['qty' => '-2'], 'receipt-key-0007')->assertStatus(422)->assertJsonValidationErrors(['qty']);
})->group('integration', 'inventory');

it('posts receipts and releases only with their permission and inside the plant scope (M3-D1)', function () {
    $f = $this->f;
    orgUser($this, $this->t, 'QCI');
    rcReceive($this, $f)->assertForbidden();
    rtSwitch($this, $this->t, 'MAT', ['PLANT', $f['plant2']]);
    rcReceive($this, $f)->assertForbidden();
    spa($this)->postJson('/api/v1/inventory/incoming-releases', ['plantId' => $f['plant'], 'itemId' => $f['coil'], 'lines' => [['locationId' => $f['rm1'], 'qty' => '1']]],
        ['Idempotency-Key' => 'release-key-0001'])->assertForbidden();
    // nothing was posted
    expect(AccessFixtures::run($this->t, fn (EoraPostgresConnection $db) => (int) $db->selectOne('select count(*) as n from inventory_documents')->n))->toBe(0);
})->group('integration', 'inventory');

it('releases BLOCKED stock rack by rack in one document, never more than is blocked (D-062)', function () {
    $f = $this->f;
    orgUser($this, $this->t, 'MAT');
    $lot = rcReceive($this, $f)->assertCreated()->json('data.lotId');
    // 4 of the lot move to the second rack, still BLOCKED
    rcRun($this->t, fn (EoraPostgresConnection $db) => app(InventoryPostingService::class)->post($db, new PostingCommand('MAT_TRANSFER', $f['plant'], 'transfer-key-0001', [
        new PostingLeg('TRANSFER_LOC', $f['rm1'], $f['coil'], $lot, 'BLOCKED', '-4'),
        new PostingLeg('TRANSFER_LOC', $f['rm2'], $f['coil'], $lot, 'BLOCKED', '4'),
    ])));

    rtSwitch($this, $this->t, 'QCI');
    $release = fn (array $lines, string $key) => spa($this)->postJson('/api/v1/inventory/incoming-releases',
        ['plantId' => $f['plant'], 'itemId' => $f['coil'], 'lotId' => $lot, 'lines' => $lines], ['Idempotency-Key' => $key]);
    $release([['locationId' => $f['rm1'], 'qty' => '7']], 'release-key-0001')->assertStatus(409)->assertJsonPath('code', 'INSUFFICIENT_STOCK');
    $year = substr($f['today'], 0, 4);
    $release([['locationId' => $f['rm1'], 'qty' => '6'], ['locationId' => $f['rm2'], 'qty' => '3']], 'release-key-0002')
        ->assertCreated()->assertJsonPath('data.docNo', "REL-P1-{$year}-0001")->assertJsonPath('data.lotId', $lot);

    $stock = collect(spa($this)->getJson('/api/v1/query/inventory.stock?filter[lotId]='.$lot)->assertOk()->json('data'))
        ->mapWithKeys(fn ($r) => [$r['locationCode'].' '.$r['stockStatus'] => $r['availableQty'].'/'.$r['qty']])->sortKeys()->all();
    expect($stock)->toBe(['RM-01 UNRESTRICTED' => '6/6', 'RM-02 BLOCKED' => '0/1', 'RM-02 UNRESTRICTED' => '3/3']);

    // the service refuses a release that moves stock between racks
    try {
        rcRun($this->t, fn (EoraPostgresConnection $db) => app(InventoryPostingService::class)->post($db, new PostingCommand('INCOMING_RELEASE', $f['plant'], 'release-key-0003', [
            new PostingLeg('INCOMING_RELEASE', $f['rm2'], $f['coil'], $lot, 'BLOCKED', '-1'),
            new PostingLeg('INCOMING_RELEASE', $f['rm1'], $f['coil'], $lot, 'UNRESTRICTED', '1'),
        ])));
        $this->fail('A release between racks was posted.');
    } catch (DomainProblem $e) {
        expect($e->errorCode)->toBe('INVALID_LEGS')->and($e->detail)->toContain('rack by rack');
    }
})->group('integration', 'inventory');
