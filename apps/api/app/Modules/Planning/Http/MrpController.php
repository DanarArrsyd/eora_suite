<?php

declare(strict_types=1);

namespace App\Modules\Planning\Http;

use App\Modules\Planning\Mrp\MrpPlanner;
use App\Modules\Planning\Queries\MrpRunsQuery;
use App\Platform\Access\Authorizer;
use App\Platform\Audit\AuditRecorder;
use App\Platform\Calendar\BusinessCalendar;
use App\Platform\Database\EoraPostgresConnection;
use App\Platform\Exceptions\DomainProblem;
use App\Platform\Http\MasterWrites;
use App\Platform\Query\QueryRunner;
use DateTimeImmutable;
use Illuminate\Database\QueryException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use LogicException;

/**
 * PPIC → MRP Results (doc 34 §1.4, D-124, BR-PLN-06): a planner runs MRP from the plant's approved MPS
 * (`planning.mrp.create`); the run is a read-only snapshot of planned work orders (FG and semi-finished) and the material
 * shortage list (need date, order-by date). Read with `planning.mrp.view`. No purchasing document (M5-D5).
 */
final class MrpController
{
    use MasterWrites;

    private const string VIEW = 'planning.mrp.view';

    private const string CREATE = 'planning.mrp.create';

    private const string RELEASE = 'planning.planned_order_release.post';

    public function __construct(
        private readonly Authorizer $authorizer,
        private readonly AuditRecorder $audit,
        private readonly QueryRunner $rows,
        private readonly BusinessCalendar $calendar,
        private readonly MrpPlanner $planner,
    ) {}

    /** Run MRP `MRP-<plant>-<year>-NNNN` from the plant's approved MPS (201); 409 when the MPS is not the approved one. */
    public function store(Request $request): JsonResponse
    {
        $data = $request->validate(['mpsVersionId' => ['required', 'uuid']]);
        $db = $this->db();
        $this->authorizer->requireAnywhere($db, self::CREATE);
        $id = $this->existing($db, 'mps_versions', $data['mpsVersionId']);
        // the MPS stays approved while the run reads it (an approval of its successor waits)
        $mps = $db->selectOne('select id, plant_id, status, mps_no, horizon_start::text as horizon_start, horizon_weeks from mps_versions where id = ? for share', [$id]);
        $this->authorizer->authorize($db, self::CREATE, $this->plantScope($db, (string) $mps->plant_id));
        if ($mps->status !== 'APPROVED') {
            throw new DomainProblem(409, 'INVALID_STATE_TRANSITION', 'MPS not approved', "{$mps->mps_no} is ".strtolower((string) $mps->status).'; MRP runs from the plant’s approved MPS.');
        }
        $today = $this->calendar->resolve($db, (string) $mps->plant_id, new DateTimeImmutable)->calendarDate;
        $year = (int) substr($today, 0, 4);
        $number = (int) $db->selectOne(
            "insert into document_counters (tenant_id, plant_id, doc_type, year, last_no) values ((select c.tenant from ctx_api.context() c), ?, 'MRP', ?, 1)
             on conflict (tenant_id, plant_id, doc_type, year) do update set last_no = document_counters.last_no + 1 returning last_no",
            [$mps->plant_id, $year],
        )->last_no;
        $code = (string) $db->selectOne('select code from plants where id = ?', [$mps->plant_id])->code;
        $run = (string) $db->selectOne(
            'insert into mrp_runs (tenant_id, plant_id, mps_version_id, run_no, plan_date, horizon_start, horizon_weeks)
             values ((select c.tenant from ctx_api.context() c), ?, ?, ?, ?, ?, ?) returning id',
            [$mps->plant_id, $mps->id, sprintf('MRP-%s-%d-%04d', $code, $year, $number), $today, $mps->horizon_start, $mps->horizon_weeks],
        )->id;
        $this->planner->run($db, $run, (string) $mps->plant_id, (string) $mps->id, $today, (string) $mps->horizon_start, (int) $mps->horizon_weeks);
        $this->audit->record($db, 'mrp.run', 'mrp_run', $run, null, $this->state($db, 'mrp_runs', $run), [], (string) $mps->plant_id);

        return $this->respondRun($db, $run, 201);
    }

