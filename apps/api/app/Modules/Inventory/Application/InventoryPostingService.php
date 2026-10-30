<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Application;

use App\Modules\Inventory\Contracts\PostingCommand;
use App\Modules\Inventory\Contracts\PostingLeg;
use App\Modules\Inventory\Contracts\PostingResult;
use App\Platform\Audit\AuditRecorder;
use App\Platform\Calendar\BusinessCalendar;
use App\Platform\Database\EoraPostgresConnection;
use App\Platform\Exceptions\DomainProblem;
use App\Platform\Outbox\OutboxPublisher;
use DateTimeImmutable;
use Illuminate\Database\QueryException;
use LogicException;
use stdClass;

/**
 * The only writer of the ledger (ADR-0014, BR-INV-01; doc 04 §Inventory ledger posting specification). In the
 * caller's tenant transaction:
 *
 *  1. idempotency — the same key replays the stored result, another request under it is refused (409);
 *  2. calendar — production date, shift and calendar date at the plant (ADR-0012, BR-PER-04);
 *  3. period — the month of the effective date must be OPEN (BR-PER-01); the current and previous month open on
 *     first use (M3-D8), any other month must already exist;
 *  4. balances — every physical balance key is created when missing and locked FOR UPDATE in one sorted order
 *     (BR-INV-05), then checked: never negative (BR-INV-04), and a decrease of UNRESTRICTED stock never below its
 *     active allocations (BR-INV-08);
 *  5. official number per tenant + plant + type + year (D-049), document, legs, genealogy, balances;
 *  6. audit and outbox in the same transaction; the database's commit checks run before returning, so a refused
 *     posting answers here, not at the end of the request.
 *
 * Authorization is the caller's: the service posts what it is asked to post. Deadlocks and serialization failures are
 * retried by the caller's transaction (TenantTransaction attempts), safe because the posting is idempotent.
 */
final class InventoryPostingService
{
    /** Requests decided by a second person (D-060): never posted directly. */
    private const array REQUESTED = ['STOCK_ADJUSTMENT', 'REVERSAL'];

    /**
     * Documents posted directly in M3 (doc 32 M3-D2) with the codes of their legs; the others come with their documents in
     * M4 (issue, custody, return, production, QC, FG receipt, pick, shipment, scrap, rework).
     */
    private const array DIRECT = [
        'RM_RECEIPT' => ['RCPT_SUPPLIER'],
        'INCOMING_RELEASE' => ['INCOMING_RELEASE'],
        'MAT_TRANSFER' => ['TRANSFER_LOC'],
        'LOT_SPLIT' => ['LOT_SPLIT_OUT', 'LOT_SPLIT_IN'],
    ];

    private const array PREFIX = [
        'RM_RECEIPT' => 'RCV', 'INCOMING_RELEASE' => 'REL', 'MAT_ISSUE' => 'ISS', 'CUSTODY_ACCEPT' => 'CUS', 'MAT_TRANSFER' => 'TRF',
        'MAT_RETURN' => 'RET', 'LOT_SPLIT' => 'SPL', 'PRODUCTION_POSTING' => 'PRD', 'QC_DISPOSITION' => 'QCD', 'FG_RECEIPT' => 'FGR',
        'PICK' => 'PCK', 'SHIPMENT' => 'SHP', 'SCRAP_DISPOSITION' => 'SCR', 'REWORK_POSTING' => 'RWK', 'STOCK_ADJUSTMENT' => 'ADJ', 'REVERSAL' => 'REV',
    ];

    public function __construct(
        private readonly BusinessCalendar $calendar,
        private readonly AuditRecorder $audit,
        private readonly OutboxPublisher $outbox,
    ) {}

