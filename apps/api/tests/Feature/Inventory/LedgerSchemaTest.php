<?php

declare(strict_types=1);

use App\Platform\Database\EoraPostgresConnection;
use App\Platform\Tenancy\TenantContext;
use App\Platform\Tenancy\TenantTransaction;
use Illuminate\Database\QueryException;
use Illuminate\Support\Str;
use Tests\Support\AccessFixtures;
use Tests\Support\TenantFixtures;

/*
 * M3 step 1 (doc 32 §1.1, D-096; ADR-0002, ADR-0014): what the database guarantees about the ledger by itself, as the
 * runtime role (never a superuser; checks deferred to the commit surface as a PDOException): append-only movements, no negative or virtual balance, posted documents fixed,
 * adjustments and reversals decided by a second person, a reversal that negates every leg once, legs only with their
 * posting, tenant isolation, and the M3-D1 permissions in the role templates.
 */

beforeEach(function () {
    $this->t = TenantFixtures::provision(1);
    AccessFixtures::roles($this->t);
});

/** Runs $work as the runtime role (eora_app) in the tenant's signed context. */
function ledgerAsApp(array $t, Closure $work): mixed
{
    return ledgerAsTenantActor($t, $t['actor'], $work);
}

/** As ledgerAsApp, signed for another actor of the tenant (a second person). */
function ledgerAsTenantActor(array $t, string $actor, Closure $work): mixed
{
    return app(TenantTransaction::class)->run(TenantContext::forTenant($t['tenant'], $actor), $work);
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
    // lot control (M3-D4): a lot-controlled item is held by lot; an item without lot control has one balance per key, lot NULL
    $balance = fn (string $item, ?string $lot) => fn () => ledgerAsApp($this->t, fn (EoraPostgresConnection $db) => $db->insert(
        "insert into stock_balances (tenant_id, plant_id, location_id, item_id, lot_id, stock_status, uom_id, qty) values (?, ?, ?, ?, ?, 'UNRESTRICTED', ?, 1)",
        [$this->t['tenant'], $f['plant'], $f['rm'], $item, $lot, $f['pcs']],
    ));
    expect($balance($f['item'], null))->toThrow(QueryException::class, 'a lot-controlled item moves by lot');
    $oil = ledgerAsApp($this->t, fn (EoraPostgresConnection $db) => (string) $db->selectOne(
        "insert into items (tenant_id, code, name, item_type, base_uom_id, lot_controlled) values (?, 'OIL-1', 'Oil', 'CONSUMABLE', ?, false) returning id", [$this->t['tenant'], $f['pcs']],
    )->id);
    $balance($oil, null)();
    expect($balance($oil, null))->toThrow(QueryException::class, 'duplicate key');
    // the flag of an item with stock history stays as it is
    expect(fn () => ledgerAsApp($this->t, fn (EoraPostgresConnection $db) => $db->update('update items set lot_controlled = false where id = ?', [$f['item']])))
        ->toThrow(QueryException::class, 'lot control is fixed');

    $leg = fn (int $no, string $code, float $qty) => fn () => ledgerAsApp($this->t, fn (EoraPostgresConnection $db) => $db->insert(
        "insert into inventory_movements (tenant_id, document_id, leg_no, movement_code, plant_id, location_id, item_id, lot_id, stock_status, uom_id, qty_signed)
         values (?, ?, ?, ?, ?, ?, ?, ?, 'UNRESTRICTED', ?, ?)",
        [$this->t['tenant'], $f['doc'], $no, $code, $f['plant'], $f['rm'], $f['item'], $f['lot'], $f['pcs'], $qty],
    ));
    expect($leg(3, 'RCPT_SUPPLIER', 0))->toThrow(QueryException::class, 'check');
    expect($leg(3, 'WIP_MOVE', 1))->toThrow(QueryException::class, 'check');
    expect($leg(1, 'RCPT_SUPPLIER', 1))->toThrow(QueryException::class, 'duplicate key'); // leg numbers are unique per document
})->group('integration', 'inventory');

