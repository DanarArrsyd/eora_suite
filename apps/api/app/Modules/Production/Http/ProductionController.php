<?php

declare(strict_types=1);

namespace App\Modules\Production\Http;

use App\Modules\Inventory\Application\InventoryPostingService;
use App\Modules\Inventory\Application\LotNumbers;
use App\Modules\Inventory\Contracts\GenealogyLink;
use App\Modules\Inventory\Contracts\PostingCommand;
use App\Modules\Inventory\Contracts\PostingLeg;
use App\Modules\Inventory\Contracts\PostingResult;
use App\Modules\Inventory\Http\ReceiptController;
use App\Modules\Production\Queries\ProductionRunsQuery;
use App\Platform\Access\Authorizer;
use App\Platform\Access\Scope;
use App\Platform\Audit\AuditRecorder;
use App\Platform\Calendar\BusinessCalendar;
use App\Platform\Database\EoraPostgresConnection;
use App\Platform\Exceptions\DomainProblem;
use App\Platform\Http\Concurrency;
use App\Platform\Http\MasterWrites;
use App\Platform\Outbox\OutboxPublisher;
use App\Platform\Query\QueryRunner;
use DateTimeImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use LogicException;
use stdClass;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Production → runs and production postings (doc 33 §1.4, D-111, doc 04 rows 5 and 8, ADR-0003, ADR-0004).
 *
 * - A run (`production.run.post`, the production leader): a released work order at one work center of its routing,
 *   machine optional; the first run moves the WO to IN_PROGRESS. Closed once it has a posting; cancelled with a reason
 *   while it has none.
 * - A production posting (`production.posting.post`, Idempotency-Key): measured consumption per input lot of the WO's
 *   material at the run's work center (WEIGHED_REMAINING / COUNTED_REMAINING: what is left; WEIGHED_CONSUMED: what was
 *   used; evidence required), output Good / NG rework / NG reject pcs of the WO's item into one output lot, process scrap
 *   in kg of a scrap item with its reason. At most one regular posting per run, production date and shift (BR-OUT-08);
 *   a correction names the reversed posting it replaces. No backflush (BR-CON-05); over-report guard and variance are M5.
 */
final class ProductionController
{
    use MasterWrites;

    private const string RUN = 'production.run.post';

    private const string POST = 'production.posting.post';

    /** ADR-0003 methods in M4 (D-111); by-difference arrives with its reconciliation case in M5. */
    public const array METHODS = ['WEIGHED_REMAINING', 'COUNTED_REMAINING', 'WEIGHED_CONSUMED'];

    /** BR-OUT-04 scrap reasons (D-111; tenant configuration later); OTHER needs a note. */
    public const array SCRAP_REASONS = ['SKELETON', 'SETUP', 'EDGE_TRIM', 'DEFECT', 'OTHER'];

    public function __construct(
        private readonly Authorizer $authorizer,
        private readonly AuditRecorder $audit,
        private readonly QueryRunner $rows,
        private readonly BusinessCalendar $calendar,
        private readonly OutboxPublisher $outbox,
        private readonly InventoryPostingService $postings,
        private readonly LotNumbers $lots,
    ) {}

