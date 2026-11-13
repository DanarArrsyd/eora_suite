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
use Illuminate\Validation\ValidationException;
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
        // M4 step 3 (D-110): material issue to a released work order
        'MAT_ISSUE' => ['ISSUE_TO_PROD'],
        // M4 step 4 (D-111): measured consumption and output of a run, in one document
        'PRODUCTION_POSTING' => ['CONSUME', 'OUTPUT_GOOD', 'OUTPUT_NG_REWORK', 'OUTPUT_NG_REJECT', 'OUTPUT_SCRAP'],
    ];

    /** Asked by one role and received by another with what was measured (D-110): a return, asked by production. */
    private const array RECEIVED = [
        'MAT_RETURN' => ['RETURN_FROM_PROD'],
    ];

    /** Requests decided by a second person, with the codes of their legs; reversals follow in M3 step 7. */
    private const array REQUEST_CODES = [
        'STOCK_ADJUSTMENT' => ['ADJ_PLUS', 'ADJ_MINUS'],
    ];

    private const array PREFIX = [
        'RM_RECEIPT' => 'RCV', 'INCOMING_RELEASE' => 'REL', 'MAT_ISSUE' => 'ISS', 'CUSTODY_ACCEPT' => 'CUS', 'MAT_TRANSFER' => 'TRF',
        'MAT_RETURN' => 'RTN', 'LOT_SPLIT' => 'SPL', 'PRODUCTION_POSTING' => 'PRD', 'QC_DISPOSITION' => 'QCD', 'FG_RECEIPT' => 'FGR',
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

        // a request already posted answers as it did, whatever master data changed since (checked before the rules)
        $replay = $this->replay($db, $command);
        if ($replay !== null) {
            return $replay;
        }
        $this->checkLegs($db, $command);

        // a split takes one parent lot per document, so the parent's quantity out equals its children's in (conservation)
        if ($command->docType === 'LOT_SPLIT') {
            $parents = array_unique(array_map(static fn (PostingLeg $l) => $l->lotId, array_filter($command->legs, static fn (PostingLeg $l) => $l->movementCode === 'LOT_SPLIT_OUT')));
            if (count($parents) !== 1) {
                throw new DomainProblem(422, 'LOT_SPLIT_ONE_PARENT', 'One parent lot per split', 'A lot split takes one parent lot; split another lot in its own document.');
            }
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
        // a reversal is built here from the document it reverses; the database checks it negates every leg exactly once
        if ($command->docType === 'REVERSAL') {
            foreach ($command->legs as $leg) {
                $leg->reversesMovementId !== null || throw new DomainProblem(422, 'INVALID_LEGS', 'Invalid legs', 'A reversal leg names the leg it reverses.');
            }

            return;
        }
        $types = [];
        $ids = array_values(array_unique(array_map(static fn (PostingLeg $l) => $l->locationId, $command->legs)));
        foreach ($db->select('select id, location_type from locations where id = any(?::uuid[])', ['{'.implode(',', $ids).'}']) as $row) {
            $types[(string) $row->id] = (string) $row->location_type;
        }
        $release = [];
        $itemIds = array_values(array_unique(array_map(static fn (PostingLeg $l) => $l->itemId, $command->legs)));
        foreach ($db->select('select id, incoming_release_required from items where id = any(?::uuid[])', ['{'.implode(',', $itemIds).'}']) as $row) {
            $release[(string) $row->id] = (bool) $row->incoming_release_required;
        }
        $refuse = static fn (string $why) => throw new DomainProblem(422, 'INVALID_LEGS', 'Invalid legs', $why);
        // per stock line (item and lot; a split's parent and children are one line): one status, or one place for a release
        $lines = [];
        $places = [];
        $released = [];
        foreach ($command->legs as $leg) {
            $type = $types[$leg->locationId] ?? throw new DomainProblem(422, 'UNKNOWN_LOCATION', 'Unknown location', 'A leg names a location that does not exist.');
            $out = str_starts_with($leg->qty, '-');
            if (! in_array($leg->movementCode, (self::DIRECT + self::REQUEST_CODES + self::RECEIVED)[$command->docType], true)) {
                $refuse("A {$command->docType} does not post {$leg->movementCode}.");
            }
            if ($leg->reversesMovementId !== null) {
                $refuse('Only a reversal reverses a leg.');
            }
            if ($leg->woId !== null && ! in_array($command->docType, ['MAT_ISSUE', 'MAT_RETURN', 'LOT_SPLIT', 'STOCK_ADJUSTMENT', 'PRODUCTION_POSTING'], true)) {
                $refuse("A {$command->docType} does not name a work order.");
            }
            match ($command->docType) {
                // BR-ISS-06, D-062: an item that needs its incoming release is received BLOCKED, any other UNRESTRICTED
                'RM_RECEIPT' => (($out ? $type === 'V_SUPPLIER' : $type === 'RM_WAREHOUSE')
                        && $leg->stockStatus === (($release[$leg->itemId] ?? false) ? 'BLOCKED' : 'UNRESTRICTED'))
                    || $refuse('A receipt moves from the supplier into a raw-material location, BLOCKED when the item needs its incoming release, UNRESTRICTED otherwise.'),
                'INCOMING_RELEASE' => ($leg->stockStatus === ($out ? 'BLOCKED' : 'UNRESTRICTED') && $type === 'RM_WAREHOUSE')
                    || $refuse('An incoming release turns BLOCKED raw material into UNRESTRICTED stock in place, in a raw-material location.'),
                // doc 04 row 12: ADJ_PLUS comes from the adjustment location into stock, ADJ_MINUS goes back to it
                'STOCK_ADJUSTMENT' => (($leg->movementCode === 'ADJ_PLUS') === $out ? $type === 'V_ADJUSTMENT' : ! str_starts_with($type, 'V_'))
                    || $refuse('An adjustment moves stock between the plant\'s adjustment location and one physical location.'),
                // doc 04 rows 3 and 6: an issue moves UNRESTRICTED raw material to Material at Production, a return back
                'MAT_ISSUE', 'MAT_RETURN' => ($leg->stockStatus === 'UNRESTRICTED' && $leg->woId !== null
                        && $type === ((($command->docType === 'MAT_ISSUE') === $out) ? 'RM_WAREHOUSE' : 'MAT_AT_PROD'))
                    || $refuse('An issue moves UNRESTRICTED material of its work order from a raw-material location to Material at Production; a return moves it back.'),
                // doc 04 rows 5 and 8: consumption leaves Material at Production for V_CONSUMED, output comes from V_PRODUCED
                'PRODUCTION_POSTING' => ($leg->movementCode === 'CONSUME'
                        ? ($out ? $type === 'MAT_AT_PROD' && $leg->woId !== null && $leg->measurementMethod !== null : $type === 'V_CONSUMED' && $leg->woId === null)
                        : ($out ? $type === 'V_PRODUCED' : ! str_starts_with($type, 'V_')) && $leg->woId === null)
                    || $refuse('A production posting consumes from its work order\'s Material at Production and puts its output into physical locations.'),
                default => ! str_starts_with($type, 'V_') || $refuse('A transfer or split moves between physical locations.'),
            };
            if ($command->docType === 'LOT_SPLIT' && $leg->lotId === null) {
                $refuse('A split moves lots of a lot-controlled item: every leg names its lot.');
            }
            $line = $leg->itemId.'|'.($command->docType === 'LOT_SPLIT' ? '' : (string) $leg->lotId);
            if ($command->docType === 'MAT_TRANSFER') {
                $places[$line][$out ? 'from' : 'to'][$leg->locationId] = true;
            }
            if ($command->docType === 'PRODUCTION_POSTING') {
                // consumption and output change place and status by design (ADR-0004); the database checks each category
                continue;
            }
            if ($command->docType === 'INCOMING_RELEASE') {
                // released in place, rack by rack: what leaves BLOCKED at a location enters UNRESTRICTED there
                $released[$line.'|'.$leg->locationId] = bcadd($released[$line.'|'.$leg->locationId] ?? '0', self::num($leg->qty), 6);
            } else {
                $lines[$line][$leg->stockStatus] = true;
            }
        }
        foreach ($released as $net) {
            if (bccomp($net, '0', 6) !== 0) {
                $refuse('An incoming release turns BLOCKED stock into UNRESTRICTED stock at the same location, rack by rack.');
            }
        }
        // a transfer moves each line somewhere else
        foreach ($places as $p) {
            if (array_intersect_key($p['from'] ?? [], $p['to'] ?? []) !== []) {
                $refuse('A transfer moves stock to another location.');
            }
        }
        // a receipt, transfer or split keeps the status of each line it moves
        foreach ($lines as $values) {
            if (count($values) !== 1) {
                $refuse('Stock keeps its status when it moves; a status changes only by its own document.');
            }
        }
        if ($command->genealogy !== [] && ! in_array($command->docType, ['LOT_SPLIT', 'PRODUCTION_POSTING'], true)) {
            $refuse('Only a lot split or a production posting records genealogy.');
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
        // a production posting links each consumed lot to its output lot; the database checks it (ADR-0004)
        foreach ($command->docType === 'LOT_SPLIT' ? $command->genealogy : [] as $g) {
            if (! in_array($g->fromLotId, $parents, true) || ! isset($children[$g->toLotId]) || isset($linked[$g->toLotId])
                || bccomp(self::num($g->qtyFrom), self::num($children[$g->toLotId]), 6) !== 0 || bccomp(self::num($g->qtyTo), self::num($children[$g->toLotId]), 6) !== 0) {
                $refuse('A split links its parent to each of its children once, with the child\'s quantity.');
            }
            $linked[$g->toLotId] = true;
        }
    }

    /**
     * Submits a request decided by a second person (D-060): an adjustment (doc 04 row 12) with its reason code, for the
     * production day it is asked on. Nothing moves until it is approved. A retry under the same key answers with the
     * same request; the key used for another request is refused (409).
     *
     * @return array{documentId: string, replayed: bool}
     */
    public function submit(EoraPostgresConnection $db, PostingCommand $command, string $reasonCode): array
    {
        if (! isset(self::REQUEST_CODES[$command->docType])) {
            throw new DomainProblem(422, 'DOCUMENT_TYPE_NOT_AVAILABLE', 'Not available yet', "{$command->docType} is not requested this way.");
        }
        $previous = $this->requested($db, $command->idempotencyKey, $command->docType, $command->hash());
        if ($previous !== null) {
            return ['documentId' => $previous, 'replayed' => true];
        }
        $this->checkLegs($db, $command);
        $this->items($db, $command->legs);
        $this->physicalLocations($db, $command->plantId, $command->legs);
        $day = $this->calendar->resolve($db, $command->plantId, new DateTimeImmutable((string) $db->selectOne('select now()::text as t')->t))->productionDate;
        $request = ['hash' => $command->hash(), 'legs' => array_map(static fn (PostingLeg $l) => [
            'movementCode' => $l->movementCode, 'locationId' => $l->locationId, 'itemId' => $l->itemId, 'lotId' => $l->lotId,
            'stockStatus' => $l->stockStatus, 'qty' => $l->qty, 'woId' => $l->woId,
        ], $command->legs)];
        $id = (string) $db->selectOne(
            "insert into inventory_documents (tenant_id, plant_id, doc_type, status, effective_date, requested_by, submitted_at, reason_code, note, request, idempotency_key)
             values ((select c.tenant from ctx_api.context() c), ?, ?, 'SUBMITTED', ?, (select c.actor from ctx_api.context() c), now(), ?, ?, ?::jsonb, ?)
             returning id",
            [$command->plantId, $command->docType, $day, $reasonCode, $command->note, json_encode($request, JSON_THROW_ON_ERROR), $command->idempotencyKey],
        )->id;
        $this->audit->record($db, 'inventory_document.submitted', 'inventory_document', $id, null,
            (array) $db->selectOne('select * from inventory_documents where id = ?', [$id]), ['docType' => $command->docType, 'reasonCode' => $reasonCode], $command->plantId);
        $this->outbox->publish($db, 'inventory.request_submitted', 'inventory_document', $id, ['docType' => $command->docType, 'plantId' => $command->plantId]);

        return ['documentId' => $id, 'replayed' => false];
    }

    /**
     * Requests the reversal of a posted document (doc 04 row 13, D-060, D-101): every leg negated with its own code, for
     * the production day it is asked on, with the reason. Nothing moves until a second person approves it. A reversal is
     * never reversed; a document is reversed once (a pending request blocks another).
     *
     * @return array{documentId: string, replayed: bool}
     */
    public function submitReversal(EoraPostgresConnection $db, string $documentId, string $key, string $reason, ?string $evidence = null): array
    {
        $hash = hash('sha256', json_encode(['REVERSAL', $documentId, $reason, $evidence], JSON_THROW_ON_ERROR));
        $previous = $this->requested($db, $key, 'REVERSAL', $hash);
        if ($previous !== null) {
            return ['documentId' => $previous, 'replayed' => true];
        }
        $original = $db->selectOne('select id, plant_id, doc_type, status, doc_no, effective_date::text as effective_date from inventory_documents where id = ? for share', [$documentId])
            ?? throw new DomainProblem(404, 'NOT_FOUND', 'Not found');
        if ($original->doc_type === 'REVERSAL' || $original->status !== 'POSTED') {
            throw new DomainProblem(409, 'INVALID_STATE_TRANSITION', 'Not reversible',
                $original->doc_type === 'REVERSAL' ? 'A reversal is not reversed. Post the corrected document instead.' : 'Only a posted document is reversed, once.');
        }
        if ($db->selectOne("select 1 as pending from inventory_documents where reverses_document_id = ? and status <> 'REJECTED'", [$documentId]) !== null) {
            throw new DomainProblem(409, 'REVERSAL_PENDING', 'Reversal already requested', "A reversal of {$original->doc_no} is already waiting for approval.");
        }
        $legs = array_map(static fn (stdClass $m) => [
            'movementCode' => $m->movement_code, 'locationId' => $m->location_id, 'itemId' => $m->item_id, 'lotId' => $m->lot_id,
            'stockStatus' => $m->stock_status, 'qty' => $m->qty, 'reversesMovementId' => $m->id, 'woId' => $m->wo_id,
        ], $db->select(
            'select id, movement_code, location_id, item_id, lot_id, stock_status, trim_scale(-qty_signed)::text as qty, wo_id from inventory_movements where document_id = ? order by leg_no',
            [$documentId],
        ));
        $plant = (string) $original->plant_id;
        $day = $this->calendar->resolve($db, $plant, new DateTimeImmutable((string) $db->selectOne('select now()::text as t')->t))->productionDate;
        // BR-PER-02, D-102: a document of a closed month is corrected in the open period, as a correction with its evidence
        // FOR SHARE: a close of that month (which locks the period) waits for this request, then sees it as pending
        $closed = $db->selectOne("select status from inventory_periods where plant_id = ? and period = date_trunc('month', ?::date)::date for share",
            [$plant, $original->effective_date])?->status === 'CLOSED';
        if ($closed && ($evidence === null || trim($evidence) === '')) {
            throw ValidationException::withMessages(['evidence' => "{$original->doc_no} is in a closed month: give the evidence for this correction (e.g. the report number)."]);
        }
        $request = ['hash' => $hash, 'legs' => $legs] + ($closed ? ['evidence' => trim((string) $evidence)] : []);
        try {
            $id = (string) $db->selectOne(
                "insert into inventory_documents (tenant_id, plant_id, doc_type, status, effective_date, requested_by, submitted_at, note, request, reverses_document_id,
                                                  correction_of_document_id, original_effective_date, reason_code, idempotency_key)
                 values ((select c.tenant from ctx_api.context() c), ?, 'REVERSAL', 'SUBMITTED', ?, (select c.actor from ctx_api.context() c), now(), ?, ?::jsonb, ?, ?, ?::date, ?, ?)
                 returning id",
                [$plant, $day, $reason, json_encode($request, JSON_THROW_ON_ERROR), $documentId, $closed ? $documentId : null,
                    $closed ? $original->effective_date : null, $closed ? 'CLOSED_PERIOD' : null, $key],
            )->id;
        } catch (QueryException $e) {
            // the database's one-reversal-per-document index: another request won the race
            throw $e->getCode() === '23505' && str_contains($e->getMessage(), 'reversal_uq')
                ? new DomainProblem(409, 'REVERSAL_PENDING', 'Reversal already requested', "A reversal of {$original->doc_no} is already waiting for approval.")
                : $e;
        }
        $this->audit->record($db, 'inventory_document.submitted', 'inventory_document', $id, null,
            (array) $db->selectOne('select * from inventory_documents where id = ?', [$id]), ['docType' => 'REVERSAL', 'reverses' => $original->doc_no], $plant);
        $this->outbox->publish($db, 'inventory.request_submitted', 'inventory_document', $id, ['docType' => 'REVERSAL', 'plantId' => $plant]);

        return ['documentId' => $id, 'replayed' => false];
    }

    /**
     * Holds $key until the caller's transaction ends, then answers with the request already submitted under it (its id),
     * null when the key is new; 409 when the key was used for another request. Callers claim the key this way before
     * checking stock or master data that may have changed since.
     */
    public function requested(EoraPostgresConnection $db, string $key, string $docType, string $hash): ?string
    {
        $this->lockKey($db, $key);
        $previous = $db->selectOne("select id, doc_type, request ->> 'hash' as hash from inventory_documents where idempotency_key = ?", [$key]);
        if ($previous === null) {
            // one key, one command: a key that posted (or approved) something never submits a request
            if ($db->selectOne('select 1 as used from idempotency_records where idempotency_key = ?', [$key]) !== null) {
                throw new DomainProblem(409, 'IDEMPOTENCY_KEY_REUSED', 'Idempotency key reused', 'This key was used for another request. Send a new key for a new request.');
            }

            return null;
        }
        if ($previous->doc_type !== $docType || $previous->hash !== $hash) {
            throw new DomainProblem(409, 'IDEMPOTENCY_KEY_REUSED', 'Idempotency key reused', 'This key was used for another request. Send a new key for a new request.');
        }

        return (string) $previous->id;
    }

    /**
     * Posts a submitted request as it was submitted (D-060): the approver is the poster and never its requester; the
     * stock, period and allocation checks run now, at approval. Retried under the same key, it answers as it posted.
     */
    public function approve(EoraPostgresConnection $db, string $requestId, string $key): PostingResult
    {
        $hash = hash('sha256', 'approve:'.$requestId);
        $previous = $this->claim($db, $key, $hash);
        if ($previous !== null) {
            return $previous;
        }
        $doc = $this->decidable($db, $requestId) ?? throw new LogicException('An approval decides a submitted request.');
        if (isset(self::RECEIVED[$doc->doc_type])) {
            throw new LogicException('A return is received with what was measured, not approved.');
        }
        $request = json_decode((string) $doc->request, true, flags: JSON_THROW_ON_ERROR);
        $command = new PostingCommand((string) $doc->doc_type, (string) $doc->plant_id, $key, array_values(array_map(static fn (array $l) => new PostingLeg(
            $l['movementCode'], $l['locationId'], $l['itemId'], $l['lotId'], $l['stockStatus'], $l['qty'], reversesMovementId: $l['reversesMovementId'] ?? null,
            woId: $l['woId'] ?? null,
        ), $request['legs'])), effectiveDate: (string) $doc->effective_date, note: $doc->note, requestHash: $hash);
        $this->checkLegs($db, $command);
        $reversed = $doc->reverses_document_id;
        if ($reversed !== null) {
            // the reversed document is held until the reversal commits; it must still be posted (not reversed meanwhile)
            $original = $db->selectOne('select status, plant_id, doc_no, effective_date::text as effective_date from inventory_documents where id = ? for update', [$reversed]);
            if ($original?->status !== 'POSTED') {
                throw new DomainProblem(409, 'INVALID_STATE_TRANSITION', 'Already reversed', 'The document is no longer posted, so it cannot be reversed.');
            }
            // BR-PER-02, D-102: the document's month closed after the request was made: it is corrected only with evidence
            $closed = $db->selectOne("select 1 as closed from inventory_periods where plant_id = ? and period = date_trunc('month', ?::date)::date and status = 'CLOSED' for share",
                [$original->plant_id, $original->effective_date]) !== null;
            if ($closed && $doc->correction_of_document_id === null) {
                throw new DomainProblem(409, 'CORRECTION_REQUIRED', 'Month closed since the request',
                    "The month of {$original->doc_no} has been closed since this request. Reject it and request the reversal again with its evidence.");
            }
        }
        $db->statement("set local statement_timeout = '10s'");
        try {
            $result = $this->write($db, $command, $requestId);
            if ($reversed !== null) {
                $db->update("update inventory_documents set status = 'REVERSED' where id = ?", [$reversed]);
            }
            $db->statement('set constraints all immediate');
        } catch (QueryException $e) {
            throw $this->refusal($e) ?? $e;
        } catch (DomainProblem $e) {
            // doc 04 row 13: the stock the document moved has moved on; what came after is reversed first
            if ($reversed !== null && in_array($e->errorCode, ['INSUFFICIENT_STOCK', 'INSUFFICIENT_AVAILABLE'], true)) {
                throw new DomainProblem(409, 'REVERSAL_BLOCKED_BY_DOWNSTREAM', 'Stock has moved on',
                    'The stock this document moved is no longer all there (used, moved or allocated since). Reverse what came after it first.');
            }
            throw $e;
        }

        return $result;
    }

    /** Rejects a submitted request with the reason (D-060); nothing moves. Rejecting it again changes nothing. */
    public function reject(EoraPostgresConnection $db, string $requestId, string $reason): void
    {
        $doc = $this->decidable($db, $requestId, rejecting: true);
        if ($doc === null) {
            return;
        }
        $db->update(
            "update inventory_documents set status = 'REJECTED', decided_by = (select c.actor from ctx_api.context() c), decided_at = now(), reject_reason = ? where id = ?",
            [$reason, $requestId],
        );
        $this->audit->record($db, 'inventory_document.rejected', 'inventory_document', $requestId, (array) $doc,
            (array) $db->selectOne('select * from inventory_documents where id = ?', [$requestId]), ['reason' => $reason], (string) $doc->plant_id);
        $this->outbox->publish($db, 'inventory.request_rejected', 'inventory_document', $requestId, ['docType' => $doc->doc_type, 'plantId' => $doc->plant_id]);
    }

    /**
     * The submitted request, locked until the decision commits: 404 when unknown, 403 SOD_VIOLATION for its requester,
     * 409 INVALID_STATE_TRANSITION once decided (null for a request already rejected when $rejecting).
     */
    private function decidable(EoraPostgresConnection $db, string $requestId, bool $rejecting = false): ?stdClass
    {
        $doc = $db->selectOne(
            'select id, doc_type, plant_id, status, requested_by, effective_date::text as effective_date, note, request::text as request, reverses_document_id,
                    correction_of_document_id, source_id
               from inventory_documents where id = ? and requested_by is not null for update',
            [$requestId],
        ) ?? throw new DomainProblem(404, 'NOT_FOUND', 'Not found');
        if ((string) $doc->requested_by === (string) $db->selectOne('select c.actor from ctx_api.context() c')->actor) {
            throw new DomainProblem(403, 'SOD_VIOLATION', 'Second person required', 'A request is approved or rejected by someone other than its requester.');
        }
        if ($rejecting && $doc->status === 'REJECTED') {
            return null;
        }
        if ($doc->status !== 'SUBMITTED') {
            throw new DomainProblem(409, 'INVALID_STATE_TRANSITION', 'Already decided', 'This request has already been decided.');
        }

        return $doc;
    }

    /**
     * Asks for a return (D-110, doc 04 row 6): production names the work order, its work center's Material at Production
     * location, the item, lot and quantity. Nothing moves until materials receives it. A retry under the same key answers
     * with the same request; the key used for another request is refused (409).
     *
     * @return array{documentId: string, replayed: bool}
     */
    public function requestReturn(EoraPostgresConnection $db, string $plantId, string $key, string $woId, string $locationId, string $itemId, ?string $lotId, string $qty, ?string $note): array
    {
        $hash = self::returnHash($plantId, $woId, $locationId, $itemId, $lotId, $qty, $note);
        $previous = $this->requested($db, $key, 'MAT_RETURN', $hash);
        if ($previous !== null) {
            return ['documentId' => $previous, 'replayed' => true];
        }
        $day = $this->calendar->resolve($db, $plantId, new DateTimeImmutable((string) $db->selectOne('select now()::text as t')->t))->productionDate;
        $request = ['hash' => $hash, 'asked' => ['locationId' => $locationId, 'itemId' => $itemId, 'lotId' => $lotId, 'qty' => $qty]];
        $id = (string) $db->selectOne(
            "insert into inventory_documents (tenant_id, plant_id, doc_type, status, effective_date, requested_by, submitted_at, note, request, source_type, source_id, idempotency_key)
             values ((select c.tenant from ctx_api.context() c), ?, 'MAT_RETURN', 'SUBMITTED', ?, (select c.actor from ctx_api.context() c), now(), ?, ?::jsonb, 'work_order', ?, ?)
             returning id",
            [$plantId, $day, $note, json_encode($request, JSON_THROW_ON_ERROR), $woId, $key],
        )->id;
        $this->audit->record($db, 'inventory_document.submitted', 'inventory_document', $id, null,
            (array) $db->selectOne('select * from inventory_documents where id = ?', [$id]), ['docType' => 'MAT_RETURN'], $plantId);
        $this->outbox->publish($db, 'materials.return_requested', 'inventory_document', $id, ['docType' => 'MAT_RETURN', 'plantId' => $plantId, 'workOrderId' => $woId]);

        return ['documentId' => $id, 'replayed' => false];
    }

    /**
     * Receives a return as measured (D-110, BR-ISS-05): out of the asked Material at Production location, into the chosen
     * raw-material location, with the weighed or counted quantity (which may differ from the asked one; both are kept).
     * Received by someone other than who asked; retried under the same key, it answers as it posted.
     */
    public function receiveReturn(EoraPostgresConnection $db, string $requestId, string $key, string $toLocationId, string $qty): PostingResult
    {
        $hash = self::receiveHash($requestId, $toLocationId, $qty);
        $previous = $this->claim($db, $key, $hash);
        if ($previous !== null) {
            return $previous;
        }
        $doc = $this->decidable($db, $requestId) ?? throw new LogicException('A receipt decides a submitted return.');
        if ($doc->doc_type !== 'MAT_RETURN') {
            throw new DomainProblem(404, 'NOT_FOUND', 'Not found');
        }
        $asked = json_decode((string) $doc->request, true, flags: JSON_THROW_ON_ERROR)['asked'];
        $wo = (string) $doc->source_id;
        $command = new PostingCommand('MAT_RETURN', (string) $doc->plant_id, $key, [
            new PostingLeg('RETURN_FROM_PROD', $asked['locationId'], $asked['itemId'], $asked['lotId'], 'UNRESTRICTED', '-'.$qty, woId: $wo),
            new PostingLeg('RETURN_FROM_PROD', $toLocationId, $asked['itemId'], $asked['lotId'], 'UNRESTRICTED', $qty, woId: $wo),
        ], effectiveDate: (string) $doc->effective_date, note: $doc->note, requestHash: $hash);
        $this->checkLegs($db, $command);
        $db->statement("set local statement_timeout = '10s'");
        try {
            $result = $this->write($db, $command, $requestId);
            $db->statement('set constraints all immediate');
        } catch (QueryException $e) {
            throw $this->refusal($e) ?? $e;
        }

        return $result;
    }

    /** The hash a return request is replayed by: callers claim the key with it before checking anything that may change. */
    public static function returnHash(string $plantId, string $woId, string $locationId, string $itemId, ?string $lotId, string $qty, ?string $note): string
    {
        return hash('sha256', json_encode(['MAT_RETURN', $plantId, $woId, $locationId, $itemId, $lotId, $qty, $note], JSON_THROW_ON_ERROR));
    }

    /** The hash a return receipt is replayed by (see returnHash()). */
    public static function receiveHash(string $requestId, string $toLocationId, string $qty): string
    {
        return hash('sha256', json_encode(['receive', $requestId, $toLocationId, $qty], JSON_THROW_ON_ERROR));
    }

    /**
     * M4-D3, BR-ISS-02: a work order never takes from Material at Production more than it has there (per work center, item,
     * lot and status), while the balance rows are locked; a reversal of an issue whose material has been returned or used
     * meets this as INSUFFICIENT_STOCK (REVERSAL_BLOCKED_BY_DOWNSTREAM).
     *
     * @param  array<string, stdClass>  $items
     */
    private function requireWorkOrderStock(EoraPostgresConnection $db, PostingCommand $command, array $items): void
    {
        $out = [];
        foreach ($command->legs as $leg) {
            if ($leg->woId !== null) {
                $k = implode('|', [$leg->woId, $leg->locationId, $leg->itemId, $leg->lotId ?? '', $leg->stockStatus]);
                $out[$k] = bcadd($out[$k] ?? '0', self::num($leg->qty), 6);
            }
        }
        foreach ($out as $k => $delta) {
            if (bccomp($delta, '0', 6) >= 0) {
                continue;
            }
            [$wo, $location, $item, $lot, $status] = explode('|', $k);
            $there = $db->selectOne(
                'select coalesce(sum(m.qty_signed), 0)::text as q, (select location_type from locations where id = ?) as type
                   from inventory_movements m where m.wo_id = ? and m.location_id = ? and m.item_id = ? and m.lot_id is not distinct from ?::uuid and m.stock_status = ? and m.uom_id = ?',
                [$location, $wo, $location, $item, $lot === '' ? null : $lot, $status, $items[$item]->base_uom_id],
            );
            if ($there->type === 'MAT_AT_PROD' && bccomp(bcadd(self::num((string) $there->q), $delta, 6), '0', 6) < 0) {
                throw new DomainProblem(409, 'INSUFFICIENT_STOCK', 'Not at the work center',
                    sprintf('This work order has only %s of it at the work center; the posting needs %s.', $this->plain(self::num((string) $there->q)), $this->plain(bcmul($delta, '-1', 6))));
            }
        }
    }

    private function lockKey(EoraPostgresConnection $db, string $key): void
    {
        if ($db->transactionLevel() === 0) {
            throw new LogicException('Postings run inside the tenant transaction.');
        }
        $db->statement("set local lock_timeout = '3s'");
        try {
            $db->selectOne("select pg_advisory_xact_lock(hashtextextended((select c.tenant from ctx_api.context() c)::text || ':' || ?, 0))", [$key]);
        } catch (QueryException $e) {
            throw $e->getCode() === '55P03'
                ? new DomainProblem(409, 'IDEMPOTENCY_IN_PROGRESS', 'Posting in progress', 'A posting with this key is being written. Send the request again to get its result.')
                : $e;
        }
    }

    private function replay(EoraPostgresConnection $db, PostingCommand $command): ?PostingResult
    {
        return $this->previous($db, $command->idempotencyKey, $command->hash());
    }

    /**
     * Holds $key until the caller's transaction ends, then answers as previous(): a second request under the same key
     * waits for the first and replays its result instead of writing anything itself. Commands that create records before
     * posting (a receipt's lot) claim the key first, before checking master data that may have changed since.
     */
    public function claim(EoraPostgresConnection $db, string $key, string $hash): ?PostingResult
    {
        $this->lockKey($db, $key);

        return $this->previous($db, $key, $hash);
    }

    /**
     * The result already posted under $key for the request hashed as $hash, null when the key is new; 409 when the key
     * was used for another request.
     */
    public function previous(EoraPostgresConnection $db, string $key, string $hash): ?PostingResult
    {
        $stored = $db->selectOne('select request_hash, response from idempotency_records where idempotency_key = ?', [$key]);
        if ($stored === null) {
            // one key, one command: a key that submitted a request (adjustment) never posts anything else
            if ($db->selectOne('select 1 as used from inventory_documents where idempotency_key = ? and requested_by is not null', [$key]) !== null) {
                throw new DomainProblem(409, 'IDEMPOTENCY_KEY_REUSED', 'Idempotency key reused', 'This key was used for another request. Send a new key for a new posting.');
            }

            return null;
        }
        if ($stored->request_hash !== $hash) {
            throw new DomainProblem(409, 'IDEMPOTENCY_KEY_REUSED', 'Idempotency key reused', 'This key was used for another request. Send a new key for a new posting.');
        }
        $r = json_decode((string) $stored->response, true, flags: JSON_THROW_ON_ERROR);

        return new PostingResult($r['documentId'], $r['docNo'], $r['productionDate'], replayed: true);
    }

    /** @param  ?string  $requestId  the submitted request this posting decides (D-060); null for a direct posting */
    private function write(EoraPostgresConnection $db, PostingCommand $command, ?string $requestId = null): PostingResult
    {
        $now = new DateTimeImmutable((string) $db->selectOne('select now()::text as t')->t);
        $time = $this->calendar->resolve($db, $command->plantId, $now);
        $effective = $command->effectiveDate ?? $time->productionDate;
        $day = DateTimeImmutable::createFromFormat('!Y-m-d', $effective);
        if ($day === false || $day->format('Y-m-d') !== $effective || $effective > $time->productionDate) {
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
        $this->requireWorkOrderStock($db, $command, $items);

        $plantCode = (string) $db->selectOne('select code from plants where id = ?', [$command->plantId])->code;
        $year = (int) substr($effective, 0, 4);
        $number = (int) $db->selectOne(
            'insert into document_counters (tenant_id, plant_id, doc_type, year, last_no) values ((select c.tenant from ctx_api.context() c), ?, ?, ?, 1)
             on conflict (tenant_id, plant_id, doc_type, year) do update set last_no = document_counters.last_no + 1 returning last_no',
            [$command->plantId, $command->docType, $year],
        )->last_no;
        $docNo = sprintf('%s-%s-%d-%04d', self::PREFIX[$command->docType], $plantCode, $year, $number);

        $documentId = $requestId !== null
            // the request becomes the posted document, decided by the signed actor (the database refuses its requester)
            ? (string) $db->selectOne(
                "update inventory_documents set status = 'POSTED', doc_no = ?, production_date = ?, calendar_date = ?, shift_id = ?, shift_date = ?,
                        posted_at = now(), posted_by = (select c.actor from ctx_api.context() c), decided_by = (select c.actor from ctx_api.context() c), decided_at = now()
                  where id = ? returning id",
                [$docNo, $effective, $time->calendarDate, $backdated ? null : $time->shiftId, $backdated ? null : $time->shiftDate, $requestId],
            )->id
            : (string) $db->selectOne(
                'insert into inventory_documents (tenant_id, plant_id, doc_type, doc_no, status, effective_date, production_date, calendar_date, shift_id, shift_date,
                                                  posted_at, posted_by, note, source_type, source_id, idempotency_key, corrects_document_id)
                 values ((select c.tenant from ctx_api.context() c), ?, ?, ?, \'POSTED\', ?, ?, ?, ?, ?, now(), (select c.actor from ctx_api.context() c), ?, ?, ?, ?, ?)
                 returning id',
                [$command->plantId, $command->docType, $docNo, $effective, $effective, $time->calendarDate, $backdated ? null : $time->shiftId,
                    $backdated ? null : $time->shiftDate, $command->note, $command->sourceType, $command->sourceId, $command->idempotencyKey, $command->correctsDocumentId],
            )->id;

        foreach ($command->legs as $i => $leg) {
            $db->insert(
                'insert into inventory_movements (tenant_id, document_id, leg_no, movement_code, reverses_movement_id, plant_id, location_id, item_id, lot_id,
                                                  stock_status, uom_id, qty_signed, entered_qty, entered_uom_id, uom_conversion_id, wo_id, run_id,
                                                  measurement_method, measured_qty, measurement_evidence, reason_code, reason_note)
                 values ((select c.tenant from ctx_api.context() c), ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
                [$documentId, $i + 1, $leg->movementCode, $leg->reversesMovementId, $command->plantId, $leg->locationId, $leg->itemId, $leg->lotId,
                    $leg->stockStatus, $items[$leg->itemId]->base_uom_id, $leg->qty, $leg->enteredQty, $leg->enteredUomId, $leg->uomConversionId, $leg->woId,
                    $command->sourceType === 'production_run' ? $command->sourceId : null,
                    $leg->measurementMethod, $leg->measuredQty, $leg->measurementEvidence, $leg->reasonCode, $leg->reasonNote],
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
        if ($state === '23505' && str_contains($e->getMessage(), 'regular_production_uq')) {
            return new DomainProblem(409, 'PRODUCTION_ALREADY_POSTED', 'Already posted for this shift',
                'This run already has its regular posting for this production date and shift. Reverse it and post a correction instead.');
        }
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