    /** The run with its items: requirements, supply, planned work orders and shortages, pegs. */
    public function show(string $run): JsonResponse
    {
        $db = $this->db();
        $this->authorizer->requireAnywhere($db, self::VIEW);
        $id = $this->existing($db, 'mrp_runs', $run);
        $this->authorizer->authorize($db, self::VIEW, $this->plantScope($db, (string) $db->selectOne('select plant_id from mrp_runs where id = ?', [$id])->plant_id));

        return $this->respondRun($db, $id);
    }

    /**
     * Release planned work orders of the run to WO Drafts (D-125), all or nothing: only from the latest run of the plant's
     * approved MPS (409 otherwise), MAKE orders without a live WO (409 when one has); one WO per planned order, reason
     * PLANNED, planned quantity and due date (today when passed), pegged to the firm delivery its MPS order covers alone.
     */
    public function release(Request $request, string $run): JsonResponse
    {
        $data = $request->validate(['orderIds' => ['required', 'array', 'min:1', 'max:200'], 'orderIds.*' => ['required', 'uuid', 'distinct']]);
        $db = $this->db();
        $this->authorizer->requireAnywhere($db, self::RELEASE);
        $id = $this->existing($db, 'mrp_runs', $run);
        $r = $db->selectOne('select r.id, r.plant_id, r.run_no, r.mps_version_id, r.created_at from mrp_runs r where r.id = ?', [$id]);
        $this->authorizer->authorize($db, self::RELEASE, $this->plantScope($db, (string) $r->plant_id));
        // the MPS stays approved while its planned orders are released (an approval of its successor waits)
        $mps = $db->selectOne('select status from mps_versions where id = ? for share', [$r->mps_version_id]);
        $latest = $db->selectOne('select id from mrp_runs where mps_version_id = ? order by created_at desc, id desc limit 1', [$r->mps_version_id]);
        if ($mps->status !== 'APPROVED' || (string) $latest->id !== (string) $r->id) {
            throw new DomainProblem(409, 'INVALID_STATE_TRANSITION', 'Not the current plan', "{$r->run_no} is not the latest MRP run of the plant’s approved MPS; release from the latest run.");
        }
        $plant = $db->selectOne('select code from plants where id = ?', [$r->plant_id]);
        $today = $this->calendar->resolve($db, (string) $r->plant_id, new DateTimeImmutable)->calendarDate;
        foreach (array_values(array_unique(array_map('strtolower', $data['orderIds']))) as $n => $orderId) {
            $o = $db->selectOne(
                'select o.id, o.kind, o.mps_planned_order_id, trim_scale(o.qty)::text as qty, o.due_date::text as due, mi.item_id, i.base_uom_id
                   from mrp_orders o join mrp_items mi on mi.id = o.mrp_item_id join items i on i.id = mi.item_id where o.id = ? and o.mrp_run_id = ?',
                [$orderId, $r->id],
            ) ?? throw ValidationException::withMessages(["orderIds.{$n}" => 'This planned order is not in this run.']);
            if ($o->kind !== 'MAKE') {
                throw ValidationException::withMessages(["orderIds.{$n}" => 'A material shortage is bought, not released to a work order.']);
            }
            // one live WO per planned order, and per MPS planned order across MRP runs of the same MPS
            if ($db->selectOne(
                "select 1 as ok from work_orders w join mrp_orders x on x.id = w.mrp_order_id
                  where w.status <> 'CANCELLED' and (x.id = ? or (?::uuid is not null and x.mps_planned_order_id = ?::uuid))",
                [$o->id, $o->mps_planned_order_id, $o->mps_planned_order_id],
            ) !== null) {
                throw self::alreadyReleased();
            }
            // the firm delivery the MPS order covers alone (BR-DEM-12)
            $line = $o->mps_planned_order_id === null ? null : $db->select(
                "select distinct d.schedule_line_id from mps_pegs p join mps_demands d on d.id = p.mps_demand_id join delivery_schedule_lines s on s.id = d.schedule_line_id
                  where p.planned_order_id = ? and d.kind = 'FIRM' and s.status in ('FIRM', 'PARTIALLY_FULFILLED')",
                [$o->mps_planned_order_id],
            );
            $year = (int) substr($today, 0, 4);
            $number = (int) $db->selectOne(
                "insert into document_counters (tenant_id, plant_id, doc_type, year, last_no) values ((select c.tenant from ctx_api.context() c), ?, 'WORK_ORDER', ?, 1)
                 on conflict (tenant_id, plant_id, doc_type, year) do update set last_no = document_counters.last_no + 1 returning last_no",
                [$r->plant_id, $year],
            )->last_no;
            $woNo = sprintf('WO-%s-%d-%04d', $plant->code, $year, $number);
            try {
                $wo = (string) $db->selectOne(
                    "insert into work_orders (tenant_id, plant_id, wo_no, item_id, uom_id, qty, due_date, reason_code, note, schedule_line_id, mrp_order_id)
                     values ((select c.tenant from ctx_api.context() c), ?, ?, ?, ?, ?, ?, 'PLANNED', ?, ?, ?) returning id",
                    [$r->plant_id, $woNo, $o->item_id, $o->base_uom_id, $o->qty, max((string) $o->due, $today), "From {$r->run_no}",
                        $line !== null && count($line) === 1 ? (string) $line[0]->schedule_line_id : null, $o->id],
                )->id;
            } catch (QueryException $e) {
                // another planner released it at the same moment (work_orders_mrp_order_uq)
                if ($e->getCode() === '23505') {
                    throw self::alreadyReleased();
                }
                throw $e;
            }
            $this->audit->record($db, 'work_order.created', 'work_order', $wo, null, $this->state($db, 'work_orders', $wo), ['woNo' => $woNo, 'plannedFrom' => $r->run_no], (string) $r->plant_id);
        }

        return $this->respondRun($db, $id);
    }

