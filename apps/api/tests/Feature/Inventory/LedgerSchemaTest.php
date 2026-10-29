<?php

declare(strict_types=1);

use App\Platform\Database\EoraPostgresConnection;
use App\Platform\Tenancy\TenantContext;
use App\Platform\Tenancy\TenantTransaction;
use Illuminate\Database\QueryException;
use Tests\Support\AccessFixtures;
use Tests\Support\TenantFixtures;

/*
 * M3 step 1 (doc 32 §1.1, D-096; ADR-0002, ADR-0014): what the database guarantees about the ledger by itself, as the
 * runtime role (never a superuser): append-only movements, no negative or virtual balance, posted documents fixed,
 * a document reversed once, tenant isolation, and the M3-D1 permissions in the role templates.
 */

beforeEach(function () {
    $this->t = TenantFixtures::provision(1);
    AccessFixtures::roles($this->t);
});

/** Runs $work as the runtime role (eora_app) in the tenant's signed context. */
function ledgerAsApp(array $t, Closure $work): mixed
{
    return app(TenantTransaction::class)->run(TenantContext::forTenant($t['tenant'], $t['actor']), $work);
}

/**
 * An item (PCS), a lot, the RM location of a new warehouse and a posted receipt with its two legs, written directly as
 * the runtime role — the posting service (step 2) is the only writer in production.
 *
 * @return array<string, string>
 */
function ledgerFixture(array $t): array
{
    return ledgerAsApp($t, function (EoraPostgresConnection $db) use ($t) {
        $plant = $t['plants'][0];
        $pcs = (string) $db->selectOne("select id from uoms where code = 'PCS'")->id;
        $item = (string) $db->selectOne("insert into items (tenant_id, code, name, item_type, base_uom_id) values (?, 'COIL-1', 'Coil', 'RAW', ?) returning id", [$t['tenant'], $pcs])->id;
        $wh = (string) $db->selectOne("insert into warehouses (tenant_id, plant_id, code, name, warehouse_type) values (?, ?, 'RM', 'Raw', 'RAW_MATERIAL') returning id", [$t['tenant'], $plant])->id;
        $rm = (string) $db->selectOne("insert into locations (tenant_id, plant_id, warehouse_id, code, name, location_type) values (?, ?, ?, 'RM-01', 'Rack 1', 'RM_WAREHOUSE') returning id", [$t['tenant'], $plant, $wh])->id;
        $supplier = (string) $db->selectOne("select id from locations where plant_id = ? and location_type = 'V_SUPPLIER'", [$plant])->id;
        $lot = (string) $db->selectOne("insert into lots (tenant_id, item_id, lot_no, origin) values (?, ?, 'L2610-0001', 'RECEIPT') returning id", [$t['tenant'], $item])->id;
        $doc = (string) $db->selectOne(
            "insert into inventory_documents (tenant_id, plant_id, doc_type, doc_no, status, effective_date, production_date, calendar_date, posted_at, posted_by, idempotency_key)
             values (?, ?, 'RM_RECEIPT', 'RCV-P1-2026-0001', 'POSTED', current_date, current_date, current_date, now(), ?, 'fixture-receipt-1') returning id",
            [$t['tenant'], $plant, $t['actor']],
        )->id;
        foreach ([[1, $supplier, -10], [2, $rm, 10]] as [$leg, $location, $qty]) {
            $db->insert(
                "insert into inventory_movements (tenant_id, document_id, leg_no, movement_code, plant_id, location_id, item_id, lot_id, stock_status, uom_id, qty_signed)
                 values (?, ?, ?, 'RCPT_SUPPLIER', ?, ?, ?, ?, 'UNRESTRICTED', ?, ?)",
                [$t['tenant'], $doc, $leg, $plant, $location, $item, $lot, $pcs, $qty],
            );
        }
        $db->insert("insert into stock_balances (tenant_id, plant_id, location_id, item_id, lot_id, stock_status, uom_id, qty) values (?, ?, ?, ?, ?, 'UNRESTRICTED', ?, 10)",
            [$t['tenant'], $plant, $rm, $item, $lot, $pcs]);

        return compact('plant', 'pcs', 'item', 'rm', 'supplier', 'lot', 'doc');
    });
}

