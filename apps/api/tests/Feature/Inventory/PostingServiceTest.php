<?php

declare(strict_types=1);

use App\Modules\Inventory\Application\InventoryPostingService;
use App\Modules\Inventory\Contracts\GenealogyLink;
use App\Modules\Inventory\Contracts\PostingCommand;
use App\Modules\Inventory\Contracts\PostingLeg;
use App\Platform\Database\EoraPostgresConnection;
use App\Platform\Exceptions\DomainProblem;
use App\Platform\Tenancy\TenantContext;
use App\Platform\Tenancy\TenantTransaction;
use Illuminate\Database\QueryException;
use Illuminate\Support\Str;
use Tests\Support\AccessFixtures;
use Tests\Support\TenantFixtures;

/*
 * M3 step 2 (doc 32 §1.2, ADR-0014, doc 04 §Inventory ledger posting specification): InventoryPostingService as the
 * runtime role — numbering, calendar, periods, locking in key order, never negative, idempotent replay, audit and
 * outbox, and the database's commit checks answered as the posting's own refusal.
 */

beforeEach(function () {
    $this->t = TenantFixtures::provision(1);
    AccessFixtures::roles($this->t);
    $this->f = pstFixture($this->t);
});

function pstRun(array $t, Closure $work, ?string $actor = null, ?string $connection = null): mixed
{
    return app(TenantTransaction::class)->run(TenantContext::forTenant($t['tenant'], $actor ?? $t['actor']), $work, $connection);
}

function pstPost(array $t, PostingCommand $command, ?string $actor = null, ?string $connection = null): mixed
{
    return pstRun($t, fn (EoraPostgresConnection $db) => app(InventoryPostingService::class)->post($db, $command), $actor, $connection);
}

/**
 * Calendar (day starts 00:00 in Jakarta), a lot-controlled coil in PCS with one lot, and two racks of one warehouse.
 *
 * @return array<string, string>
 */
function pstFixture(array $t): array
{
    return pstRun($t, function (EoraPostgresConnection $db) use ($t) {
        $plant = $t['plants'][0];
        $db->insert("insert into business_calendars (tenant_id, plant_id, timezone, production_day_starts_at, effective_from) values (?, null, 'Asia/Jakarta', '00:00', '2026-01-01')", [$t['tenant']]);
        $pcs = (string) $db->selectOne("select id from uoms where code = 'PCS'")->id;
        $item = (string) $db->selectOne("insert into items (tenant_id, code, name, item_type, base_uom_id) values (?, 'COIL-1', 'Coil', 'RAW', ?) returning id", [$t['tenant'], $pcs])->id;
        $wh = (string) $db->selectOne("insert into warehouses (tenant_id, plant_id, code, name, warehouse_type) values (?, ?, 'RM', 'Raw', 'RAW_MATERIAL') returning id", [$t['tenant'], $plant])->id;
        $rack = fn (string $code) => (string) $db->selectOne("insert into locations (tenant_id, plant_id, warehouse_id, code, name, location_type) values (?, ?, ?, ?, ?, 'RM_WAREHOUSE') returning id",
            [$t['tenant'], $plant, $wh, $code, "Rack {$code}"])->id;
        $rm1 = $rack('RM-01');
        $rm2 = $rack('RM-02');
        $supplier = (string) $db->selectOne("select id from locations where plant_id = ? and location_type = 'V_SUPPLIER'", [$plant])->id;
        $lot = (string) $db->selectOne("insert into lots (tenant_id, item_id, lot_no, origin) values (?, ?, 'L2610-0001', 'RECEIPT') returning id", [$t['tenant'], $item])->id;
        $today = (string) $db->selectOne("select (now() at time zone 'Asia/Jakarta')::date::text as d")->d;

        return compact('plant', 'pcs', 'item', 'rm1', 'rm2', 'supplier', 'lot', 'today');
    });
}

function pstReceipt(array $f, string $qty, string $key, ?string $effectiveDate = null): PostingCommand
{
    return new PostingCommand('RM_RECEIPT', $f['plant'], $key, [
        new PostingLeg('RCPT_SUPPLIER', $f['supplier'], $f['item'], $f['lot'], 'UNRESTRICTED', '-'.$qty),
        new PostingLeg('RCPT_SUPPLIER', $f['rm1'], $f['item'], $f['lot'], 'UNRESTRICTED', $qty),
    ], effectiveDate: $effectiveDate);
}

