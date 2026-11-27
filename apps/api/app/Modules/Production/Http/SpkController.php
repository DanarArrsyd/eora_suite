<?php

declare(strict_types=1);

namespace App\Modules\Production\Http;

use App\Modules\Planning\Http\WorkOrderController;
use App\Modules\Production\Application\SpkGenerator;
use App\Modules\Production\Application\SpkHours;
use App\Modules\Production\Queries\SpkQuery;
use App\Platform\Access\Authorizer;
use App\Platform\Access\Scope;
use App\Platform\Audit\AuditRecorder;
use App\Platform\Calendar\BusinessCalendar;
use App\Platform\Calendar\ShiftCalendar;
use App\Platform\Database\EoraPostgresConnection;
use App\Platform\Exceptions\DomainProblem;
use App\Platform\Http\Concurrency;
use App\Platform\Http\MasterWrites;
use App\Platform\Outbox\OutboxPublisher;
use App\Platform\Query\QueryRunner;
use DateTimeImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use LogicException;
use stdClass;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * SPK per Line — daily production dispatch (doc 38, D-138), slice 1. The planner drafts one SPK per line (work center) and
 * production date, plans released work-order operations on the line's machines, sees the load per machine against the
 * line's hours per shift, and releases it to production; a released SPK changes only through a revision. An SPK never
 * posts stock. Every write takes If-Match on the SPK and is audited; the database guards repeat each rule.
 */
final class SpkController
{
    use MasterWrites;

    private const string VIEW = 'production.spk.view';

    private const string CREATE = 'production.spk.create';

    private const string RELEASE = 'production.spk.release';

    private const string CLOSE = 'production.spk.close';

    public const string QTY = '/^\d{1,12}(\.\d{1,6})?$/';

    public function __construct(
        private readonly Authorizer $authorizer,
        private readonly AuditRecorder $audit,
        private readonly QueryRunner $rows,
        private readonly BusinessCalendar $calendar,
        private readonly OutboxPublisher $outbox,
        private readonly SpkGenerator $generator,
        private readonly WorkOrderController $workOrders,
        private readonly ShiftCalendar $shifts,
    ) {}

    /**
     * Generate SPK (doc 38 §12, D-139) for a plant and a production day (today or later): work orders for the Daily
     * Recovery shortfall + safety stock, released by the planner, and draft SPK lines per line over preferred and
     * alternative machines and shifts, with proposed overtime. Serialised per plant with Daily Recovery; nothing is
     * released to production — the planner reviews each draft SPK.
     */
    public function generate(Request $request): JsonResponse
    {
        $data = $request->validate(['plantId' => ['required', 'uuid'], 'productionDate' => ['required', 'date_format:Y-m-d']]);
        $db = $this->db();
        $plant = $db->selectOne('select id, company_id from plants where id = ?', [strtolower($data['plantId'])])
            ?? throw ValidationException::withMessages(['plantId' => 'Unknown plant.']);
        $scope = Scope::plant((string) $plant->id, (string) $plant->company_id);
        foreach ([self::CREATE, 'planning.wo.create', 'planning.wo.submit'] as $permission) {
            $this->authorizer->authorize($db, $permission, $scope);
        }
        $today = $this->today($db, (string) $plant->id);
        if ($data['productionDate'] < $today) {
            throw ValidationException::withMessages(['productionDate' => "Choose today ({$today}) or a later day."]);
        }
        // one generate or Daily Recovery write at a time per plant: both read and change the same supply
        $db->select('select pg_advisory_xact_lock(hashtextextended(?,0))', ['daily-recovery:'.$plant->id]);
        $result = $this->generator->generate($db, (string) $plant->id, $data['productionDate']);

        return response()->json(['data' => ['plantId' => (string) $plant->id, 'productionDate' => $data['productionDate']] + $result]);
    }