    public function post(EoraPostgresConnection $db, PostingCommand $command): PostingResult
    {
        if ($db->transactionLevel() === 0) {
            throw new LogicException('Postings run inside the tenant transaction.');
        }
        if (! isset(self::PREFIX[$command->docType])) {
            throw new DomainProblem(422, 'UNKNOWN_DOCUMENT_TYPE', 'Unknown document type', "There is no inventory document type {$command->docType}.");
        }
        if (in_array($command->docType, self::REQUESTED, true)) {
            throw new DomainProblem(409, 'APPROVAL_REQUIRED', 'Approval required', 'Adjustments and reversals are requested and posted by a second person.');
        }

        if (! isset(self::DIRECT[$command->docType])) {
            throw new DomainProblem(422, 'DOCUMENT_TYPE_NOT_AVAILABLE', 'Not available yet', "{$command->docType} is posted with its own document from M4.");
        }
        $this->checkLegs($db, $command);

        // a split takes one parent lot per document, so the parent's quantity out equals its children's in (conservation)
        if ($command->docType === 'LOT_SPLIT') {
            $parents = array_unique(array_map(static fn (PostingLeg $l) => $l->lotId, array_filter($command->legs, static fn (PostingLeg $l) => $l->movementCode === 'LOT_SPLIT_OUT')));
            if (count($parents) !== 1) {
                throw new DomainProblem(422, 'LOT_SPLIT_ONE_PARENT', 'One parent lot per split', 'A lot split takes one parent lot; split another lot in its own document.');
            }
        }

        $replay = $this->replay($db, $command);
        if ($replay !== null) {
            return $replay;
        }

        $db->statement("set local lock_timeout = '3s'");
        $db->statement("set local statement_timeout = '10s'");

        try {
            $result = $this->write($db, $command);
            // the database checks the whole posting at commit (conservation, balance = ledger, codes, lots, genealogy);
            // run them now so a refusal is this posting's answer
            $db->statement('set constraints all immediate');
        } catch (QueryException $e) {
            throw $this->refusal($e) ?? $e;
        }

        return $result;
    }

    /**
     * What each leg means (doc 04 rows 1, 4, 7 and the incoming release, D-062): its code belongs to the document, its
     * sign and place fit the code, and only the incoming release changes status (BLOCKED → UNRESTRICTED in place).
     */
    private function checkLegs(EoraPostgresConnection $db, PostingCommand $command): void
    {
        $types = [];
        $ids = array_values(array_unique(array_map(static fn (PostingLeg $l) => $l->locationId, $command->legs)));
        foreach ($db->select('select id, location_type from locations where id = any(?::uuid[])', ['{'.implode(',', $ids).'}']) as $row) {
            $types[(string) $row->id] = (string) $row->location_type;
        }
        $refuse = static fn (string $why) => throw new DomainProblem(422, 'INVALID_LEGS', 'Invalid legs', $why);
        $statuses = [];
        foreach ($command->legs as $leg) {
            $type = $types[$leg->locationId] ?? throw new DomainProblem(422, 'UNKNOWN_LOCATION', 'Unknown location', 'A leg names a location that does not exist.');
            $out = str_starts_with($leg->qty, '-');
            if (! in_array($leg->movementCode, self::DIRECT[$command->docType], true)) {
                $refuse("A {$command->docType} does not post {$leg->movementCode}.");
            }
            if ($leg->reversesMovementId !== null) {
                $refuse('Only a reversal reverses a leg.');
            }
            match ($command->docType) {
                'RM_RECEIPT' => ($out ? $type === 'V_SUPPLIER' : ($type === 'RM_WAREHOUSE' && in_array($leg->stockStatus, ['UNRESTRICTED', 'BLOCKED'], true)))
                    || $refuse('A receipt moves from the supplier into a raw-material location, UNRESTRICTED or BLOCKED.'),
                'INCOMING_RELEASE' => ($leg->stockStatus === ($out ? 'BLOCKED' : 'UNRESTRICTED') && $type === 'RM_WAREHOUSE')
                    || $refuse('An incoming release turns BLOCKED raw material into UNRESTRICTED stock in place, in a raw-material location.'),
                default => ! str_starts_with($type, 'V_') || $refuse('A transfer or split moves between physical locations.'),
            };
            if ($command->docType !== 'INCOMING_RELEASE') {
                $statuses[$leg->stockStatus] = true;
            }
            if ($command->docType === 'INCOMING_RELEASE') {
                $statuses[$leg->locationId] = true;
            }
        }
        // a receipt, transfer or split keeps the status of what it moves; a release stays at one place
        if (count($statuses) !== 1) {
            $refuse($command->docType === 'INCOMING_RELEASE' ? 'An incoming release stays at one location.' : 'Stock keeps its status when it moves; a status changes only by its own document.');
        }
        if ($command->genealogy !== [] && $command->docType !== 'LOT_SPLIT') {
            $refuse('Only a lot split records genealogy in M3.');
        }
        // a split's links are exactly its parent → child pairs, with the child leg's quantity (one link per child)
        $children = [];
        foreach ($command->legs as $leg) {
            if ($leg->movementCode === 'LOT_SPLIT_IN') {
                isset($children[(string) $leg->lotId]) && $refuse('A split moves into each child lot with one leg.');
                $children[(string) $leg->lotId] = $leg->qty;
            }
        }
        $parents = array_values(array_unique(array_map(static fn (PostingLeg $l) => (string) $l->lotId,
            array_filter($command->legs, static fn (PostingLeg $l) => $l->movementCode === 'LOT_SPLIT_OUT'))));
        $linked = [];
        foreach ($command->genealogy as $g) {
            if (! in_array($g->fromLotId, $parents, true) || ! isset($children[$g->toLotId]) || isset($linked[$g->toLotId])
                || bccomp(self::num($g->qtyFrom), self::num($children[$g->toLotId]), 6) !== 0 || bccomp(self::num($g->qtyTo), self::num($children[$g->toLotId]), 6) !== 0) {
                $refuse('A split links its parent to each of its children once, with the child\'s quantity.');
            }
            $linked[$g->toLotId] = true;
        }
    }