it('keeps posted movements append-only for the runtime role (BR-INV-02)', function () {
    $f = ledgerFixture($this->t);
    expect(fn () => ledgerAsApp($this->t, fn (EoraPostgresConnection $db) => $db->update('update inventory_movements set qty_signed = 99 where document_id = ?', [$f['doc']])))
        ->toThrow(QueryException::class, 'permission denied');
    expect(fn () => ledgerAsApp($this->t, fn (EoraPostgresConnection $db) => $db->delete('delete from inventory_movements where document_id = ?', [$f['doc']])))
        ->toThrow(QueryException::class, 'permission denied');
    expect(fn () => ledgerAsApp($this->t, fn (EoraPostgresConnection $db) => $db->delete('delete from stock_balances')))
        ->toThrow(QueryException::class, 'permission denied');
    expect(fn () => ledgerAsApp($this->t, fn (EoraPostgresConnection $db) => $db->update("update stock_balances set stock_status = 'BLOCKED'")))
        ->toThrow(QueryException::class, 'permission denied'); // only the quantity of a balance changes
})->group('integration', 'inventory');

it('never holds a negative or a virtual balance, nor a movement of zero or with an unknown code (BR-INV-04, D-084)', function () {
    $f = ledgerFixture($this->t);
    expect(fn () => ledgerAsApp($this->t, fn (EoraPostgresConnection $db) => $db->update('update stock_balances set qty = -1')))->toThrow(QueryException::class, 'check');
    expect(fn () => ledgerAsApp($this->t, fn (EoraPostgresConnection $db) => $db->insert(
        "insert into stock_balances (tenant_id, plant_id, location_id, item_id, lot_id, stock_status, uom_id, qty) values (?, ?, ?, ?, ?, 'UNRESTRICTED', ?, 1)",
        [$this->t['tenant'], $f['plant'], $f['supplier'], $f['item'], $f['lot'], $f['pcs']],
    )))->toThrow(QueryException::class, 'virtual location holds no balance');
    // one balance per key, also for items without lot control (lot NULL)
    $noLot = fn () => ledgerAsApp($this->t, fn (EoraPostgresConnection $db) => $db->insert(
        "insert into stock_balances (tenant_id, plant_id, location_id, item_id, lot_id, stock_status, uom_id, qty) values (?, ?, ?, ?, null, 'UNRESTRICTED', ?, 1)",
        [$this->t['tenant'], $f['plant'], $f['rm'], $f['item'], $f['pcs']],
    ));
    $noLot();
    expect($noLot)->toThrow(QueryException::class, 'duplicate key');

    $leg = fn (int $no, string $code, float $qty) => fn () => ledgerAsApp($this->t, fn (EoraPostgresConnection $db) => $db->insert(
        "insert into inventory_movements (tenant_id, document_id, leg_no, movement_code, plant_id, location_id, item_id, stock_status, uom_id, qty_signed)
         values (?, ?, ?, ?, ?, ?, ?, 'UNRESTRICTED', ?, ?)",
        [$this->t['tenant'], $f['doc'], $no, $code, $f['plant'], $f['rm'], $f['item'], $f['pcs'], $qty],
    ));
    expect($leg(3, 'RCPT_SUPPLIER', 0))->toThrow(QueryException::class, 'check');
    expect($leg(3, 'WIP_MOVE', 1))->toThrow(QueryException::class, 'check');
    expect($leg(1, 'RCPT_SUPPLIER', 1))->toThrow(QueryException::class, 'duplicate key'); // leg numbers are unique per document
})->group('integration', 'inventory');

