<?php

declare(strict_types=1);

use App\Platform\Database\EoraPostgresConnection;
use App\Platform\Tenancy\TenantContext;
use App\Platform\Tenancy\TenantTransaction;

/* Inventory HTTP tests (M3): a tenant with calendar, items, racks and a supplier; receipts through the API. */

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

function trMove(object $test, array $f, array $body = [], string $key = 'transfer-key-0001')
{
    return spa($test)->postJson('/api/v1/inventory/transfers', $body + [
        'plantId' => $f['plant'], 'itemId' => $f['coil'], 'lotId' => $f['lotId'] ?? null, 'stockStatus' => 'BLOCKED',
        'fromLocationId' => $f['rm1'], 'toLocationId' => $f['rm2'], 'qty' => '4',
    ], ['Idempotency-Key' => $key]);
}

/** @return array<string, string> "<location> <status>" => qty of the lot */
function trStock(object $test, string $lot): array
{
    return collect(spa($test)->getJson('/api/v1/query/inventory.stock?filter[lotId]='.$lot)->assertOk()->json('data'))
        ->mapWithKeys(fn ($r) => [$r['locationCode'].' '.$r['stockStatus'] => $r['qty']])->sortKeys()->all();
}

function lsSplit(object $test, array $f, array $body = [], string $key = 'split-key-0001')
{
    return spa($test)->postJson('/api/v1/inventory/lot-splits', $body + [
        'plantId' => $f['plant'], 'itemId' => $f['coil'], 'lotId' => $f['lotId'] ?? null, 'stockStatus' => 'BLOCKED',
        'locationId' => $f['rm1'], 'children' => [['qty' => '3', 'widthMm' => '1219', 'lengthMm' => '2438.5'], ['qty' => '2']],
    ], ['Idempotency-Key' => $key]);
}

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