    /** Open a run `RUN-<plant>-<year>-NNNN` for a released work order at a work center of its routing. */
    public function open(Request $request): JsonResponse
    {
        $data = $request->validate([
            'workOrderId' => ['required', 'uuid'],
            'workCenterId' => ['required', 'uuid'],
            'machineId' => ['nullable', 'uuid'],
            'note' => ['nullable', 'string', 'max:500'],
        ]);
        $db = $this->db();
        $wo = $db->selectOne('select w.id, w.plant_id, w.wo_no, w.status, w.version, w.routing_version_id, p.code as plant_code, p.company_id
                                from work_orders w join plants p on p.id = w.plant_id where w.id = ? for update of w', [strtolower($data['workOrderId'])])
            ?? throw ValidationException::withMessages(['workOrderId' => 'Unknown work order.']);
        $this->authorizer->authorize($db, self::RUN, Scope::plant((string) $wo->plant_id, (string) $wo->company_id));
        if (! in_array($wo->status, ['RELEASED', 'IN_PROGRESS'], true)) {
            throw new DomainProblem(409, 'INVALID_STATE_TRANSITION', 'Work order not released', "A run is opened for a released work order; {$wo->wo_no} is ".strtolower((string) $wo->status).'.');
        }
        $wc = strtolower($data['workCenterId']);
        $db->selectOne('select 1 as ok from routing_operations where routing_version_id = ? and work_center_id = ?', [$wo->routing_version_id, $wc])
            ?? throw ValidationException::withMessages(['workCenterId' => "Choose a work center of {$wo->wo_no}'s routing."]);
        $machine = isset($data['machineId']) ? strtolower($data['machineId']) : null;
        if ($machine !== null && $db->selectOne("select 1 as ok from machines where id = ? and work_center_id = ? and status = 'ACTIVE'", [$machine, $wc]) === null) {
            throw ValidationException::withMessages(['machineId' => 'Choose an active machine of this work center.']);
        }

        $year = (int) substr($this->calendar->resolve($db, (string) $wo->plant_id, new DateTimeImmutable)->calendarDate, 0, 4);
        $number = (int) $db->selectOne(
            "insert into document_counters (tenant_id, plant_id, doc_type, year, last_no) values ((select c.tenant from ctx_api.context() c), ?, 'PRODUCTION_RUN', ?, 1)
             on conflict (tenant_id, plant_id, doc_type, year) do update set last_no = document_counters.last_no + 1 returning last_no",
            [$wo->plant_id, $year],
        )->last_no;
        $id = (string) $db->selectOne(
            'insert into production_runs (tenant_id, plant_id, run_no, work_order_id, work_center_id, machine_id, opened_by, opened_at, note)
             values ((select c.tenant from ctx_api.context() c), ?, ?, ?, ?, ?, (select c.actor from ctx_api.context() c), now(), ?) returning id',
            [$wo->plant_id, sprintf('RUN-%s-%d-%04d', $wo->plant_code, $year, $number), $wo->id, $wc, $machine, self::optionalText($data['note'] ?? null)],
        )->id;
        $this->audit->record($db, 'production_run.opened', 'production_run', $id, null, $this->state($db, 'production_runs', $id), [], (string) $wo->plant_id);
        // D-111: the first run puts the work order into production
        if ($wo->status === 'RELEASED') {
            $before = $this->state($db, 'work_orders', (string) $wo->id);
            $db->update("update work_orders set status = 'IN_PROGRESS' where id = ?", [$wo->id]);
            $this->audit->record($db, 'work_order.started', 'work_order', (string) $wo->id, $before, $this->state($db, 'work_orders', (string) $wo->id), [], (string) $wo->plant_id);
        }
        $this->outbox->publish($db, 'production.run_opened', 'production_run', $id, ['plantId' => $wo->plant_id, 'workOrderId' => $wo->id]);

        return $this->respondRun($db, $id, 201);
    }

    /** One run with its postings and the work order's material at its work center (what a posting consumes). */
    public function show(string $run): JsonResponse
    {
        $db = $this->db();
        $this->authorizer->requireAnywhere($db, 'production.run.view');

        return $this->respondRun($db, (string) $this->run($db, $run)->id);
    }

    /** Close an open run that has a posting (If-Match). */
    public function close(Request $request, string $run): JsonResponse
    {
        $expected = Concurrency::expectedVersion($request);
        $db = $this->db();
        $r = $this->openRun($db, $run);
        if ($db->selectOne("select 1 as ok from inventory_documents where doc_type = 'PRODUCTION_POSTING' and source_id = ? and status = 'POSTED'", [$r->id]) === null) {
            throw new DomainProblem(409, 'INVALID_STATE_TRANSITION', 'Nothing posted', 'A run is closed once it has a production posting. Cancel it instead.');
        }
        $this->transition($db, $r, $expected, "status = 'CLOSED', closed_by = (select c.actor from ctx_api.context() c), closed_at = now()", [], 'production_run.closed');
        $this->outbox->publish($db, 'production.run_closed', 'production_run', (string) $r->id, ['plantId' => $r->plant_id]);

        return $this->respondRun($db, (string) $r->id);
    }

    /** Cancel an open run with no posting, with the reason (If-Match). */
    public function cancel(Request $request, string $run): JsonResponse
    {
        $data = $request->validate(['reason' => ['required', 'string', 'min:3', 'max:500']]);
        $expected = Concurrency::expectedVersion($request);
        $db = $this->db();
        $r = $this->openRun($db, $run);
        if ($db->selectOne("select 1 as ok from inventory_documents where doc_type = 'PRODUCTION_POSTING' and source_id = ?", [$r->id]) !== null) {
            throw new DomainProblem(409, 'INVALID_STATE_TRANSITION', 'Run has postings', 'A run with production postings is closed, not cancelled.');
        }
        $this->transition($db, $r, $expected, "status = 'CANCELLED', cancelled_by = (select c.actor from ctx_api.context() c), cancelled_at = now(), cancel_reason = ?",
            [trim($data['reason'])], 'production_run.cancelled');

        return $this->respondRun($db, (string) $r->id);
    }

    /**
     * Post production of an open run (Idempotency-Key header required): measured consumption, output and scrap in one
     * document. 201 when posted, 200 when the same request was posted before under the key, 409 when the key was used for
     * another request or the run already has its regular posting for this production date and shift.
     */
    public function post(Request $request): JsonResponse
    {
        $data = $request->validate([
            'runId' => ['required', 'uuid'],
            'consumptions' => ['required', 'array', 'min:1', 'max:20'],
            'consumptions.*.itemId' => ['required', 'uuid'],
            'consumptions.*.lotId' => ['nullable', 'uuid'],
            'consumptions.*.method' => ['required', Rule::in(ProductionController::METHODS)],
            'consumptions.*.qty' => ['required', 'string', 'regex:'.ProductionController::MEASURED],
            'consumptions.*.evidence' => ['required', 'string', 'min:1', 'max:200'],
            'goodQty' => ['nullable', 'string', 'regex:'.ReceiptController::QTY],
            'ngReworkQty' => ['nullable', 'string', 'regex:'.ReceiptController::QTY],
            'ngRejectQty' => ['nullable', 'string', 'regex:'.ReceiptController::QTY],
            'scrap' => ['nullable', 'array', 'max:10'],
            'scrap.*.itemId' => ['required', 'uuid'],
            'scrap.*.qty' => ['required', 'string', 'regex:'.ReceiptController::QTY],
            'scrap.*.reasonCode' => ['required', Rule::in(ProductionController::SCRAP_REASONS)],
            'scrap.*.note' => ['nullable', 'string', 'max:500'],
            'qcLocationId' => ['nullable', 'uuid'],
            'ngLocationId' => ['nullable', 'uuid'],
            'scrapLocationId' => ['nullable', 'uuid'],
            'correctsPostingId' => ['nullable', 'uuid'],
            'note' => ['nullable', 'string', 'max:500'],
        ]);
        $key = (string) $request->header('Idempotency-Key', '');
        if (preg_match('/^[A-Za-z0-9._:-]{8,200}$/', $key) !== 1) {
            throw new DomainProblem(400, 'IDEMPOTENCY_KEY_REQUIRED', 'Idempotency key required', 'Send an Idempotency-Key header (8–200 letters, digits, . _ : -).');
        }
        $db = $this->db();
        $r = $db->selectOne(
            "select r.id, r.plant_id, r.status, r.work_order_id, r.work_center_id, w.wo_no, w.status as wo_status, w.item_id, i.lot_controlled, i.inspection_required,
                    u.code as uom, u.decimals, p.company_id,
                    (select l.id from locations l where l.plant_id = r.plant_id and l.location_type = 'MAT_AT_PROD' and l.work_center_id = r.work_center_id) as map_id
               from production_runs r join work_orders w on w.id = r.work_order_id join items i on i.id = w.item_id join uoms u on u.id = i.base_uom_id
               join plants p on p.id = r.plant_id where r.id = ?",
            [strtolower($data['runId'])],
        ) ?? throw ValidationException::withMessages(['runId' => 'Unknown run.']);
        $this->authorizer->authorize($db, self::POST, Scope::plant((string) $r->plant_id, (string) $r->company_id));
        $normalized = $data;
        unset($normalized['runId']);
        $hash = hash('sha256', json_encode(['PRODUCTION_POSTING', (string) $r->id, $normalized], JSON_THROW_ON_ERROR));
        // a retried request answers with what it posted, whatever the stock or the run did since
        $previous = $this->postings->claim($db, $key, $hash);
        if ($previous !== null) {
            return $this->posted($db, $previous);
        }

        // locked until the posting commits: the run stays open meanwhile
        if ($db->selectOne('select status from production_runs where id = ? for share', [$r->id])->status !== 'OPEN') {
            throw new DomainProblem(409, 'INVALID_STATE_TRANSITION', 'Run not open', 'Production is posted to an open run.');
        }
        $plant = (string) $r->plant_id;
        $corrects = isset($data['correctsPostingId']) ? strtolower($data['correctsPostingId']) : null;
        if ($corrects !== null && $db->selectOne("select 1 as ok from inventory_documents where id = ? and doc_type = 'PRODUCTION_POSTING' and source_id = ? and status = 'REVERSED'", [$corrects, $r->id]) === null) {
            throw ValidationException::withMessages(['correctsPostingId' => 'A correction replaces a reversed posting of this run.']);
        }
        $virtual = fn (string $type) => (string) ($db->selectOne('select id from locations where plant_id = ? and location_type = ?', [$plant, $type])
            ?? throw new DomainProblem(409, 'NO_VIRTUAL_LOCATION', 'Plant not set up', "This plant has no {$type} location."))->id;
        $consumed = $virtual('V_CONSUMED');
        $produced = $virtual('V_PRODUCED');

        // output in pieces of the work order's item, in its unit
        $unit = (object) ['uom' => $r->uom, 'decimals' => $r->decimals];
        $outputs = [];
        foreach (['goodQty' => 'OUTPUT_GOOD', 'ngReworkQty' => 'OUTPUT_NG_REWORK', 'ngRejectQty' => 'OUTPUT_NG_REJECT'] as $field => $code) {
            $qty = $data[$field] ?? null;
            if ($qty !== null && bccomp(self::num($qty), '0', 6) > 0) {
                $this->precision($qty, $unit, $field);
                $outputs[$code] = $qty;
            }
        }
        $scrap = array_values($data['scrap'] ?? []);
        if ($outputs === [] && $scrap === []) {
            throw ValidationException::withMessages(['goodQty' => 'Post what was made: good, NG or scrap.']);
        }
        $place = function (?string $id, string $type, string $field) use ($db, $plant): string {
            $row = $id !== null
                ? $db->selectOne("select id from locations where id = ? and plant_id = ? and location_type = ? and status = 'ACTIVE' for share", [strtolower($id), $plant, $type])
                : $db->selectOne("select id from locations where plant_id = ? and location_type = ? and status = 'ACTIVE' order by code limit 1 for share", [$plant, $type]);

            return (string) ($row ?? throw ValidationException::withMessages([$field => "Choose an active {$type} location of this plant."]))->id;
        };
        $qcArea = isset($outputs['OUTPUT_GOOD']) ? $place($data['qcLocationId'] ?? null, 'QC_AREA', 'qcLocationId') : null;
        $ngArea = isset($outputs['OUTPUT_NG_REWORK']) || isset($outputs['OUTPUT_NG_REJECT']) ? $place($data['ngLocationId'] ?? null, 'NG_AREA', 'ngLocationId') : null;
        $scrapYard = $scrap !== [] ? $place($data['scrapLocationId'] ?? null, 'SCRAP_YARD', 'scrapLocationId') : null;

        // ADR-0003: consumption per input lot of the WO's material at the run's work center, measured
        $lines = [];
        foreach (array_values($data['consumptions']) as $i => $c) {
            $item = strtolower($c['itemId']);
            $lot = isset($c['lotId']) ? strtolower($c['lotId']) : null;
            $k = "{$item}|{$lot}";
            if (isset($lines[$k])) {
                throw ValidationException::withMessages(["consumptions.{$i}.lotId" => 'Enter each lot once.']);
            }
            $lines[$k] = ['i' => $i, 'item' => $item, 'lot' => $lot] + $c;
        }
        ksort($lines);
        $legs = [];
        $genealogy = [];
        $totalPcs = array_reduce(array_values($outputs), static fn (string $sum, string $q) => bcadd($sum, self::num($q), 6), '0');
        $outputLot = null;
        if ($outputs !== [] && (bool) $r->lot_controlled) {
            $day = $this->calendar->resolve($db, $plant, new DateTimeImmutable((string) $db->selectOne('select now()::text as t')->t))->productionDate;
            $lotNo = $this->lots->next($db, (string) $r->item_id, $day);
            $outputLot = (string) $db->selectOne(
                "insert into lots (tenant_id, item_id, lot_no, origin) values ((select c.tenant from ctx_api.context() c), ?, ?, 'OUTPUT') returning id",
                [$r->item_id, $lotNo],
            )->id;
        }
        foreach ($lines as $line) {
            $i = $line['i'];
            $mu = $db->selectOne('select i.base_uom_id, u.code as uom, u.decimals from items i join uoms u on u.id = i.base_uom_id where i.id = ?', [$line['item']])
                ?? throw ValidationException::withMessages(["consumptions.{$i}.itemId" => 'Unknown material.']);
            $this->precision($line['qty'], $mu, "consumptions.{$i}.qty");
            // held until the posting commits, so what the WO has there cannot change in between (BR-INV-05 order: by key)
            $db->select("select id from stock_balances where location_id = ? and item_id = ? and lot_id is not distinct from ?::uuid and stock_status = 'UNRESTRICTED' for update",
                [$r->map_id, $line['item'], $line['lot']]);
            $there = self::num((string) $db->selectOne(
                "select coalesce(sum(qty_signed), 0)::text as q from inventory_movements
                  where wo_id = ? and location_id = ? and item_id = ? and lot_id is not distinct from ?::uuid and stock_status = 'UNRESTRICTED'",
                [$r->work_order_id, $r->map_id, $line['item'], $line['lot']],
            )->q);
            $measured = self::num($line['qty']);
            $used = $line['method'] === 'WEIGHED_CONSUMED' ? $measured : bcsub($there, $measured, 6);
            if (bccomp($used, '0', 6) <= 0) {
                throw ValidationException::withMessages(["consumptions.{$i}.qty" => bccomp($there, '0', 6) <= 0
                    ? "{$r->wo_no} has none of this lot at the work center."
                    : ($line['method'] === 'WEIGHED_CONSUMED' ? 'Enter the consumed quantity.' : "What is left must be less than the {$this->plain($there)} {$mu->uom} {$r->wo_no} has here.")]);
            }
            if (bccomp($used, $there, 6) > 0) {
                // BR-CON-03, doc 14 #26: never more than the work order has of that lot at the work center
                throw new DomainProblem(409, 'INSUFFICIENT_STOCK', 'Not at the work center',
                    "{$r->wo_no} has only {$this->plain($there)} {$mu->uom} of this lot at the work center; the posting consumes {$this->plain($used)}.");
            }
            $evidence = trim((string) $line['evidence']);
            $legs[] = new PostingLeg('CONSUME', (string) $r->map_id, $line['item'], $line['lot'], 'UNRESTRICTED', '-'.$this->plain($used), woId: (string) $r->work_order_id,
                measurementMethod: $line['method'], measuredQty: $this->plain($measured), measurementEvidence: $evidence);
            $legs[] = new PostingLeg('CONSUME', $consumed, $line['item'], $line['lot'], 'UNRESTRICTED', $this->plain($used));
            if ($line['lot'] !== null && $outputLot !== null) {
                $genealogy[] = new GenealogyLink($line['lot'], $outputLot, $this->plain($used), (string) $mu->base_uom_id, $this->plain($totalPcs), (string) $db->selectOne('select base_uom_id from items where id = ?', [$r->item_id])->base_uom_id);
            }
        }

        $status = [
            'OUTPUT_GOOD' => [(bool) $r->inspection_required ? 'PENDING_QC' : 'QC_RELEASED', $qcArea],
            'OUTPUT_NG_REWORK' => ['REWORK', $ngArea],
            'OUTPUT_NG_REJECT' => ['REJECTED', $ngArea],
        ];
        foreach ($outputs as $code => $qty) {
            [$st, $to] = $status[$code];
            $legs[] = new PostingLeg($code, $produced, (string) $r->item_id, $outputLot, $st, '-'.$qty);
            $legs[] = new PostingLeg($code, (string) $to, (string) $r->item_id, $outputLot, $st, $qty);
        }
        foreach ($scrap as $i => $s) {
            $item = $db->selectOne("select i.id, i.lot_controlled, u.code as uom, u.decimals from items i join uoms u on u.id = i.base_uom_id where i.id = ? and i.item_type = 'SCRAP'", [strtolower($s['itemId'])])
                ?? throw ValidationException::withMessages(["scrap.{$i}.itemId" => 'Choose a scrap item.']);
            $this->precision($s['qty'], $item, "scrap.{$i}.qty");
            $note = self::optionalText($s['note'] ?? null);
            if ($s['reasonCode'] === 'OTHER' && mb_strlen((string) $note) < 3) {
                throw ValidationException::withMessages(["scrap.{$i}.note" => 'Explain the scrap when the reason is Other.']);
            }
            $scrapLot = null;
            if ((bool) $item->lot_controlled) {
                $day = $this->calendar->resolve($db, $plant, new DateTimeImmutable((string) $db->selectOne('select now()::text as t')->t))->productionDate;
                $scrapLot = (string) $db->selectOne("insert into lots (tenant_id, item_id, lot_no, origin) values ((select c.tenant from ctx_api.context() c), ?, ?, 'OUTPUT') returning id",
                    [$item->id, $this->lots->next($db, (string) $item->id, $day)])->id;
            }
            $legs[] = new PostingLeg('OUTPUT_SCRAP', $produced, (string) $item->id, $scrapLot, 'UNRESTRICTED', '-'.$s['qty'], reasonCode: $s['reasonCode'], reasonNote: $note);
            $legs[] = new PostingLeg('OUTPUT_SCRAP', (string) $scrapYard, (string) $item->id, $scrapLot, 'UNRESTRICTED', $s['qty'], reasonCode: $s['reasonCode'], reasonNote: $note);
        }

        $result = $this->postings->post($db, new PostingCommand('PRODUCTION_POSTING', $plant, $key, $legs, $genealogy, note: self::optionalText($data['note'] ?? null),
            sourceType: 'production_run', sourceId: (string) $r->id, requestHash: $hash, correctsDocumentId: $corrects));
        $this->outbox->publish($db, 'production.output_posted', 'inventory_document', $result->documentId,
            ['plantId' => $plant, 'runId' => $r->id, 'workOrderId' => $r->work_order_id, 'pendingQc' => isset($outputs['OUTPUT_GOOD']) && (bool) $r->inspection_required]);

        return $this->posted($db, $result);
    }

    /** M4: a measured quantity may be zero (nothing left) — consumed is checked above zero separately. */
    public const string MEASURED = '/^\d{1,12}(\.\d{1,6})?$/';

    private function posted(EoraPostgresConnection $db, PostingResult $result): JsonResponse
    {
        $lot = $db->selectOne(
            "select l.id, l.lot_no from inventory_movements m join lots l on l.id = m.lot_id
              where m.document_id = ? and m.movement_code in ('OUTPUT_GOOD', 'OUTPUT_NG_REWORK', 'OUTPUT_NG_REJECT') limit 1",
            [$result->documentId],
        );

        // the posting answer of doc 24 §5.3: its lot is the output lot (null when only scrap was posted)
        return response()->json(['data' => $result->toArray() + ['replayed' => $result->replayed, 'lotId' => $lot?->id, 'lotNo' => $lot?->lot_no]],
            $result->replayed ? 200 : 201);
    }

    /** A run visible to the reader (404 outside their plants). */
    private function run(EoraPostgresConnection $db, string $id, bool $lock = false): stdClass
    {
        $id = $this->existing($db, 'production_runs', $id);
        $run = $db->selectOne('select r.*, p.company_id from production_runs r join plants p on p.id = r.plant_id where r.id = ?'.($lock ? ' for update of r' : ''), [$id])
            ?? throw new NotFoundHttpException;
        $scopes = $this->authorizer->requireAnyOf($db, ['production.run.view']);
        if (! array_filter($scopes, static fn (Scope $s) => $s->covers(Scope::plant((string) $run->plant_id, (string) $run->company_id)))) {
            throw new NotFoundHttpException;
        }

        return $run;
    }

    private function openRun(EoraPostgresConnection $db, string $id): stdClass
    {
        $r = $this->run($db, $id, true);
        $this->authorizer->authorize($db, self::RUN, Scope::plant((string) $r->plant_id, (string) $r->company_id));
        if ($r->status !== 'OPEN') {
            throw new DomainProblem(409, 'INVALID_STATE_TRANSITION', 'Run not open', 'This run is already '.strtolower((string) $r->status).'.');
        }

        return $r;
    }

    /** @param  list<mixed>  $bindings */
    private function transition(EoraPostgresConnection $db, stdClass $r, int $expected, string $set, array $bindings, string $action): void
    {
        $before = $this->state($db, 'production_runs', (string) $r->id);
        if ($db->update("update production_runs set {$set} where id = ? and version = ?", [...$bindings, $r->id, $expected]) === 0) {
            throw Concurrency::conflict();
        }
        $this->audit->record($db, $action, 'production_run', (string) $r->id, $before, $this->state($db, 'production_runs', (string) $r->id), [], (string) $r->plant_id);
    }

    /** The run (list representation) with its postings and what its work order has at its work center; ETag = version. */
    private function respondRun(EoraPostgresConnection $db, string $id, int $status = 200): JsonResponse
    {
        $row = $this->rows->find($db, new ProductionRunsQuery, $id) ?? throw new LogicException('Written run not visible.');
        $row['atWorkCenter'] = array_map(static fn (stdClass $m) => [
            'itemId' => (string) $m->item_id, 'itemCode' => (string) $m->item_code, 'itemName' => (string) $m->item_name,
            'lotId' => $m->lot_id === null ? null : (string) $m->lot_id, 'lotNo' => $m->lot_no === null ? null : (string) $m->lot_no,
            'qty' => (string) $m->qty, 'uomCode' => (string) $m->uom_code, 'uomDecimals' => (int) $m->decimals,
        ], $db->select(
            "select m.item_id, i.code as item_code, i.name as item_name, m.lot_id, l.lot_no, trim_scale(sum(m.qty_signed))::text as qty, u.code as uom_code, u.decimals
               from production_runs r join locations ml on ml.plant_id = r.plant_id and ml.location_type = 'MAT_AT_PROD' and ml.work_center_id = r.work_center_id
               join inventory_movements m on m.wo_id = r.work_order_id and m.location_id = ml.id and m.stock_status = 'UNRESTRICTED'
               join items i on i.id = m.item_id join uoms u on u.id = i.base_uom_id left join lots l on l.id = m.lot_id
              where r.id = ? group by m.item_id, i.code, i.name, m.lot_id, l.lot_no, u.code, u.decimals having sum(m.qty_signed) <> 0 order by i.code, l.lot_no",
            [$id],
        ));
        $row['plantToday'] = $this->calendar->resolve($db, (string) $row['plantId'], new DateTimeImmutable)->productionDate;

        return Concurrency::withVersion(response()->json(['data' => $row], $status), (int) $row['version']);
    }

    private function precision(string $qty, stdClass $unit, string $field): void
    {
        $dot = strpos($qty, '.');
        $decimals = $dot === false ? 0 : strlen(rtrim(substr($qty, $dot + 1), '0'));
        if ($decimals > (int) $unit->decimals) {
            throw ValidationException::withMessages([$field => (int) $unit->decimals === 0 ? "Enter a whole number of {$unit->uom}." : "Enter {$unit->uom} with at most {$unit->decimals} decimals."]);
        }
    }

    /** @return numeric-string */
    private static function num(string $value): string
    {
        return is_numeric($value) ? $value : throw new LogicException("Not a quantity: {$value}");
    }

    private function plain(string $qty): string
    {
        return str_contains($qty, '.') ? (rtrim(rtrim($qty, '0'), '.') ?: '0') : $qty;
    }
}