function pstTransfer(array $f, string $qty, string $key): PostingCommand
{
    return new PostingCommand('MAT_TRANSFER', $f['plant'], $key, [
        new PostingLeg('TRANSFER_LOC', $f['rm1'], $f['item'], $f['lot'], 'UNRESTRICTED', '-'.$qty),
        new PostingLeg('TRANSFER_LOC', $f['rm2'], $f['item'], $f['lot'], 'UNRESTRICTED', $qty),
    ]);
}

/** @return array<string, string> location id => on-hand of the fixture lot */
function pstBalances(array $t, array $f): array
{
    return pstRun($t, fn (EoraPostgresConnection $db) => array_column(array_map(fn ($r) => (array) $r, $db->select(
        "select location_id::text as l, trim_scale(qty)::text as q from stock_balances where lot_id = ? and stock_status = 'UNRESTRICTED'", [$f['lot']],
    )), 'q', 'l'));
}

it('posts a receipt with its official number, dates, legs, balance, period, audit and outbox (ADR-0014, D-049)', function () {
    $f = $this->f;
    $result = pstPost($this->t, pstReceipt($f, '10', 'receipt-0001'));
    $year = substr($f['today'], 0, 4);
    expect($result)->docNo->toBe("RCV-P1-{$year}-0001")->productionDate->toBe($f['today'])->replayed->toBeFalse();
    expect(pstPost($this->t, pstReceipt($f, '5', 'receipt-0002'))->docNo)->toBe("RCV-P1-{$year}-0002");
    expect(pstBalances($this->t, $f))->toBe([$f['rm1'] => '15']);

    $row = pstRun($this->t, fn (EoraPostgresConnection $db) => $db->selectOne(
        "select d.status, d.posted_by, d.production_date::text as pd, (select count(*) from inventory_movements m where m.document_id = d.id) as legs,
                (select status from inventory_periods where period = date_trunc('month', d.effective_date)::date) as period,
                (select count(*) from audit_events a where a.action = 'inventory_document.posted' and a.entity_id = d.id) as audits,
                (select count(*) from outbox_events o where o.event_type = 'inventory.document_posted' and o.aggregate_id = d.id) as events
           from inventory_documents d where d.id = ?", [$result->documentId],
    ));
    expect($row)->status->toBe('POSTED')->posted_by->toBe($this->t['actor'])->pd->toBe($f['today'])->legs->toBe(2)
        ->period->toBe('OPEN')->audits->toBe(1)->events->toBe(1);
})->group('integration', 'inventory');

it('replays the same request under its key and refuses another one (ADR-0014 §4)', function () {
    $f = $this->f;
    $first = pstPost($this->t, pstReceipt($f, '10', 'receipt-replay'));
    $again = pstPost($this->t, pstReceipt($f, '10', 'receipt-replay'));
    expect($again)->documentId->toBe($first->documentId)->docNo->toBe($first->docNo)->replayed->toBeTrue();
    expect(fn () => pstPost($this->t, pstReceipt($f, '11', 'receipt-replay')))->toThrow(DomainProblem::class, 'Idempotency key reused');
    expect(pstBalances($this->t, $f))->toBe([$f['rm1'] => '10']);
})->group('integration', 'inventory');

it('never makes stock negative and writes nothing of a refused posting (BR-INV-04)', function () {
    $f = $this->f;
    pstPost($this->t, pstReceipt($f, '10', 'receipt-neg'));
    pstPost($this->t, pstTransfer($f, '4', 'transfer-ok'));
    try {
        pstPost($this->t, pstTransfer($f, '7', 'transfer-too-much'));
        $this->fail('expected INSUFFICIENT_STOCK');
    } catch (DomainProblem $e) {
        expect($e->errorCode)->toBe('INSUFFICIENT_STOCK')->and($e->status)->toBe(409)->and($e->detail)->toBe('Only 6 is on hand there; the posting needs 7.');
    }
    expect(pstBalances($this->t, $f))->toBe([$f['rm1'] => '6', $f['rm2'] => '4']);
    expect(pstRun($this->t, fn (EoraPostgresConnection $db) => $db->selectOne("select count(*) as n from inventory_documents where idempotency_key = 'transfer-too-much'")))->n->toBe(0);
})->group('integration', 'inventory');