it('reverses a posted document only through a posted reversal by a second person that negates every leg once (ADR-0002 §5, D-060)', function () {
    $f = ledgerFixture($this->t);
    $checker = Str::uuid()->toString();
    expect(fn () => ledgerAsApp($this->t, fn (EoraPostgresConnection $db) => $db->update("update inventory_documents set note = 'edit' where id = ?", [$f['doc']])))
        ->toThrow(QueryException::class, 'a posted inventory document never changes');
    // marking it reversed without a posted reversal is refused
    expect(fn () => ledgerAsApp($this->t, fn (EoraPostgresConnection $db) => $db->update("update inventory_documents set status = 'REVERSED' where id = ?", [$f['doc']])))
        ->toThrow(QueryException::class, 'a document is reversed by a posted reversal');

    $request = fn (string $key) => ledgerAsApp($this->t, fn (EoraPostgresConnection $db) => (string) $db->selectOne(
        "insert into inventory_documents (tenant_id, plant_id, doc_type, status, effective_date, reverses_document_id, requested_by, note, idempotency_key)
         values (?, ?, 'REVERSAL', 'SUBMITTED', current_date, ?, ?, 'Wrong supplier', ?) returning id",
        [$this->t['tenant'], $f['plant'], $f['doc'], $this->t['actor'], $key],
    )->id);
    $first = $request('fixture-reversal-1');
    expect(fn () => $request('fixture-reversal-2'))->toThrow(QueryException::class, 'duplicate key'); // one reversal per document
    // the requester never decides; a second person may reject, and a rejected request does not count
    $decide = fn (string $actor, string $id, string $set, array $bindings = []) => fn () => ledgerAsTenantActor($this->t, $actor, fn (EoraPostgresConnection $db) => $db->update(
        "update inventory_documents set {$set} where id = ?", [...$bindings, $id],
    ));
    expect($decide($this->t['actor'], $first, "status = 'REJECTED', reject_reason = 'Own call', decided_by = ?, decided_at = now()", [$this->t['actor']]))
        ->toThrow(QueryException::class, 'a request is decided by a second person');
    $decide($checker, $first, "status = 'REJECTED', reject_reason = 'Wrong lot', decided_by = ?, decided_at = now()", [$checker])();
    $second = $request('fixture-reversal-3');

    // the reversal posts: a second person, every leg negated once, then the original is marked reversed
    $post = fn (bool $complete) => fn () => ledgerAsTenantActor($this->t, $checker, function (EoraPostgresConnection $db) use ($f, $second, $checker, $complete) {
        $db->update(
            "update inventory_documents set status = 'POSTED', doc_no = 'REV-P1-2026-0001', production_date = current_date, calendar_date = current_date,
                    posted_at = now(), posted_by = ?, decided_by = ?, decided_at = now() where id = ?",
            [$checker, $checker, $second],
        );
        $legs = $db->select('select * from inventory_movements where document_id = ? order by leg_no', [$f['doc']]);
        foreach ($complete ? $legs : array_slice($legs, 0, 1) as $o) {
            $db->insert(
                'insert into inventory_movements (tenant_id, document_id, leg_no, movement_code, reverses_movement_id, plant_id, location_id, item_id, lot_id, stock_status, uom_id, qty_signed)
                 values (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
                [$o->tenant_id, $second, $o->leg_no, $o->movement_code, $o->id, $o->plant_id, $o->location_id, $o->item_id, $o->lot_id, $o->stock_status, $o->uom_id, -$o->qty_signed],
            );
        }
        $db->update("update inventory_documents set status = 'REVERSED' where id = ?", [$f['doc']]);
    });
    expect($post(false))->toThrow(PDOException::class, 'a reversal negates every leg of its document once');
    $post(true)();
    expect(ledgerAsApp($this->t, fn (EoraPostgresConnection $db) => $db->selectOne('select status from inventory_documents where id = ?', [$f['doc']])))->status->toBe('REVERSED');
    // a reversed document stays as it is, and a reversal is not reversed again
    expect(fn () => ledgerAsApp($this->t, fn (EoraPostgresConnection $db) => $db->update("update inventory_documents set status = 'POSTED' where id = ?", [$f['doc']])))
        ->toThrow(QueryException::class, 'a reversed inventory document never changes');
    expect(fn () => $request('fixture-reversal-4'))->toThrow(QueryException::class, 'only a posted document that is not a reversal can be reversed');
})->group('integration', 'inventory');

it('starts adjustments as requests and never takes legs outside their posting (D-060, ADR-0014)', function () {
    $f = ledgerFixture($this->t);
    // an adjustment cannot be inserted as posted, nor requested in someone else's name
    $insert = fn (string $status, string $requester) => fn () => ledgerAsApp($this->t, fn (EoraPostgresConnection $db) => $db->insert(
        "insert into inventory_documents (tenant_id, plant_id, doc_type, status, effective_date, requested_by, reason_code, idempotency_key)
         values (?, ?, 'STOCK_ADJUSTMENT', ?, current_date, ?, 'DAMAGE', ?)",
        [$this->t['tenant'], $f['plant'], $status, $requester, 'fixture-adj-'.Str::random(8)],
    ));
    expect($insert('POSTED', $this->t['actor']))->toThrow(QueryException::class, 'starts as a request');
    expect($insert('SUBMITTED', Str::uuid()->toString()))->toThrow(QueryException::class, 'a request is made by the signed actor');
    $insert('SUBMITTED', $this->t['actor'])();
    // a submitted adjustment names its reason code
    expect(fn () => ledgerAsApp($this->t, fn (EoraPostgresConnection $db) => $db->insert(
        "insert into inventory_documents (tenant_id, plant_id, doc_type, status, effective_date, requested_by, idempotency_key) values (?, ?, 'STOCK_ADJUSTMENT', 'SUBMITTED', current_date, ?, 'fixture-adj-noreason')",
        [$this->t['tenant'], $f['plant'], $this->t['actor']],
    )))->toThrow(QueryException::class, 'check');

    // a posted document needs its number, dates and legs; legs never join it later
    expect(fn () => ledgerAsApp($this->t, fn (EoraPostgresConnection $db) => $db->insert(
        "insert into inventory_documents (tenant_id, plant_id, doc_type, status, effective_date, idempotency_key) values (?, ?, 'RM_RECEIPT', 'POSTED', current_date, 'fixture-receipt-2')",
        [$this->t['tenant'], $f['plant']],
    )))->toThrow(QueryException::class, 'check');
    expect(fn () => ledgerAsApp($this->t, fn (EoraPostgresConnection $db) => $db->insert(
        "insert into inventory_documents (tenant_id, plant_id, doc_type, doc_no, status, effective_date, production_date, calendar_date, posted_at, posted_by, idempotency_key)
         values (?, ?, 'MAT_TRANSFER', 'TRF-P1-2026-0001', 'POSTED', current_date, current_date, current_date, now(), ?, 'fixture-transfer-1')",
        [$this->t['tenant'], $f['plant'], $this->t['actor']],
    )))->toThrow(PDOException::class, 'a posted inventory document has legs');
    expect(fn () => ledgerAsApp($this->t, fn (EoraPostgresConnection $db) => $db->insert(
        "insert into inventory_movements (tenant_id, document_id, leg_no, movement_code, plant_id, location_id, item_id, lot_id, stock_status, uom_id, qty_signed)
         values (?, ?, 3, 'RCPT_SUPPLIER', ?, ?, ?, ?, 'UNRESTRICTED', ?, 1)",
        [$this->t['tenant'], $f['doc'], $f['plant'], $f['rm'], $f['item'], $f['lot'], $f['pcs']],
    )))->toThrow(PDOException::class, 'legs are written only with the posting of their document');
    // only a reversal links a leg to the leg it reverses
    expect(fn () => ledgerAsApp($this->t, function (EoraPostgresConnection $db) use ($f) {
        $doc = (string) $db->selectOne(
            "insert into inventory_documents (tenant_id, plant_id, doc_type, doc_no, status, effective_date, production_date, calendar_date, posted_at, posted_by, idempotency_key)
             values (?, ?, 'MAT_TRANSFER', 'TRF-P1-2026-0002', 'POSTED', current_date, current_date, current_date, now(), ?, 'fixture-transfer-2') returning id",
            [$this->t['tenant'], $f['plant'], $this->t['actor']],
        )->id;
        $leg = $db->selectOne('select id from inventory_movements where document_id = ? and leg_no = 2', [$f['doc']]);
        $db->insert(
            "insert into inventory_movements (tenant_id, document_id, leg_no, movement_code, reverses_movement_id, plant_id, location_id, item_id, lot_id, stock_status, uom_id, qty_signed)
             values (?, ?, 1, 'TRANSFER_LOC', ?, ?, ?, ?, ?, 'UNRESTRICTED', ?, -1)",
            [$this->t['tenant'], $doc, $leg->id, $f['plant'], $f['rm'], $f['item'], $f['lot'], $f['pcs']],
        );
    }))->toThrow(PDOException::class, 'only a reversal reverses a leg');
})->group('integration', 'inventory');

it('closes and reopens a period only on a request decided by a second person (M3-D8, BR-PER-03)', function () {
    $second = Str::uuid()->toString();
    $period = ledgerAsApp($this->t, fn (EoraPostgresConnection $db) => (string) $db->selectOne(
        "insert into inventory_periods (tenant_id, plant_id, period) values (?, ?, date_trunc('month', current_date)::date) returning id", [$this->t['tenant'], $this->t['plants'][0]],
    )->id);
    $as = fn (string $actor, string $set, array $bindings = []) => fn () => ledgerAsTenantActor($this->t, $actor, fn (EoraPostgresConnection $db) => $db->update(
        "update inventory_periods set {$set} where id = ?", [...$bindings, $period],
    ));
    $me = $this->t['actor'];
    // closing straight away, or in one person's name, is refused
    expect($as($me, "status = 'CLOSED', close_requested_by = ?, close_requested_at = now(), closed_by = ?, closed_at = now()", [$me, $me]))
        ->toThrow(QueryException::class, 'a period goes OPEN');
    expect($as($me, "status = 'CLOSING', close_requested_by = ?, close_requested_at = now()", [$second]))->toThrow(QueryException::class, 'requested by the signed actor');
    $as($me, "status = 'CLOSING', close_requested_by = ?, close_requested_at = now()", [$me])();
    // the requester cannot hand the request to someone else and close it themselves
    expect($as($me, 'close_requested_by = ?', [$second]))->toThrow(QueryException::class, 'a period changes only by its lifecycle');
    expect($as($me, "status = 'CLOSED', closed_by = ?, closed_at = now()", [$me]))->toThrow(QueryException::class, 'closed by a second person');
    $as($second, "status = 'CLOSED', closed_by = ?, closed_at = now()", [$second])();

    // reopen: requested, then decided by someone else
    expect($as($me, "status = 'OPEN', closed_by = null, closed_at = null, close_requested_by = null, close_requested_at = null, reopened_by = ?, reopened_at = now()", [$me]))
        ->toThrow(QueryException::class, 'reopens on a request decided by a second person');
    $as($me, "reopen_requested_by = ?, reopen_requested_at = now(), reopen_reason = 'Missed receipt'", [$me])();
    expect($as($me, "status = 'OPEN', closed_by = null, closed_at = null, close_requested_by = null, close_requested_at = null,
                     reopen_requested_by = null, reopen_requested_at = null, reopen_reason = null, reopened_by = ?, reopened_at = now()", [$me]))
        ->toThrow(QueryException::class, 'reopens on a request decided by a second person');
    $as($second, "status = 'OPEN', closed_by = null, closed_at = null, close_requested_by = null, close_requested_at = null,
                   reopen_requested_by = null, reopen_requested_at = null, reopen_reason = null, reopened_by = ?, reopened_at = now()", [$second])();
    expect(ledgerAsApp($this->t, fn (EoraPostgresConnection $db) => $db->selectOne('select status, reopened_by from inventory_periods where id = ?', [$period])))
        ->status->toBe('OPEN')->reopened_by->toBe($second);
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