    /** A new DRAFT SPK for a line and a production day (today or later at the plant). */
    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'plantId' => ['required', 'uuid'],
            'workCenterId' => ['required', 'uuid'],
            'productionDate' => ['required', 'date_format:Y-m-d'],
            'reference' => ['nullable', 'string', 'max:60'],
            'note' => ['nullable', 'string', 'max:500'],
        ]);
        $db = $this->db();
        $plant = $db->selectOne('select id, company_id from plants where id = ?', [strtolower($data['plantId'])])
            ?? throw ValidationException::withMessages(['plantId' => 'Unknown plant.']);
        $this->authorizer->authorize($db, self::CREATE, Scope::plant((string) $plant->id, (string) $plant->company_id));
        $line = $db->selectOne("select id from work_centers where id = ? and plant_id = ? and status = 'ACTIVE'", [strtolower($data['workCenterId']), $plant->id])
            ?? throw ValidationException::withMessages(['workCenterId' => 'Choose an active line (work center) of this plant.']);
        $today = $this->today($db, (string) $plant->id);
        if ($data['productionDate'] < $today) {
            throw ValidationException::withMessages(['productionDate' => "Choose today ({$today}) or a later day."]);
        }
        if ($db->selectOne("select 1 as ok from spk_headers where plant_id = ? and work_center_id = ? and production_date = ? and status in ('DRAFT', 'RELEASED')",
            [$plant->id, $line->id, $data['productionDate']]) !== null) {
            throw new DomainProblem(409, 'SPK_EXISTS', 'SPK already exists', 'This line already has an SPK for that day. Open it, or revise it if it is released.');
        }
        $id = (string) $db->selectOne(
            'insert into spk_headers (tenant_id, plant_id, work_center_id, production_date, reference, note)
             values ((select c.tenant from ctx_api.context() c), ?, ?, ?, ?, ?) returning id',
            [$plant->id, $line->id, $data['productionDate'], self::optionalText($data['reference'] ?? null), self::optionalText($data['note'] ?? null)],
        )->id;
        $this->audit->record($db, 'spk.created', 'spk', $id, null, $this->state($db, 'spk_headers', $id), [], (string) $plant->id);

        return $this->respondSpk($db, $id, 201);
    }

    /** One SPK with its lines, hours per line and load per machine (404 outside the reader's plants). */
    public function show(string $spk): JsonResponse
    {
        $db = $this->db();

        return $this->respondSpk($db, (string) $this->visible($db, $spk)->id);
    }

    /** What the planner can plan on this SPK's line: released WO operations on the line, its machines and the plant's shifts. */
    public function options(string $spk): JsonResponse
    {
        $db = $this->db();
        $h = $this->visible($db, $spk);
        $operations = $db->select(
            "select w.id as work_order_id, w.wo_no, w.status, i.code as item_code, i.name as item_name, trim_scale(w.qty)::text as qty, u.code as uom_code,
                    w.due_date::text as due_date, o.id as operation_id, o.op_seq, pt.name as process_name, o.std_setup_minutes,
                    trim_scale(o.std_good_output_per_hour)::text as sph, trim_scale(o.std_cycle_seconds)::text as cycle_seconds, o.cycle_basis, o.parts_per_cycle
               from work_orders w join items i on i.id = w.item_id join uoms u on u.id = w.uom_id
               join routing_operations o on o.routing_version_id = w.routing_version_id and o.work_center_id = ?
               join process_types pt on pt.id = o.process_type_id
              where w.plant_id = ? and w.status in ('RELEASED', 'IN_PROGRESS')
              order by w.due_date, w.wo_no, o.op_seq limit 500",
            [$h->work_center_id, $h->plant_id],
        );

        return response()->json(['data' => [
            'operations' => array_map(static fn (stdClass $o) => [
                'workOrderId' => (string) $o->work_order_id, 'workOrderCode' => (string) $o->wo_no, 'workOrderStatus' => (string) $o->status,
                'itemCode' => (string) $o->item_code, 'itemName' => (string) $o->item_name, 'qty' => (string) $o->qty, 'uomCode' => (string) $o->uom_code,
                'dueDate' => (string) $o->due_date, 'operationId' => (string) $o->operation_id, 'opSeq' => (int) $o->op_seq, 'processName' => (string) $o->process_name,
                'setupMinutes' => SpkHours::plain((string) $o->std_setup_minutes), 'sph' => SpkHours::sph($o),
            ], $operations),
            'machines' => array_map(static fn (stdClass $m) => ['id' => (string) $m->id, 'code' => (string) $m->code, 'name' => (string) $m->name],
                $db->select("select id, code, name from machines where work_center_id = ? and status = 'ACTIVE' order by code", [$h->work_center_id])),
            // the shifts that run on the SPK's day (D-142: weekday times), in the order they run
            'shifts' => array_map(static fn (array $s) => ['id' => $s['id'], 'code' => $s['code'], 'name' => $s['name']],
                $this->shifts->shiftsOn($db, (string) $h->plant_id, (string) $h->production_date)),
        ]]);
    }

    /** Replaces the header text and all lines of a DRAFT SPK (If-Match). */
    public function update(Request $request, string $spk): JsonResponse
    {
        $data = $request->validate([
            'reference' => ['nullable', 'string', 'max:60'],
            'note' => ['nullable', 'string', 'max:500'],
            'lines' => ['present', 'array', 'max:200'],
            'lines.*.workOrderId' => ['required', 'uuid'],
            'lines.*.routingOperationId' => ['required', 'uuid'],
            'lines.*.machineId' => ['required', 'uuid'],
            'lines.*.shiftId' => ['nullable', 'uuid'],
            'lines.*.plannedQty' => ['required', 'string', 'regex:'.SpkController::QTY],
            'lines.*.priority' => ['required', 'integer', 'min:1', 'max:99'],
            'lines.*.setupMinutes' => ['nullable', 'numeric', 'min:0', 'max:1440'],
            'lines.*.qcSampleMinutes' => ['nullable', 'numeric', 'min:0', 'max:1440'],
            'lines.*.note' => ['nullable', 'string', 'max:300'],
        ]);
        $expected = Concurrency::expectedVersion($request);
        $db = $this->db();
        $h = $this->writable($db, $spk, ['DRAFT'], self::CREATE);
        $checked = [];
        foreach ($data['lines'] as $i => $line) {
            $checked[] = $this->checkedLine($db, $h, $line, $i);
        }
        $generated = $this->generatedOn($db, (string) $h->id);
        $before = $this->state($db, 'spk_headers', (string) $h->id);
        if ($db->update('update spk_headers set reference = ?, note = ? where id = ? and version = ?',
            [self::optionalText($data['reference'] ?? null), self::optionalText($data['note'] ?? null), $h->id, $expected]) === 0) {
            throw Concurrency::conflict();
        }
        $db->delete('delete from spk_lines where spk_id = ?', [$h->id]);
        foreach ($checked as $n => $line) {
            $db->insert(
                'insert into spk_lines (tenant_id, spk_id, line_no, work_order_id, routing_operation_id, machine_id, shift_id, planned_qty, priority, setup_minutes, qc_sample_minutes, note)
                 values ((select c.tenant from ctx_api.context() c), ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
                [$h->id, $n + 1, ...array_values($line)],
            );
        }
        $this->audit->record($db, 'spk.updated', 'spk', (string) $h->id, $before, $this->state($db, 'spk_headers', (string) $h->id), ['lines' => count($checked)], (string) $h->plant_id);
        $this->cancelUnplanned($db, $generated, (string) $h->plant_id);

        return $this->respondSpk($db, (string) $h->id);
    }

    /**
     * DRAFT → RELEASED (If-Match). The number `SPK-<company>-<DDMMYY of release>-<0001>` (S-5) is taken now from a daily
     * series per company; a revision keeps its SPK's number and supersedes it. Production of the plant is told.
     */
    public function release(Request $request, string $spk): JsonResponse
    {
        $expected = Concurrency::expectedVersion($request);
        $db = $this->db();
        $h = $this->writable($db, $spk, ['DRAFT'], self::RELEASE);
        if ($db->selectOne('select 1 as ok from spk_lines where spk_id = ? limit 1', [$h->id]) === null) {
            throw ValidationException::withMessages(['lines' => 'Add at least one line before releasing the SPK.']);
        }
        // the planned WOs must still be released or in production; FOR SHARE holds off a concurrent cancel until this commits
        $planned = $db->select(
            'select w.wo_no, w.status from work_orders w where w.id in (select l.work_order_id from spk_lines l where l.spk_id = ?) order by w.id for share of w',
            [$h->id],
        );
        $blocked = array_values(array_map(static fn (stdClass $w) => (string) $w->wo_no,
            array_filter($planned, static fn (stdClass $w) => ! in_array($w->status, ['RELEASED', 'IN_PROGRESS'], true))));
        if ($blocked !== []) {
            throw new DomainProblem(409, 'INVALID_STATE_TRANSITION', 'Work order no longer released',
                implode(', ', $blocked).' is no longer released or in production. Remove its lines and save before releasing.');
        }
        // the planned machines must still be active on this line; FOR SHARE holds off a concurrent machine change until this commits
        $machines = $db->select(
            'select m.code, m.status, m.work_center_id from machines m where m.id in (select l.machine_id from spk_lines l where l.spk_id = ?) order by m.id for share of m',
            [$h->id],
        );
        $idle = array_values(array_map(static fn (stdClass $m) => (string) $m->code,
            array_filter($machines, static fn (stdClass $m) => $m->status !== 'ACTIVE' || $m->work_center_id !== $h->work_center_id)));
        if ($idle !== []) {
            throw new DomainProblem(409, 'INVALID_STATE_TRANSITION', 'Machine no longer on this line',
                implode(', ', $idle).' is no longer an active machine of this line. Move its lines to another machine and save before releasing.');
        }
        // shifts saved before a new calendar version came into force for the day are no longer shifts of that day
        $stale = $db->select(
            'select l.line_no from spk_lines l where l.spk_id = ? and l.shift_id is not null
                and not exists (select 1 from shifts s where s.id = l.shift_id and s.shift_calendar_id is not distinct from ?::uuid) order by l.line_no',
            [$h->id, $this->shiftCalendar($db, (string) $h->plant_id, (string) $h->production_date)],
        );
        if ($stale !== []) {
            throw new DomainProblem(409, 'INVALID_STATE_TRANSITION', 'Shift calendar changed',
                'Line '.implode(', ', array_map(static fn (stdClass $l) => (string) $l->line_no, $stale)).' uses a shift that is no longer in the calendar for this day. Pick the shift again and save before releasing.');
        }
        $number = $h->previous_id !== null
            ? (string) $db->selectOne('select spk_no from spk_headers where id = ?', [$h->previous_id])->spk_no
            : $this->nextNumber($db, $h);
        $before = $this->state($db, 'spk_headers', (string) $h->id);
        // generated WOs the revision dropped are reconciled once the released plan they were on is superseded (D-139)
        $generated = $h->previous_id === null ? [] : $this->generatedOn($db, (string) $h->previous_id);
        if ($h->previous_id !== null) {
            $previous = $this->state($db, 'spk_headers', (string) $h->previous_id);
            if ($db->update("update spk_headers set status = 'SUPERSEDED' where id = ? and status = 'RELEASED'", [$h->previous_id]) !== 1) {
                throw new DomainProblem(409, 'INVALID_STATE_TRANSITION', 'SPK changed', 'The SPK this revision replaces is no longer released. Reload it.');
            }
            $this->audit->record($db, 'spk.superseded', 'spk', (string) $h->previous_id, $previous, $this->state($db, 'spk_headers', (string) $h->previous_id), [], (string) $h->plant_id);
        }
        if ($db->update("update spk_headers set status = 'RELEASED', spk_no = ?, released_by = (select c.actor from ctx_api.context() c), released_at = now()
                          where id = ? and version = ?", [$number, $h->id, $expected]) === 0) {
            throw Concurrency::conflict();
        }
        $this->audit->record($db, 'spk.released', 'spk', (string) $h->id, $before, $this->state($db, 'spk_headers', (string) $h->id), ['spkNo' => $number], (string) $h->plant_id);
        $this->cancelUnplanned($db, $generated, (string) $h->plant_id);
        // production is told through the outbox (SpkReleasedNotifier), in this transaction
        $this->outbox->publish($db, 'production.spk_released', 'spk', (string) $h->id, ['plantId' => (string) $h->plant_id, 'spkNo' => $number, 'revision' => (int) $h->revision]);

        return $this->respondSpk($db, (string) $h->id);
    }

    /** RELEASED → a new DRAFT revision with the same lines and a reason; the released SPK stays in force until it is released. */
    public function revise(Request $request, string $spk): JsonResponse
    {
        $data = $request->validate(['reason' => ['required', 'string', 'min:3', 'max:500']]);
        $expected = Concurrency::expectedVersion($request);
        $db = $this->db();
        $h = $this->writable($db, $spk, ['RELEASED'], self::CREATE);
        if ((int) $h->version !== $expected) {
            throw Concurrency::conflict();
        }
        if ($db->selectOne("select 1 as ok from spk_headers where previous_id = ? and status = 'DRAFT'", [$h->id]) !== null) {
            throw new DomainProblem(409, 'SPK_EXISTS', 'Revision already drafted', 'This SPK already has a draft revision. Open it.');
        }
        $id = (string) $db->selectOne(
            'insert into spk_headers (tenant_id, plant_id, work_center_id, production_date, revision, previous_id, reference, note, revision_reason)
             values ((select c.tenant from ctx_api.context() c), ?, ?, ?, ?, ?, ?, ?, ?) returning id',
            [$h->plant_id, $h->work_center_id, $h->production_date, (int) $h->revision + 1, $h->id, $h->reference, $h->note, trim($data['reason'])],
        )->id;
        $db->insert(
            'insert into spk_lines (tenant_id, spk_id, line_no, work_order_id, routing_operation_id, machine_id, shift_id, planned_qty, priority, setup_minutes, qc_sample_minutes, note)
             select tenant_id, ?, line_no, work_order_id, routing_operation_id, machine_id,
                    -- a shift of a calendar no longer in force on the day is cleared: the planner picks the new one on the draft
                    case when exists (select 1 from shifts s where s.id = l.shift_id and s.shift_calendar_id = ?) then shift_id end,
                    planned_qty, priority, setup_minutes, qc_sample_minutes, note
               from spk_lines l where l.spk_id = ? and exists (select 1 from work_orders w where w.id = l.work_order_id and w.status in (\'RELEASED\', \'IN_PROGRESS\'))
                and exists (select 1 from machines m where m.id = l.machine_id and m.status = \'ACTIVE\' and m.work_center_id = ?)',
            [$id, $this->shiftCalendar($db, (string) $h->plant_id, (string) $h->production_date), $h->id, $h->work_center_id],
        );
        $this->audit->record($db, 'spk.revised', 'spk', $id, null, $this->state($db, 'spk_headers', $id), ['revises' => (string) $h->id, 'reason' => trim($data['reason'])], (string) $h->plant_id);

        return $this->respondSpk($db, $id, 201);
    }

    /** RELEASED → CLOSED (If-Match): the day's plan is done. */
    public function close(Request $request, string $spk): JsonResponse
    {
        return $this->move($request, $spk, 'RELEASED', 'CLOSED', self::CLOSE, 'spk.closed', ', closed_by = (select c.actor from ctx_api.context() c), closed_at = now()');
    }

    /** DRAFT → CANCELLED (If-Match); a cancelled revision leaves its released SPK in force. */
    public function cancel(Request $request, string $spk): JsonResponse
    {
        return $this->move($request, $spk, 'DRAFT', 'CANCELLED', self::CREATE, 'spk.cancelled', '');
    }

    private function move(Request $request, string $spk, string $from, string $to, string $permission, string $action, string $extra): JsonResponse
    {
        $expected = Concurrency::expectedVersion($request);
        $db = $this->db();
        $h = $this->writable($db, $spk, [$from], $permission);
        $generated = $to === 'CANCELLED' ? $this->generatedOn($db, (string) $h->id) : [];
        $before = $this->state($db, 'spk_headers', (string) $h->id);
        if ($db->update("update spk_headers set status = '{$to}'{$extra} where id = ? and version = ?", [$h->id, $expected]) === 0) {
            throw Concurrency::conflict();
        }
        $this->audit->record($db, $action, 'spk', (string) $h->id, $before, $this->state($db, 'spk_headers', (string) $h->id), [], (string) $h->plant_id);
        $this->cancelUnplanned($db, $generated, (string) $h->plant_id);

        return $this->respondSpk($db, (string) $h->id);
    }

    /**
     * @param  array<string, mixed>  $line
     * @return array<string, mixed> column values in insert order
     */
    private function checkedLine(EoraPostgresConnection $db, stdClass $h, array $line, int $i): array
    {
        $wo = $db->selectOne('select id, wo_no, plant_id, status, routing_version_id from work_orders where id = ?', [strtolower((string) $line['workOrderId'])]);
        if ($wo === null || (string) $wo->plant_id !== (string) $h->plant_id || ! in_array($wo->status, ['RELEASED', 'IN_PROGRESS'], true)) {
            throw ValidationException::withMessages(["lines.{$i}.workOrderId" => 'Choose a released or in-production work order of this plant.']);
        }
        if (bccomp(SpkHours::num($line['plannedQty']), '0', 6) <= 0) {
            throw ValidationException::withMessages(["lines.{$i}.plannedQty" => 'Enter a quantity above zero.']);
        }
        $op = $db->selectOne('select id, std_setup_minutes from routing_operations where id = ? and routing_version_id = ? and work_center_id = ?',
            [strtolower((string) $line['routingOperationId']), $wo->routing_version_id, $h->work_center_id])
            ?? throw ValidationException::withMessages(["lines.{$i}.routingOperationId" => "Choose an operation of {$wo->wo_no} on this line."]);
        $db->selectOne("select 1 as ok from machines where id = ? and work_center_id = ? and status = 'ACTIVE'", [strtolower((string) $line['machineId']), $h->work_center_id])
            ?? throw ValidationException::withMessages(["lines.{$i}.machineId" => 'Choose an active machine of this line.']);
        $shift = isset($line['shiftId']) ? strtolower((string) $line['shiftId']) : null;
        if ($shift !== null && ! in_array($shift, array_column($this->shifts->shiftsOn($db, (string) $h->plant_id, (string) $h->production_date), 'id'), true)) {
            throw ValidationException::withMessages(["lines.{$i}.shiftId" => 'Choose a shift that runs on this day in the calendar in force.']);
        }

        return [
            'work_order_id' => (string) $wo->id,
            'routing_operation_id' => (string) $op->id,
            'machine_id' => strtolower((string) $line['machineId']),
            'shift_id' => $shift,
            'planned_qty' => (string) $line['plannedQty'],
            'priority' => (int) $line['priority'],
            'setup_minutes' => isset($line['setupMinutes']) ? (string) $line['setupMinutes'] : (string) $op->std_setup_minutes,
            'qc_sample_minutes' => isset($line['qcSampleMinutes']) ? (string) $line['qcSampleMinutes'] : '5',
            'note' => self::optionalText($line['note'] ?? null),
        ];
    }

    /** `SPK-<company code>-<DDMMYY>-<0001>` from the daily series of the company on the plant's release day (no gapless promise, D-049). */
    private function nextNumber(EoraPostgresConnection $db, stdClass $h): string
    {
        $company = (string) $db->selectOne('select c.code from plants p join companies c on c.id = p.company_id where p.id = ?', [$h->plant_id])->code;
        $day = $this->today($db, (string) $h->plant_id);
        $namespace = 'SPK-'.$company;
        if (strlen($namespace) > 26) {
            throw new DomainProblem(409, 'SPK_NUMBER_UNAVAILABLE', 'Company code too long', 'The company code is too long for SPK numbers. Use a code of at most 20 characters.');
        }
        $number = (int) $db->selectOne(
            'insert into daily_lot_counters (tenant_id, namespace, day, last_no) values ((select c.tenant from ctx_api.context() c), ?, ?, 1)
             on conflict (tenant_id, namespace, day) do update set last_no = daily_lot_counters.last_no + 1 returning last_no',
            [$namespace, $day],
        )->last_no;
        if ($number > 9999) {
            throw new DomainProblem(409, 'SPK_NUMBER_UNAVAILABLE', 'Daily SPK numbers used up', 'This company has released 9,999 SPKs today. Release the next one tomorrow.');
        }

        return sprintf('%s-%s-%04d', $namespace, (new DateTimeImmutable($day))->format('dmy'), $number);
    }

    private function visible(EoraPostgresConnection $db, string $spk, bool $lock = false): stdClass
    {
        $scopes = $this->authorizer->requireAnyOf($db, [self::VIEW, self::CREATE]);
        $id = $this->existing($db, 'spk_headers', $spk);
        $h = $db->selectOne('select h.*, h.production_date::text as production_date, p.company_id from spk_headers h join plants p on p.id = h.plant_id where h.id = ?'
            .($lock ? ' for update of h' : ''), [$id]) ?? throw new NotFoundHttpException;
        if (! array_filter($scopes, static fn (Scope $s) => $s->covers(Scope::plant((string) $h->plant_id, (string) $h->company_id)))) {
            throw new NotFoundHttpException;
        }

        return $h;
    }

    /** @param  list<string>  $statuses */
    private function writable(EoraPostgresConnection $db, string $spk, array $statuses, string $permission): stdClass
    {
        $h = $this->visible($db, $spk, true);
        $this->authorizer->authorize($db, $permission, Scope::plant((string) $h->plant_id, (string) $h->company_id));
        if (! in_array($h->status, $statuses, true)) {
            throw new DomainProblem(409, 'INVALID_STATE_TRANSITION', 'SPK status does not allow this', 'This is possible on a '.strtolower(implode(' or ', $statuses)).' SPK only.');
        }

        return $h;
    }

    /** The shift calendar in force at the plant on the day: the plant's own before the tenant's, latest version (BusinessCalendar). */
    private function shiftCalendar(EoraPostgresConnection $db, string $plant, string $day): ?string
    {
        $row = $db->selectOne(
            'select id from shift_calendars where (plant_id = ? or plant_id is null) and effective_from <= ?::date order by (plant_id is null), effective_from desc limit 1',
            [$plant, $day],
        );

        return $row === null ? null : (string) $row->id;
    }

    private function today(EoraPostgresConnection $db, string $plant): string
    {
        return $this->calendar->resolve($db, $plant, new DateTimeImmutable)->calendarDate;
    }

    /**
     * Generated work orders (D-139) planned on this SPK.
     *
     * @return list<string>
     */
    private function generatedOn(EoraPostgresConnection $db, string $spk): array
    {
        return array_values(array_map(static fn (stdClass $r) => (string) $r->work_order_id, $db->select(
            'select distinct l.work_order_id from spk_lines l join spk_generated_orders g on g.work_order_id = l.work_order_id where l.spk_id = ?', [$spk])));
    }

    /**
     * A generated WO that no SPK in force or in draft plans any more (superseded history does not count) is cancelled while nothing was issued or run for it (D-139), when the
     * planner may cancel released work orders; otherwise it stays for the planner to plan or cancel.
     *
     * @param  list<string>  $orders
     */
    private function cancelUnplanned(EoraPostgresConnection $db, array $orders, string $plant): void
    {
        foreach ($orders as $wo) {
            if ($db->selectOne("select 1 as planned from spk_lines l join spk_headers h on h.id = l.spk_id where l.work_order_id = ? and h.status in ('DRAFT', 'RELEASED', 'CLOSED') limit 1", [$wo]) === null
                && (bool) $db->selectOne("select public.eora_actor_may('planning.wo.post', ?::uuid) as ok", [$plant])->ok) {
                $this->workOrders->cancelGenerated($db, $wo, 'No SPK plans it any more (Generate SPK).');
            }
        }
    }

    private function respondSpk(EoraPostgresConnection $db, string $id, int $status = 200): JsonResponse
    {
        $row = $this->rows->find($db, new SpkQuery, $id) ?? throw new LogicException('Written row not visible.');
        $lines = $db->select(
            'select l.id, l.line_no, l.work_order_id, w.wo_no, w.status as wo_status, i.code as item_code, i.name as item_name, trim_scale(w.qty)::text as wo_qty,
                    u.code as uom_code, w.due_date::text as due_date, l.routing_operation_id, o.op_seq, pt.name as process_name,
                    (select count(*) from routing_operations x where x.routing_version_id = o.routing_version_id) as op_count,
                    l.machine_id, m.code as machine_code, m.name as machine_name, l.shift_id, s.code as shift_code,
                    trim_scale(l.planned_qty)::text as planned_qty, l.priority, trim_scale(l.setup_minutes)::text as setup_minutes,
                    trim_scale(l.qc_sample_minutes)::text as qc_sample_minutes, l.note,
                    trim_scale(o.std_good_output_per_hour)::text as sph, trim_scale(o.std_cycle_seconds)::text as cycle_seconds, o.cycle_basis, o.parts_per_cycle,
                    v.paired_item_id
               from spk_lines l join work_orders w on w.id = l.work_order_id join items i on i.id = w.item_id join uoms u on u.id = w.uom_id
               left join routing_versions v on v.id = w.routing_version_id
               join routing_operations o on o.id = l.routing_operation_id join process_types pt on pt.id = o.process_type_id
               join machines m on m.id = l.machine_id left join shifts s on s.id = l.shift_id
              where l.spk_id = ? order by l.priority, l.line_no',
            [$id],
        );
        /** @var array<string, array{machineId: string, machineCode: string, machineName: string, hours: string, notCalculable: int, shifts: array<string, array{shiftId: ?string, shiftCode: ?string, hours: string}>}> $load */
        $load = [];
        $row['lines'] = array_map(static function (stdClass $l) use (&$load): array {
            $sph = SpkHours::sph($l);
            // a paired blanking WO plans pieces per side while SPH counts both sides' good output (D-130, as MPS capacity does)
            $runQty = $l->paired_item_id === null ? (string) $l->planned_qty : bcmul(SpkHours::num($l->planned_qty), '2', 6);
            $hours = SpkHours::hours($sph, $runQty, (string) $l->setup_minutes, (string) $l->qc_sample_minutes);
            $key = (string) $l->machine_id;
            $load[$key] ??= ['machineId' => $key, 'machineCode' => (string) $l->machine_code, 'machineName' => (string) $l->machine_name, 'hours' => '0', 'notCalculable' => 0, 'shifts' => []];
            // one bucket per shift of the machine (lines without a shift share one bucket): each bucket is held to one shift's hours
            $bucket = (string) ($l->shift_id ?? '');
            $load[$key]['shifts'][$bucket] ??= ['shiftId' => $l->shift_id, 'shiftCode' => $l->shift_code, 'hours' => '0'];
            if ($hours === null) {
                $load[$key]['notCalculable']++;
            } else {
                $load[$key]['hours'] = bcadd(SpkHours::num($load[$key]['hours']), SpkHours::num($hours), 6);
                $load[$key]['shifts'][$bucket]['hours'] = bcadd(SpkHours::num($load[$key]['shifts'][$bucket]['hours']), SpkHours::num($hours), 6);
            }

            return [
                'id' => (string) $l->id, 'lineNo' => (int) $l->line_no, 'workOrderId' => (string) $l->work_order_id, 'workOrderCode' => (string) $l->wo_no,
                'workOrderStatus' => (string) $l->wo_status, 'itemCode' => (string) $l->item_code, 'itemName' => (string) $l->item_name,
                'workOrderQty' => (string) $l->wo_qty, 'uomCode' => (string) $l->uom_code, 'dueDate' => (string) $l->due_date,
                'routingOperationId' => (string) $l->routing_operation_id, 'opSeq' => (int) $l->op_seq, 'opCount' => (int) $l->op_count,
                'processName' => (string) $l->process_name, 'machineId' => (string) $l->machine_id, 'machineCode' => (string) $l->machine_code,
                'machineName' => (string) $l->machine_name, 'shiftId' => $l->shift_id, 'shiftCode' => $l->shift_code,
                'plannedQty' => (string) $l->planned_qty, 'priority' => (int) $l->priority, 'setupMinutes' => (string) $l->setup_minutes,
                'qcSampleMinutes' => (string) $l->qc_sample_minutes, 'note' => $l->note, 'sph' => $sph,
                'hours' => $hours === null ? null : SpkHours::round3($hours),
            ];
        }, $lines);
        $perShift = $db->selectOne('select trim_scale(available_hours_per_shift)::text as hours from work_centers where id = ?', [$row['workCenterId']])->hours;
        $row['hoursPerShift'] = $perShift;
        // hours of each shift that day (D-142 C-3): the shift's work hours capped by the line's hours per shift
        $dayShifts = array_column($this->shifts->shiftsOn($db, (string) $row['plantId'], (string) $row['productionDate']), 'workHours', 'id');
        $capOf = static fn (?string $shift): ?string => ShiftCalendar::capacity($shift === null ? null : ($dayShifts[$shift] ?? null), $perShift === null ? null : SpkHours::num($perShift));
        // a machine is overloaded when any one of its shifts is: pooling shifts would hide an over-full shift (doc 38 §3)
        $row['machineLoad'] = array_values(array_map(static function (array $m) use ($capOf): array {
            $shifts = array_values(array_map(static function (array $b) use ($capOf): array {
                $hours = SpkHours::num($b['hours']);
                $cap = $capOf($b['shiftId'] ?? null);

                return ['shiftId' => $b['shiftId'] ?? null, 'shiftCode' => $b['shiftCode'] ?? null, 'hours' => SpkHours::round3($hours),
                    'capacityHours' => $cap === null ? null : SpkHours::round3($cap),
                    'balanceHours' => $cap === null ? null : SpkHours::round3(bcsub($cap, $hours, 6)),
                    'overloaded' => $cap !== null && bccomp($hours, $cap, 6) > 0];
            }, $m['shifts']));
            $caps = array_column($shifts, 'capacityHours');
            $capacity = $caps === [] || in_array(null, $caps, true) ? null : array_reduce($caps, static fn (string $sum, string $c): string => bcadd($sum, SpkHours::num($c), 6), '0');
            $hours = SpkHours::num($m['hours']);

            return ['machineId' => $m['machineId'], 'machineCode' => $m['machineCode'], 'machineName' => $m['machineName'],
                'hours' => SpkHours::round3($hours), 'notCalculableLines' => $m['notCalculable'],
                'capacityHours' => $capacity === null ? null : SpkHours::round3(SpkHours::num($capacity)),
                'balanceHours' => $capacity === null ? null : SpkHours::round3(bcsub(SpkHours::num($capacity), $hours, 6)),
                'overloaded' => in_array(true, array_column($shifts, 'overloaded'), true), 'shifts' => $shifts];
        }, $load));
        $row['revisions'] = array_map(static fn (stdClass $r) => ['id' => (string) $r->id, 'revision' => (int) $r->revision, 'status' => (string) $r->status,
            'reason' => $r->revision_reason], $db->select(
                'select id, revision, status, revision_reason from spk_headers where plant_id = ? and work_center_id = ? and production_date = ?::date
                  order by revision, created_at',
                [$row['plantId'], $row['workCenterId'], $row['productionDate']],
            ));

        return Concurrency::withVersion(response()->json(['data' => $row], $status), (int) $row['version']);
    }
}