it('posts only into an open period: this and last month open on first use, older months must exist (BR-PER-01, M3-D8)', function () {
    $f = $this->f;
    $lastMonth = (new DateTimeImmutable($f['today']))->modify('first day of last month')->format('Y-m-d');
    $older = (new DateTimeImmutable($f['today']))->modify('first day of -3 months')->format('Y-m-d');
    pstPost($this->t, pstReceipt($f, '1', 'receipt-last-month', $lastMonth));
    expect(fn () => pstPost($this->t, pstReceipt($f, '1', 'receipt-older', $older)))->toThrow(DomainProblem::class, 'Period closed');
    expect(fn () => pstPost($this->t, pstReceipt($f, '1', 'receipt-tomorrow', (new DateTimeImmutable($f['today']))->modify('+1 day')->format('Y-m-d'))))
        ->toThrow(DomainProblem::class, 'Invalid effective date');
    expect(fn () => pstPost($this->t, pstReceipt($f, '1', 'receipt-no-such-day', substr($f['today'], 0, 4).'-02-31')))->toThrow(DomainProblem::class, 'Invalid effective date');

    // the current month closes (requested, closed by a second person); postings into it stop
    pstPost($this->t, pstReceipt($f, '1', 'receipt-before-close'));
    $period = substr($f['today'], 0, 7).'-01';
    pstRun($this->t, fn (EoraPostgresConnection $db) => $db->update(
        "update inventory_periods set status = 'CLOSING', close_requested_by = ?, close_requested_at = now() where period = ?::date", [$this->t['actor'], $period],
    ));
    $second = Str::uuid()->toString();
    pstRun($this->t, fn (EoraPostgresConnection $db) => $db->update(
        "update inventory_periods set status = 'CLOSED', closed_by = ?, closed_at = now() where period = ?::date", [$second, $period],
    ), $second);
    expect(fn () => pstPost($this->t, pstReceipt($f, '1', 'receipt-after-close')))->toThrow(DomainProblem::class, 'Period closed');
})->group('integration', 'inventory');

it('answers the database\'s ledger checks as its own refusal, and keeps requests out of direct posting', function () {
    $f = $this->f;
    // a receipt without its supplier leg would create stock from nothing (BR-INV-03): refused before the request ends
    try {
        pstPost($this->t, new PostingCommand('RM_RECEIPT', $f['plant'], 'receipt-one-leg', [new PostingLeg('RCPT_SUPPLIER', $f['rm1'], $f['item'], $f['lot'], 'UNRESTRICTED', '3')]));
        $this->fail('expected POSTING_REFUSED');
    } catch (DomainProblem $e) {
        expect($e->errorCode)->toBe('POSTING_REFUSED')->and($e->detail)->toBe('a posting conserves quantity per item, lot and unit');
    }
    expect(fn () => pstPost($this->t, new PostingCommand('STOCK_ADJUSTMENT', $f['plant'], 'adjust-direct', [new PostingLeg('ADJ_PLUS', $f['rm1'], $f['item'], $f['lot'], 'UNRESTRICTED', '1')])))
        ->toThrow(DomainProblem::class, 'Approval required');
    expect(pstBalances($this->t, $f))->toBe([]);
})->group('integration', 'inventory');