    private function replay(EoraPostgresConnection $db, PostingCommand $command): ?PostingResult
    {
        $stored = $db->selectOne('select request_hash, response from idempotency_records where idempotency_key = ?', [$command->idempotencyKey]);
        if ($stored === null) {
            return null;
        }
        if ($stored->request_hash !== $command->hash()) {
            throw new DomainProblem(409, 'IDEMPOTENCY_KEY_REUSED', 'Idempotency key reused', 'This key was used for another request. Send a new key for a new posting.');
        }
        $r = json_decode((string) $stored->response, true, flags: JSON_THROW_ON_ERROR);

        return new PostingResult($r['documentId'], $r['docNo'], $r['productionDate'], replayed: true);
    }

    private function write(EoraPostgresConnection $db, PostingCommand $command): PostingResult
    {
        $now = new DateTimeImmutable((string) $db->selectOne('select now()::text as t')->t);
        $time = $this->calendar->resolve($db, $command->plantId, $now);
        $effective = $command->effectiveDate ?? $time->productionDate;
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $effective) !== 1 || $effective > $time->productionDate) {
            throw new DomainProblem(422, 'INVALID_EFFECTIVE_DATE', 'Invalid effective date', 'A posting belongs to today or an earlier day of an open period.');
        }
        // a backdated posting keeps the day it belongs to; the shift is resolved only for today's postings
        $backdated = $effective !== $time->productionDate;
        $this->requireOpenPeriod($db, $command->plantId, $effective, $time->calendarDate);

        $items = $this->items($db, $command->legs);
        $physical = $this->physicalLocations($db, $command->plantId, $command->legs);
        $deltas = [];
        foreach ($command->legs as $leg) {
            if (isset($physical[$leg->locationId])) {
                $key = $this->balanceKey($command->plantId, $leg, $items[$leg->itemId]->base_uom_id);
                $deltas[$key] = bcadd($deltas[$key] ?? '0', self::num($leg->qty), 6);
            }
        }
        $balances = $this->lockBalances($db, $deltas);

        $plantCode = (string) $db->selectOne('select code from plants where id = ?', [$command->plantId])->code;
        $year = (int) substr($effective, 0, 4);
        $number = (int) $db->selectOne(
            'insert into document_counters (tenant_id, plant_id, doc_type, year, last_no) values ((select c.tenant from ctx_api.context() c), ?, ?, ?, 1)
             on conflict (tenant_id, plant_id, doc_type, year) do update set last_no = document_counters.last_no + 1 returning last_no',
            [$command->plantId, $command->docType, $year],
        )->last_no;
        $docNo = sprintf('%s-%s-%d-%04d', self::PREFIX[$command->docType], $plantCode, $year, $number);

        $documentId = (string) $db->selectOne(
            'insert into inventory_documents (tenant_id, plant_id, doc_type, doc_no, status, effective_date, production_date, calendar_date, shift_id, shift_date,
                                              posted_at, posted_by, note, source_type, source_id, idempotency_key)
             values ((select c.tenant from ctx_api.context() c), ?, ?, ?, \'POSTED\', ?, ?, ?, ?, ?, now(), (select c.actor from ctx_api.context() c), ?, ?, ?, ?)
             returning id',
            [$command->plantId, $command->docType, $docNo, $effective, $effective, $time->calendarDate, $backdated ? null : $time->shiftId,
                $backdated ? null : $time->shiftDate, $command->note, $command->sourceType, $command->sourceId, $command->idempotencyKey],
        )->id;

        foreach ($command->legs as $i => $leg) {
            $db->insert(
                'insert into inventory_movements (tenant_id, document_id, leg_no, movement_code, reverses_movement_id, plant_id, location_id, item_id, lot_id,
                                                  stock_status, uom_id, qty_signed, entered_qty, entered_uom_id, uom_conversion_id)
                 values ((select c.tenant from ctx_api.context() c), ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
                [$documentId, $i + 1, $leg->movementCode, $leg->reversesMovementId, $command->plantId, $leg->locationId, $leg->itemId, $leg->lotId,
                    $leg->stockStatus, $items[$leg->itemId]->base_uom_id, $leg->qty, $leg->enteredQty, $leg->enteredUomId, $leg->uomConversionId],
            );
        }
        foreach ($command->genealogy as $g) {
            $db->insert(
                'insert into genealogy_edges (tenant_id, from_lot_id, to_lot_id, document_id, qty_from, uom_from, qty_to, uom_to)
                 values ((select c.tenant from ctx_api.context() c), ?, ?, ?, ?, ?, ?, ?)',
                [$g->fromLotId, $g->toLotId, $documentId, $g->qtyFrom, $g->uomFrom, $g->qtyTo, $g->uomTo],
            );
        }
        foreach ($balances as $key => $balance) {
            $db->update('update stock_balances set qty = ? where id = ?', [$balance['qty'], $balance['id']]);
        }

        $this->audit->record($db, 'inventory_document.posted', 'inventory_document', $documentId, null,
            (array) $db->selectOne('select * from inventory_documents where id = ?', [$documentId]),
            ['docType' => $command->docType, 'docNo' => $docNo, 'legs' => count($command->legs)], $command->plantId);
        $this->outbox->publish($db, 'inventory.document_posted', 'inventory_document', $documentId,
            ['docType' => $command->docType, 'plantId' => $command->plantId]);

        $result = new PostingResult($documentId, $docNo, $effective);
        $db->insert(
            'insert into idempotency_records (tenant_id, idempotency_key, command, request_hash, response) values ((select c.tenant from ctx_api.context() c), ?, ?, ?, ?::jsonb)',
            [$command->idempotencyKey, 'inventory.post', $command->hash(), json_encode($result->toArray(), JSON_THROW_ON_ERROR)],
        );

        return $result;
    }

    /** BR-PER-01, M3-D8: the period of the day must be OPEN; this and last month open on first use. */
    private function requireOpenPeriod(EoraPostgresConnection $db, string $plantId, string $effective, string $today): void
    {
        $month = substr($effective, 0, 7).'-01';
        $current = substr($today, 0, 7).'-01';
        $previous = (new DateTimeImmutable($current))->modify('-1 month')->format('Y-m-d');
        if (in_array($month, [$current, $previous], true)) {
            $db->insert(
                'insert into inventory_periods (tenant_id, plant_id, period) values ((select c.tenant from ctx_api.context() c), ?, ?::date) on conflict (tenant_id, plant_id, period) do nothing',
                [$plantId, $month],
            );
        }
        // FOR SHARE: a close of this period (an UPDATE) waits for the posting, or the posting sees the close
        $status = $db->selectOne('select status from inventory_periods where plant_id = ? and period = ?::date for share', [$plantId, $month])?->status;
        if ($status !== 'OPEN') {
            throw new DomainProblem(409, 'PERIOD_CLOSED', 'Period closed', "The inventory period of {$effective} is not open for this plant. Post a correction in the open period instead.");
        }
    }

    /**
     * @param  list<PostingLeg>  $legs
     * @return array<string, stdClass> item id => lot_controlled, base_uom_id
     */
    private function items(EoraPostgresConnection $db, array $legs): array
    {
        $ids = array_values(array_unique(array_map(static fn (PostingLeg $l) => $l->itemId, $legs)));
        $rows = $db->select('select id, lot_controlled, base_uom_id from items where id = any(?::uuid[])', ['{'.implode(',', $ids).'}']);
        $items = [];
        foreach ($rows as $row) {
            $items[(string) $row->id] = $row;
        }
        foreach ($ids as $id) {
            if (! isset($items[$id])) {
                throw new DomainProblem(422, 'UNKNOWN_ITEM', 'Unknown item', 'A leg names an item that does not exist.');
            }
        }

        return $items;
    }

    /**
     * @param  list<PostingLeg>  $legs
     * @return array<string, true> the legs' physical locations of this plant
     */
    private function physicalLocations(EoraPostgresConnection $db, string $plantId, array $legs): array
    {
        $ids = array_values(array_unique(array_map(static fn (PostingLeg $l) => $l->locationId, $legs)));
        $rows = $db->select('select id, location_type, plant_id from locations where id = any(?::uuid[])', ['{'.implode(',', $ids).'}']);
        $physical = [];
        foreach ($rows as $row) {
            if ((string) $row->plant_id !== $plantId) {
                throw new DomainProblem(422, 'LOCATION_OUTSIDE_PLANT', 'Location outside the plant', 'Every leg of a posting is at a location of its plant.');
            }
            if (! str_starts_with((string) $row->location_type, 'V_')) {
                $physical[(string) $row->id] = true;
            }
        }
        if (count($rows) !== count($ids)) {
            throw new DomainProblem(422, 'UNKNOWN_LOCATION', 'Unknown location', 'A leg names a location that does not exist.');
        }

        return $physical;
    }

    private function balanceKey(string $plantId, PostingLeg $leg, string $uomId): string
    {
        return implode('|', [$plantId, $leg->locationId, $leg->itemId, $leg->lotId ?? '', $leg->stockStatus, 'OWN', $uomId]);
    }

    /**
     * Creates the missing balance rows and locks every affected row in key order (BR-INV-05), then applies the deltas
     * and checks them.
     *
     * @param  array<string, string>  $deltas  balance key => signed quantity
     * @return array<string, array{id: string, qty: string}> the new quantity per balance
     */
    private function lockBalances(EoraPostgresConnection $db, array $deltas): array
    {
        ksort($deltas, SORT_STRING);
        $out = [];
        foreach ($deltas as $key => $delta) {
            [$plant, $location, $item, $lot, $status, $ownership, $uom] = explode('|', $key);
            $lot = $lot === '' ? null : $lot;
            $db->insert(
                'insert into stock_balances (tenant_id, plant_id, location_id, item_id, lot_id, stock_status, ownership, uom_id, qty)
                 values ((select c.tenant from ctx_api.context() c), ?, ?, ?, ?, ?, ?, ?, 0) on conflict do nothing',
                [$plant, $location, $item, $lot, $status, $ownership, $uom],
            );
            $row = $db->selectOne(
                'select id, qty::text as qty from stock_balances
                  where plant_id = ? and location_id = ? and item_id = ? and lot_id is not distinct from ?::uuid and stock_status = ? and ownership = ? and uom_id = ?
                  for update',
                [$plant, $location, $item, $lot, $status, $ownership, $uom],
            );
            $onHand = self::num((string) $row->qty);
            $delta = self::num($delta);
            $qty = bcadd($onHand, $delta, 6);
            if (bccomp($qty, '0', 6) < 0) {
                throw new DomainProblem(409, 'INSUFFICIENT_STOCK', 'Insufficient stock', sprintf('Only %s is on hand there; the posting needs %s.', $this->plain($onHand), $this->plain(bcmul($delta, '-1', 6))));
            }
            if ($status === 'UNRESTRICTED' && bccomp($delta, '0', 6) < 0) {
                $allocated = self::num((string) $db->selectOne("select coalesce(sum(qty), 0)::text as q from stock_allocations where balance_id = ? and status = 'ACTIVE'", [$row->id])->q);
                if (bccomp($qty, $allocated, 6) < 0) {
                    throw new DomainProblem(409, 'INSUFFICIENT_AVAILABLE', 'Stock allocated', 'The quantity left would be less than what is allocated to demand.');
                }
            }
            $out[$key] = ['id' => (string) $row->id, 'qty' => $qty];
        }

        return $out;
    }

    /** A refusal of the database's ledger checks as the posting's answer; null for anything else. */
    private function refusal(QueryException $e): ?DomainProblem
    {
        $state = (string) $e->getCode();
        if ($state === '23505' && str_contains($e->getMessage(), 'idempotency_key')) {
            return new DomainProblem(409, 'IDEMPOTENCY_IN_PROGRESS', 'Posting in progress', 'A posting with this key is being written. Send the request again to get its result.');
        }
        if ($state === '55P03') {
            return new DomainProblem(409, 'STOCK_BUSY', 'Stock busy', 'Another posting holds this stock. Try again.');
        }
        if (in_array($state, ['23514', '42501', '23503'], true)) {
            $message = $e->getPrevious()?->getMessage() ?? $e->getMessage();
            preg_match('/ERROR:\s+(.+?)(\n|\(Connection|$)/', $message, $m);

            return new DomainProblem(422, 'POSTING_REFUSED', 'Posting refused', trim($m[1] ?? 'The ledger refused this posting.'));
        }

        return null;
    }

    /** @return numeric-string */
    private static function num(string $value): string
    {
        return is_numeric($value) ? $value : throw new LogicException("Not a quantity: {$value}");
    }

    private function plain(string $qty): string
    {
        return rtrim(rtrim($qty, '0'), '.') ?: '0';
    }
}