it('fixes a posted document except POSTED → REVERSED, and reverses a document once (ADR-0002 §5)', function () {
    $f = ledgerFixture($this->t);
    expect(fn () => ledgerAsApp($this->t, fn (EoraPostgresConnection $db) => $db->update("update inventory_documents set note = 'edit' where id = ?", [$f['doc']])))
        ->toThrow(QueryException::class, 'a posted inventory document never changes');

    $reversal = fn (string $key, string $status) => ledgerAsApp($this->t, fn (EoraPostgresConnection $db) => $db->selectOne(
        "insert into inventory_documents (tenant_id, plant_id, doc_type, status, effective_date, reverses_document_id, requested_by, idempotency_key)
         values (?, ?, 'REVERSAL', ?, current_date, ?, ?, ?) returning id",
        [$this->t['tenant'], $f['plant'], $status, $f['doc'], $this->t['actor'], $key],
    )->id);
    $first = $reversal('fixture-reversal-1', 'SUBMITTED');
    expect(fn () => $reversal('fixture-reversal-2', 'SUBMITTED'))->toThrow(QueryException::class, 'duplicate key');
    // a rejected request does not count
    ledgerAsApp($this->t, fn (EoraPostgresConnection $db) => $db->update(
        "update inventory_documents set status = 'REJECTED', reject_reason = 'Wrong lot', decided_by = ?, decided_at = now() where id = ?", [$this->t['actor'], $first],
    ));
    $reversal('fixture-reversal-3', 'SUBMITTED');

    ledgerAsApp($this->t, fn (EoraPostgresConnection $db) => $db->update("update inventory_documents set status = 'REVERSED' where id = ?", [$f['doc']]));
    expect(fn () => ledgerAsApp($this->t, fn (EoraPostgresConnection $db) => $db->update("update inventory_documents set status = 'POSTED' where id = ?", [$f['doc']])))
        ->toThrow(QueryException::class, 'a reversed inventory document never changes');
    // a posted document needs its number and dates
    expect(fn () => ledgerAsApp($this->t, fn (EoraPostgresConnection $db) => $db->insert(
        "insert into inventory_documents (tenant_id, plant_id, doc_type, status, effective_date, idempotency_key) values (?, ?, 'RM_RECEIPT', 'POSTED', current_date, 'fixture-receipt-2')",
        [$this->t['tenant'], $f['plant']],
    )))->toThrow(QueryException::class, 'check');
})->group('integration', 'inventory');

it('keeps a split lot on its parent\'s item and the ledger inside the tenant', function () {
    $f = ledgerFixture($this->t);
    ledgerAsApp($this->t, fn (EoraPostgresConnection $db) => $db->insert(
        "insert into lots (tenant_id, item_id, lot_no, origin, parent_lot_id) values (?, ?, 'L2610-0002', 'SPLIT', ?)", [$this->t['tenant'], $f['item'], $f['lot']],
    ));
    expect(fn () => ledgerAsApp($this->t, fn (EoraPostgresConnection $db) => $db->insert(
        "insert into lots (tenant_id, item_id, lot_no, origin) values (?, ?, 'L2610-0003', 'SPLIT')", [$this->t['tenant'], $f['item']],
    )))->toThrow(QueryException::class, 'check'); // a split lot names its parent

    $other = TenantFixtures::provision(1);
    $seen = ledgerAsApp($other, fn (EoraPostgresConnection $db) => $db->selectOne(
        'select (select count(*) from lots) as lots, (select count(*) from inventory_documents) as docs, (select count(*) from inventory_movements) as legs, (select count(*) from stock_balances) as balances',
    ));
    expect($seen)->lots->toBe(0)->docs->toBe(0)->legs->toBe(0)->balances->toBe(0);
    expect(fn () => ledgerAsApp($other, fn (EoraPostgresConnection $db) => $db->insert(
        "insert into lots (tenant_id, item_id, lot_no, origin) values (?, ?, 'X-1', 'RECEIPT')", [$this->t['tenant'], $f['item']],
    )))->toThrow(QueryException::class);
})->group('integration', 'inventory');

it('maps the M3-D1 inventory permissions to the role templates', function () {
    $granted = AccessFixtures::run($this->t, fn (EoraPostgresConnection $db) => array_column($db->select(
        "select r.code || ':' || rp.permission_code as g from role_permissions rp join roles r on r.id = rp.role_id
          where rp.permission_code like 'materials.receipt.%' or rp.permission_code like 'materials.incoming_release.%'
             or rp.permission_code like 'materials.transfer.%' or rp.permission_code like 'inventory.stock.%'",
    ), 'g'));
    expect($granted)->toContain('MAT:materials.receipt.post', 'QCI:materials.incoming_release.post', 'FGW:materials.transfer.post', 'OPR:inventory.stock.view', 'AUD:inventory.stock.view')
        ->not->toContain('PPC:materials.receipt.post', 'MAT:materials.incoming_release.post', 'MAD:inventory.stock.view', 'APR:inventory.stock.view');
})->group('integration', 'inventory');