it('splits a lot into a child with its genealogy (doc 04 row 7)', function () {
    $f = $this->f;
    pstPost($this->t, pstReceipt($f, '10', 'receipt-split'));
    $child = pstRun($this->t, fn (EoraPostgresConnection $db) => (string) $db->selectOne(
        "insert into lots (tenant_id, item_id, lot_no, origin, parent_lot_id) values (?, ?, 'L2610-0002', 'SPLIT', ?) returning id", [$this->t['tenant'], $f['item'], $f['lot']],
    )->id);
    $split = pstPost($this->t, new PostingCommand('LOT_SPLIT', $f['plant'], 'split-0001', [
        new PostingLeg('LOT_SPLIT_OUT', $f['rm1'], $f['item'], $f['lot'], 'UNRESTRICTED', '-4'),
        new PostingLeg('LOT_SPLIT_IN', $f['rm1'], $f['item'], $child, 'UNRESTRICTED', '4'),
    ], [new GenealogyLink($f['lot'], $child, '4', $f['pcs'], '4', $f['pcs'])]));
    expect($split->docNo)->toStartWith('SPL-P1-');
    // a split invents no other ancestry: an extra link between unrelated lots is refused
    $other = pstRun($this->t, fn (EoraPostgresConnection $db) => (string) $db->selectOne(
        "insert into lots (tenant_id, item_id, lot_no, origin, parent_lot_id) values (?, ?, 'L2610-0003', 'SPLIT', ?) returning id", [$this->t['tenant'], $f['item'], $f['lot']],
    )->id);
    expect(fn () => pstPost($this->t, new PostingCommand('LOT_SPLIT', $f['plant'], 'split-extra-link', [
        new PostingLeg('LOT_SPLIT_OUT', $f['rm1'], $f['item'], $f['lot'], 'UNRESTRICTED', '-1'),
        new PostingLeg('LOT_SPLIT_IN', $f['rm1'], $f['item'], $other, 'UNRESTRICTED', '1'),
    ], [new GenealogyLink($f['lot'], $other, '1', $f['pcs'], '1', $f['pcs']), new GenealogyLink($child, $other, '1', $f['pcs'], '1', $f['pcs'])])))
        ->toThrow(DomainProblem::class, 'Invalid legs');
    // one leg per child lot, so its single edge carries all it received
    expect(fn () => pstPost($this->t, new PostingCommand('LOT_SPLIT', $f['plant'], 'split-twice-in', [
        new PostingLeg('LOT_SPLIT_OUT', $f['rm1'], $f['item'], $f['lot'], 'UNRESTRICTED', '-2'),
        new PostingLeg('LOT_SPLIT_IN', $f['rm1'], $f['item'], $other, 'UNRESTRICTED', '1'),
        new PostingLeg('LOT_SPLIT_IN', $f['rm1'], $f['item'], $other, 'UNRESTRICTED', '1'),
    ], [new GenealogyLink($f['lot'], $other, '1', $f['pcs'], '1', $f['pcs'])])))->toThrow(DomainProblem::class, 'Invalid legs');
    // one parent lot per split document
    expect(fn () => pstPost($this->t, new PostingCommand('LOT_SPLIT', $f['plant'], 'split-two-parents', [
        new PostingLeg('LOT_SPLIT_OUT', $f['rm1'], $f['item'], $f['lot'], 'UNRESTRICTED', '-1'),
        new PostingLeg('LOT_SPLIT_OUT', $f['rm1'], $f['item'], $child, 'UNRESTRICTED', '-1'),
        new PostingLeg('LOT_SPLIT_IN', $f['rm1'], $f['item'], $child, 'UNRESTRICTED', '2'),
    ])))->toThrow(DomainProblem::class, 'One parent lot per split');
    expect(pstBalances($this->t, $f))->toBe([$f['rm1'] => '6']);
    expect(pstRun($this->t, fn (EoraPostgresConnection $db) => $db->selectOne(
        'select trim_scale(qty)::text as q, (select count(*) from genealogy_edges where to_lot_id = ?) as edges from stock_balances where lot_id = ?', [$child, $child],
    )))->q->toBe('4')->edges->toBe(1);
})->group('integration', 'inventory');

it('serializes postings on one balance: the second waits for the first and never takes stock twice (BR-INV-05, doc 20 §8)', function () {
    $f = $this->f;
    pstPost($this->t, pstReceipt($f, '10', 'receipt-race'));
    config(['database.connections.pgsql_second' => config('database.connections.pgsql')]);

    pstRun($this->t, function (EoraPostgresConnection $db) use ($f) {
        app(InventoryPostingService::class)->post($db, pstTransfer($f, '8', 'transfer-race-1'));
        // while the first holds the balance lock, the second waits until its lock timeout and is told the stock is busy
        expect(fn () => pstPost($this->t, pstTransfer($f, '5', 'transfer-race-2'), connection: 'pgsql_second'))->toThrow(DomainProblem::class, 'Stock busy');
    });
    // after the first committed, the second sees what is left
    expect(fn () => pstPost($this->t, pstTransfer($f, '5', 'transfer-race-3'), connection: 'pgsql_second'))->toThrow(DomainProblem::class, 'Insufficient stock');
    expect(pstBalances($this->t, $f))->toBe([$f['rm1'] => '2', $f['rm2'] => '8']);
})->group('integration', 'inventory');

it('reruns a transaction after a deadlock when asked to (ADR-0014 §3)', function () {
    $tries = 0;
    $result = app(TenantTransaction::class)->run(TenantContext::forTenant($this->t['tenant'], $this->t['actor']), function () use (&$tries) {
        if (++$tries === 1) {
            throw new QueryException('pgsql', 'select 1', [], new PDOException('SQLSTATE[40P01]: Deadlock detected: 7 ERROR:  deadlock detected'));
        }

        return 'done';
    }, attempts: 3);
    expect($result)->toBe('done')->and($tries)->toBe(2);
})->group('integration', 'inventory');