    private static function alreadyReleased(): DomainProblem
    {
        return new DomainProblem(409, 'INVALID_STATE_TRANSITION', 'Already released', 'A selected planned order already has a work order; refresh and choose again.');
    }

    private function respondRun(EoraPostgresConnection $db, string $id, int $status = 200): JsonResponse
    {
        $row = $this->rows->find($db, new MrpRunsQuery, $id) ?? throw new LogicException('Written row not visible.');
        $items = [];
        foreach ($db->select(
            'select mi.id, mi.item_id, i.code, i.name, i.item_type, u.code as uom, u.decimals, mi.kind, mi.level, trim_scale(mi.on_hand)::text as on_hand,
                    trim_scale(mi.committed)::text as committed, trim_scale(mi.wo_need)::text as wo_need, trim_scale(mi.safety_stock)::text as ss, mi.lead_time_days as lead, mi.warnings::text as warnings
               from mrp_items mi join items i on i.id = mi.item_id join uoms u on u.id = mi.uom_id where mi.mrp_run_id = ? order by mi.level, i.code',
            [$id],
        ) as $r) {
            $items[(string) $r->id] = ['id' => (string) $r->id, 'itemId' => (string) $r->item_id, 'itemCode' => (string) $r->code, 'itemName' => (string) $r->name,
                'itemType' => (string) $r->item_type, 'uomCode' => (string) $r->uom, 'uomDecimals' => (int) $r->decimals, 'kind' => (string) $r->kind, 'level' => (int) $r->level,
                'onHand' => (string) $r->on_hand, 'committed' => (string) $r->committed, 'plannedWorkOrderNeed' => (string) $r->wo_need, 'safetyStock' => $r->ss === null ? null : (string) $r->ss,
                'leadTimeDays' => $r->lead === null ? null : (int) $r->lead, 'warnings' => self::list((string) $r->warnings), 'requirements' => [], 'supplies' => [], 'orders' => []];
        }
        foreach ($db->select(
            'select q.id, q.mrp_item_id, q.due_date::text as due, trim_scale(q.qty)::text as qty, pi.code as parent_code, po.qty as parent_qty, po.id as parent_id
               from mrp_requirements q join mrp_orders po on po.id = q.parent_order_id join mrp_items pm on pm.id = po.mrp_item_id join items pi on pi.id = pm.item_id
              where q.mrp_run_id = ? order by q.due_date, pi.code',
            [$id],
        ) as $q) {
            $items[(string) $q->mrp_item_id]['requirements'][] = ['id' => (string) $q->id, 'dueDate' => (string) $q->due, 'qty' => (string) $q->qty,
                'parentOrderId' => (string) $q->parent_id, 'parentItemCode' => (string) $q->parent_code];
        }
        foreach ($db->select(
            'select s.mrp_item_id, s.kind, coalesce(l.inbound_no, w.wo_no) as ref, s.supply_date::text as d, trim_scale(s.qty)::text as qty
               from mrp_supplies s left join supplier_inbound_lines l on l.id = s.inbound_line_id left join work_orders w on w.id = s.work_order_id
              where s.mrp_run_id = ? order by s.supply_date, ref',
            [$id],
        ) as $s) {
            $items[(string) $s->mrp_item_id]['supplies'][] = ['kind' => (string) $s->kind, 'reference' => (string) $s->ref, 'date' => (string) $s->d, 'qty' => (string) $s->qty];
        }
        $pegs = [];
        foreach ($db->select('select order_id, requirement_id, trim_scale(qty)::text as qty from mrp_pegs where mrp_run_id = ? order by id', [$id]) as $p) {
            $pegs[(string) $p->order_id][] = ['requirementId' => $p->requirement_id === null ? null : (string) $p->requirement_id, 'qty' => (string) $p->qty];
        }
        foreach ($db->select(
            "select o.id, o.mrp_item_id, o.kind, o.source, o.week_start::text as week, trim_scale(o.qty)::text as qty, o.due_date::text as due, o.start_date::text as start,
                    o.warnings::text as warnings, b.version_no as bom_version, w.id as wo_id, w.wo_no, w.status as wo_status
               from mrp_orders o left join bom_versions b on b.id = o.bom_version_id
               -- its live work order, released from this run or (for an MPS order) from another run of the same MPS
               left join lateral (select w.id, w.wo_no, w.status from work_orders w join mrp_orders x on x.id = w.mrp_order_id
                                   where w.status <> 'CANCELLED' and (x.id = o.id or (o.mps_planned_order_id is not null and x.mps_planned_order_id = o.mps_planned_order_id))
                                   limit 1) w on true
              where o.mrp_run_id = ? order by o.due_date, o.id",
            [$id],
        ) as $o) {
            $items[(string) $o->mrp_item_id]['orders'][] = ['id' => (string) $o->id, 'kind' => (string) $o->kind, 'source' => (string) $o->source,
                'weekStart' => (string) $o->week, 'qty' => (string) $o->qty, 'dueDate' => (string) $o->due, 'startDate' => (string) $o->start,
                'bomVersionNo' => $o->bom_version === null ? null : (int) $o->bom_version, 'warnings' => self::list((string) $o->warnings), 'pegs' => $pegs[(string) $o->id] ?? [],
                'workOrder' => $o->wo_id === null ? null : ['id' => (string) $o->wo_id, 'code' => (string) $o->wo_no, 'status' => (string) $o->wo_status]];
        }
        $row['items'] = array_values($items);
        $r = $db->selectOne('select r.mps_version_id, m.status from mrp_runs r join mps_versions m on m.id = r.mps_version_id where r.id = ?', [$id]);
        $latest = $db->selectOne('select id from mrp_runs where mps_version_id = ? order by created_at desc, id desc limit 1', [$r->mps_version_id]);
        $row['releasable'] = $r->status === 'APPROVED' && (string) $latest->id === $id;

        return response()->json(['data' => $row], $status);
    }

    /** @return list<string> */
    private static function list(string $literal): array
    {
        $inner = trim($literal, '{}');

        return $inner === '' ? [] : explode(',', $inner);
    }
}