it('checks what each leg means: codes per document, sign and place, and status only through its own document (doc 04, D-062)', function () {
    $f = $this->f;
    pstPost($this->t, pstReceipt($f, '10', 'receipt-legs'));
    $refused = fn (PostingCommand $c) => fn () => pstPost($this->t, $c);
    // a transfer that would turn UNRESTRICTED into HOLD
    expect($refused(new PostingCommand('MAT_TRANSFER', $f['plant'], 'transfer-status', [
        new PostingLeg('TRANSFER_LOC', $f['rm1'], $f['item'], $f['lot'], 'UNRESTRICTED', '-1'),
        new PostingLeg('TRANSFER_LOC', $f['rm2'], $f['item'], $f['lot'], 'HOLD', '1'),
    ])))->toThrow(DomainProblem::class, 'Invalid legs');
    // a receipt with an adjustment code, or genealogy it has no reason for
    expect($refused(new PostingCommand('RM_RECEIPT', $f['plant'], 'receipt-code', [
        new PostingLeg('ADJ_MINUS', $f['supplier'], $f['item'], $f['lot'], 'UNRESTRICTED', '-1'),
        new PostingLeg('ADJ_PLUS', $f['rm1'], $f['item'], $f['lot'], 'UNRESTRICTED', '1'),
    ])))->toThrow(DomainProblem::class, 'Invalid legs');
    expect($refused(new PostingCommand('RM_RECEIPT', $f['plant'], 'receipt-genealogy', pstReceipt($f, '1', 'x-unused-key')->legs,
        [new GenealogyLink($f['lot'], $f['lot'], '1', $f['pcs'], '1', $f['pcs'])])))->toThrow(DomainProblem::class, 'Invalid legs');
    // a transfer goes somewhere else
    expect($refused(new PostingCommand('MAT_TRANSFER', $f['plant'], 'transfer-in-place', [
        new PostingLeg('TRANSFER_LOC', $f['rm1'], $f['item'], $f['lot'], 'UNRESTRICTED', '-1'),
        new PostingLeg('TRANSFER_LOC', $f['rm1'], $f['item'], $f['lot'], 'UNRESTRICTED', '1'),
    ])))->toThrow(DomainProblem::class, 'Invalid legs');
    // documents of M4 are not posted yet
    expect($refused(new PostingCommand('PRODUCTION_POSTING', $f['plant'], 'production-early', [
        new PostingLeg('OUTPUT_GOOD', $f['rm1'], $f['item'], $f['lot'], 'UNRESTRICTED', '1'),
    ])))->toThrow(DomainProblem::class, 'Not available yet');

    // the incoming release (D-062): an item that needs it is received BLOCKED (never UNRESTRICTED), then released in place
    pstRun($this->t, fn (EoraPostgresConnection $db) => $db->update('update items set incoming_release_required = true where id = ?', [$f['item']]));
    expect($refused(pstReceipt($f, '1', 'receipt-unrestricted-but-needs-release')))->toThrow(DomainProblem::class, 'Invalid legs');
    pstPost($this->t, new PostingCommand('RM_RECEIPT', $f['plant'], 'receipt-blocked', [
        new PostingLeg('RCPT_SUPPLIER', $f['supplier'], $f['item'], $f['lot'], 'BLOCKED', '-3'),
        new PostingLeg('RCPT_SUPPLIER', $f['rm2'], $f['item'], $f['lot'], 'BLOCKED', '3'),
    ]));
    expect($refused(new PostingCommand('INCOMING_RELEASE', $f['plant'], 'release-moving', [
        new PostingLeg('INCOMING_RELEASE', $f['rm2'], $f['item'], $f['lot'], 'BLOCKED', '-3'),
        new PostingLeg('INCOMING_RELEASE', $f['rm1'], $f['item'], $f['lot'], 'UNRESTRICTED', '3'),
    ])))->toThrow(DomainProblem::class, 'Invalid legs');
    $release = pstPost($this->t, new PostingCommand('INCOMING_RELEASE', $f['plant'], 'release-0001', [
        new PostingLeg('INCOMING_RELEASE', $f['rm2'], $f['item'], $f['lot'], 'BLOCKED', '-3'),
        new PostingLeg('INCOMING_RELEASE', $f['rm2'], $f['item'], $f['lot'], 'UNRESTRICTED', '3'),
    ]));
    expect($release->docNo)->toStartWith('REL-P1-');
    expect(pstBalances($this->t, $f))->toBe([$f['rm1'] => '10', $f['rm2'] => '3']);
})->group('integration', 'inventory');
