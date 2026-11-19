<?php

declare(strict_types=1);

namespace App\Modules\Planning\Http;

use App\Modules\Inventory\Application\MaterialOwnership;
use App\Modules\Planning\Mps\MpsCapacity;
use App\Modules\Planning\Mps\MpsEngine;
use App\Modules\Planning\Mps\MpsInputs;
use App\Modules\Planning\Mps\MpsItemInput;
use App\Modules\Planning\Queries\MpsVersionsQuery;
use App\Platform\Access\Authorizer;
use App\Platform\Approvals\Approvals;
use App\Platform\Audit\AuditRecorder;
use App\Platform\Database\EoraPostgresConnection;
use App\Platform\Exceptions\DomainProblem;
use App\Platform\Http\Concurrency;
use App\Platform\Http\MasterWrites;
use App\Platform\Query\QueryRunner;
use Illuminate\Database\QueryException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use LogicException;
use stdClass;

/**
 * PPIC → Master Production Schedule (doc 34 §1.3, D-123, D-046, BR-PLN-04/05/07): an MPS per plant over 12 ISO weeks.
 * A planner calculates a draft (`planning.mps.create`; MpsInputs → MpsEngine → rows), may recalculate it and adjust a
 * planned order's quantity and due date with a reason, and submits it (`planning.mps.submit`). A CSA approves it or
 * returns it with a reason (`planning.mps.approve`), never the one who calculated, submitted or adjusted it (SoD-07);
 * approval is refused when what it was calculated from changed since (409 MPS_INPUTS_CHANGED, D-123 (2)) and supersedes
 * the plant's approved MPS. Only an approved MPS feeds planned-order release (step 5).
 */
final class MpsController
{
    use MasterWrites;

    private const string VIEW = 'planning.mps.view';

    private const string CREATE = 'planning.mps.create';

    private const string SUBMIT = 'planning.mps.submit';

    private const string APPROVE = 'planning.mps.approve';

    public const string QTY = '/^(?!0+(\.0+)?$)\d{1,14}(\.\d{1,6})?$/';

    public function __construct(
        private readonly Authorizer $authorizer,
        private readonly AuditRecorder $audit,
        private readonly QueryRunner $rows,
        private readonly MpsInputs $inputs,
        private readonly MpsCapacity $capacity,
    ) {}

    /** Calculate a new draft MPS `MPS-<plant>-<year>-NNNN` for the plant (201); one draft or submitted MPS per plant. */
    public function store(Request $request): JsonResponse
    {
        $data = $request->validate(['plantId' => ['required', 'uuid'], 'note' => ['nullable', 'string', 'max:500']]);
        $db = $this->db();
        $this->authorizer->requireAnywhere($db, self::CREATE);
        $plant = $this->existing($db, 'plants', $data['plantId']);
        $this->authorizer->authorize($db, self::CREATE, $this->plantScope($db, $plant));
        if ($db->selectOne("select 1 as ok from mps_versions where plant_id = ? and status in ('DRAFT', 'SUBMITTED')", [$plant]) !== null) {
            throw self::openExists();
        }
        MaterialOwnership::requireAggregatePlanning($db, $plant);
        $in = $this->inputs->load($db, $plant);
        $year = (int) substr($in->today, 0, 4);
        $number = (int) $db->selectOne(
            "insert into document_counters (tenant_id, plant_id, doc_type, year, last_no) values ((select c.tenant from ctx_api.context() c), ?, 'MPS', ?, 1)
             on conflict (tenant_id, plant_id, doc_type, year) do update set last_no = document_counters.last_no + 1 returning last_no",
            [$plant, $year],
        )->last_no;
        $code = (string) $db->selectOne('select code from plants where id = ?', [$plant])->code;
        try {
            $id = (string) $db->selectOne(
                'insert into mps_versions (tenant_id, plant_id, mps_no, horizon_start, horizon_weeks, plan_date, input_fingerprint, calculated_at, calculated_by, note)
                 values ((select c.tenant from ctx_api.context() c), ?, ?, ?, ?, ?, ?, now(), (select c.actor from ctx_api.context() c), ?) returning id',
                [$plant, sprintf('MPS-%s-%d-%04d', $code, $year, $number), $in->horizonStart, MpsInputs::WEEKS, $in->today, $in->fingerprint(), self::optionalText($data['note'] ?? null)],
            )->id;
        } catch (QueryException $e) {
            // another planner calculated one at the same moment (mps_versions_open_uq)
            if ($e->getCode() === '23505') {
                throw self::openExists();
            }
            throw $e;
        }
        $this->write($db, $id, $plant, $in);
        $this->audit->record($db, 'mps.calculated', 'mps_version', $id, null, $this->state($db, 'mps_versions', $id), [], $plant);

        return $this->respondVersion($db, $id, 201);
    }

    /** The MPS with its items, weeks, demand, supply, planned orders, pegs and capacity; `inputsChanged` while open. */
    public function show(string $version): JsonResponse
    {
        $db = $this->db();
        $this->authorizer->requireAnywhere($db, self::VIEW);
        $id = $this->existing($db, 'mps_versions', $version);
        $this->authorizer->authorize($db, self::VIEW, $this->plantScope($db, (string) $db->selectOne('select plant_id from mps_versions where id = ?', [$id])->plant_id));

        return $this->respondVersion($db, $id);
    }

    /** Recalculate a draft from today's data (If-Match); adjustments of its planned orders are replaced (D-123 (3)). */
    public function recalculate(Request $request, string $version): JsonResponse
    {
        $expected = Concurrency::expectedVersion($request);
        $db = $this->db();
        $m = $this->locked($db, $version, self::CREATE, ['DRAFT']);
        MaterialOwnership::requireAggregatePlanning($db, (string) $m->plant_id);
        $before = $this->state($db, 'mps_versions', (string) $m->id);
        $in = $this->inputs->load($db, (string) $m->plant_id);
        if ($db->update(
            'update mps_versions set horizon_start = ?, plan_date = ?, input_fingerprint = ?, calculated_at = now(), calculated_by = (select c.actor from ctx_api.context() c)
              where id = ? and version = ?',
            [$in->horizonStart, $in->today, $in->fingerprint(), $m->id, $expected],
        ) === 0) {
            throw Concurrency::conflict();
        }
        foreach (['mps_capacity', 'mps_pegs', 'mps_planned_orders', 'mps_buckets', 'mps_supplies', 'mps_demands', 'mps_items'] as $table) {
            $db->delete("delete from {$table} where mps_version_id = ?", [$m->id]);
        }
        $this->write($db, (string) $m->id, (string) $m->plant_id, $in);
        $this->audit->record($db, 'mps.recalculated', 'mps_version', (string) $m->id, $before, $this->state($db, 'mps_versions', (string) $m->id), [], (string) $m->plant_id);

        return $this->respondVersion($db, (string) $m->id);
    }

    /**
     * Adjust a planned order of a draft (If-Match on the MPS): quantity and due date (inside the horizon, from the plan
     * date) with a reason; the start date follows the item's lead time and capacity is reloaded. Marked adjusted for the
     * approver; a recalculation replaces it.
     */
    public function adjust(Request $request, string $version, string $order): JsonResponse
    {
        $data = $request->validate([
            'qty' => ['required', 'string', 'regex:'.self::QTY],
            'dueDate' => ['required', 'date_format:Y-m-d'],
            'reason' => ['required', 'string', 'min:3', 'max:500'],
        ]);
        $expected = Concurrency::expectedVersion($request);
        $db = $this->db();
        $m = $this->locked($db, $version, self::CREATE, ['DRAFT']);
        MaterialOwnership::requireAggregatePlanning($db, (string) $m->plant_id);
        $o = $db->selectOne(
            'select o.id, o.week_start::text as week_start, mi.production_lead_time_days as lead, u.code as uom, u.decimals
               from mps_planned_orders o join mps_items mi on mi.id = o.mps_item_id join uoms u on u.id = mi.uom_id
              where o.id = ? and o.mps_version_id = ? for update of o',
            [strtolower($order), $m->id],
        ) ?? throw ValidationException::withMessages(['order' => 'This planned order is not in this MPS.']);
        if (strlen(explode('.', $data['qty'].'.')[1]) > (int) $o->decimals) {
            throw ValidationException::withMessages(['qty' => "{$o->uom} takes ".((int) $o->decimals === 0 ? 'whole numbers' : "at most {$o->decimals} decimals").'.']);
        }
        if ($data['dueDate'] < $m->plan_date || $data['dueDate'] > $m->horizon_end) {
            throw ValidationException::withMessages(['dueDate' => "Choose a day from {$m->plan_date} to {$m->horizon_end}."]);
        }
        if ($db->update('update mps_versions set note = note where id = ? and version = ?', [$m->id, $expected]) === 0) {
            throw Concurrency::conflict();
        }
        $days = $this->inputs->load($db, (string) $m->plant_id)->days;
        $start = $days->back($data['dueDate'], $o->lead === null ? 0 : (int) $o->lead);
        $before = (array) $db->selectOne('select trim_scale(qty)::text as qty, due_date::text as due_date, start_date::text as start_date from mps_planned_orders where id = ?', [$o->id]);
        $orders = array_map(static fn (stdClass $r) => $r->id === $o->id
            ? ['itemId' => (string) $r->item_id, 'qty' => MpsItemInput::num($data['qty']), 'startDate' => $start]
            : ['itemId' => (string) $r->item_id, 'qty' => MpsItemInput::num($r->qty), 'startDate' => (string) $r->start_date], $all = $db->select(
                'select o.id, mi.item_id, trim_scale(o.qty)::text as qty, o.start_date::text as start_date
                   from mps_planned_orders o join mps_items mi on mi.id = o.mps_item_id where o.mps_version_id = ? order by o.id',
                [$m->id],
            ));
        $load = $this->capacity->compute($db, (string) $m->plant_id, (string) $m->plan_date, (string) $m->horizon_start, $days, array_values($orders));
        $noRouting = in_array(array_search($o->id, array_column($all, 'id'), true), $load['noRouting'], true);
        $db->update(
            'update mps_planned_orders set qty = ?, due_date = ?, start_date = ?, warnings = ?::text[], adjust_reason = ?, adjusted_at = now(), adjusted_by = (select c.actor from ctx_api.context() c)
              where id = ?',
            [$data['qty'], $data['dueDate'], $start, self::pgArray(self::orderWarnings($start, (string) $m->plan_date, $o->lead === null, $noRouting)), trim($data['reason']), $o->id],
        );
        $this->saveCapacity($db, (string) $m->id, $load['rows']);
        $this->audit->record($db, 'mps.planned_order_adjusted', 'mps_version', (string) $m->id, $before,
            ['planned_order_id' => (string) $o->id, 'qty' => $data['qty'], 'due_date' => $data['dueDate'], 'start_date' => $start, 'reason' => trim($data['reason'])], [], (string) $m->plant_id);

        return $this->respondVersion($db, (string) $m->id);
    }

    /** Submit a draft for approval (If-Match). */
    public function submit(Request $request, string $version): JsonResponse
    {
        $expected = Concurrency::expectedVersion($request);
        $db = $this->db();
        $m = $this->locked($db, $version, self::SUBMIT, ['DRAFT']);
        $this->transition($db, $m, $expected, "status = 'SUBMITTED', submitted_at = now(), submitted_by = (select c.actor from ctx_api.context() c), return_reason = null", [], 'mps.submitted');
        app(Approvals::class)->requested($db, 'mps_version', (string) $m->id); // D-129

        return $this->respondVersion($db, (string) $m->id);
    }

    /** Cancel a draft (If-Match); the plant may then calculate a new one. */
    public function cancel(Request $request, string $version): JsonResponse
    {
        $expected = Concurrency::expectedVersion($request);
        $db = $this->db();
        $m = $this->locked($db, $version, self::CREATE, ['DRAFT']);
        $this->transition($db, $m, $expected, "status = 'CANCELLED', cancelled_at = now(), cancelled_by = (select c.actor from ctx_api.context() c)", [], 'mps.cancelled');

        return $this->respondVersion($db, (string) $m->id);
    }

    /** Return a submitted MPS to its planner with a reason (If-Match). */
    public function returnToDraft(Request $request, string $version): JsonResponse
    {
        $data = $request->validate(['reason' => ['required', 'string', 'min:3', 'max:500']]);
        $expected = Concurrency::expectedVersion($request);
        $db = $this->db();
        $m = $this->locked($db, $version, self::APPROVE, ['SUBMITTED']);
        // told while the requester is still on the version (D-129)
        app(Approvals::class)->decided($db, 'mps_version', (string) $m->id, 'returned', trim($data['reason']));
        $this->transition($db, $m, $expected, "status = 'DRAFT', submitted_at = null, submitted_by = null, return_reason = ?", [trim($data['reason'])], 'mps.returned');

        return $this->respondVersion($db, (string) $m->id);
    }

    /**
     * Approve a submitted MPS (If-Match): not by whoever calculated, submitted or adjusted it (403 SOD_VIOLATION), and
     * only while its inputs are unchanged (409 MPS_INPUTS_CHANGED); the plant's approved MPS becomes superseded.
     */
    public function approve(Request $request, string $version): JsonResponse
    {
        $expected = Concurrency::expectedVersion($request);
        $db = $this->db();
        $m = $this->locked($db, $version, self::APPROVE, ['SUBMITTED']);
        $actor = (string) $db->selectOne('select c.actor from ctx_api.context() c')->actor;
        // anyone who ever calculated, submitted or adjusted it (mps_contributors), not only the latest names
        if (in_array($actor, [(string) $m->calculated_by, (string) $m->submitted_by], true)
            || $db->selectOne('select 1 as ok from mps_contributors where mps_version_id = ? and user_id = ?
                                union all select 1 from mps_planned_orders where mps_version_id = ? and adjusted_by = ? limit 1', [$m->id, $actor, $m->id, $actor]) !== null) {
            throw new DomainProblem(403, 'SOD_VIOLATION', 'Second person required', 'You prepared this MPS; another approver decides it.');
        }
        MaterialOwnership::requireAggregatePlanning($db, (string) $m->plant_id);
        if ($this->inputs->load($db, (string) $m->plant_id)->fingerprint() !== $m->input_fingerprint) {
            throw new DomainProblem(409, 'MPS_INPUTS_CHANGED', 'Data changed since the calculation',
                'Demand, stock, work orders or planning parameters changed after this MPS was calculated. Return it so the planner recalculates.');
        }
        $old = $db->selectOne("select id from mps_versions where plant_id = ? and status = 'APPROVED' for update", [$m->plant_id]);
        if ($old !== null) {
            $before = $this->state($db, 'mps_versions', (string) $old->id);
            $db->update("update mps_versions set status = 'SUPERSEDED', superseded_at = now() where id = ?", [$old->id]);
            $this->audit->record($db, 'mps.superseded', 'mps_version', (string) $old->id, $before, $this->state($db, 'mps_versions', (string) $old->id), [], (string) $m->plant_id);
        }
        $this->transition($db, $m, $expected, "status = 'APPROVED', approved_at = now(), approved_by = (select c.actor from ctx_api.context() c)", [], 'mps.approved');
        app(Approvals::class)->decided($db, 'mps_version', (string) $m->id, 'approved'); // D-129

        return $this->respondVersion($db, (string) $m->id);
    }

    /** Writes the calculation of each item, its pegs and the capacity load. */
    private function write(EoraPostgresConnection $db, string $id, string $plant, MpsInputs $in): void
    {
        $engine = new MpsEngine($in->days);
        $plans = [];
        $orders = [];
        foreach ($in->items as $key => $item) {
            $plans[$key] = $engine->plan($item->forEngine(), $in->horizonStart, MpsInputs::WEEKS, $in->today);
            foreach ($plans[$key]['plannedOrders'] as $n => $o) {
                $orders["{$key}|{$n}"] = ['itemId' => $item->itemId, 'qty' => $o['qty'], 'startDate' => $o['startDate']];
            }
        }
        $load = $this->capacity->compute($db, $plant, $in->today, $in->horizonStart, $in->days, array_values($orders));
        $noRouting = array_flip(array_map(static fn (int $n) => array_keys($orders)[$n], $load['noRouting']));
        foreach ($in->items as $key => $item) {
            $plan = $plans[$key];
            $mi = (string) $db->selectOne(
                'insert into mps_items (tenant_id, mps_version_id, item_id, uom_id, on_hand, out_of_scope_allocated, safety_stock, pending_qc, production_lead_time_days)
                 values ((select c.tenant from ctx_api.context() c), ?, ?, ?, ?, ?, ?, ?, ?) returning id',
                [$id, $item->itemId, $item->uomId, $item->onHand, $item->outOfScope, $item->safetyStock, $item->pendingQc, $item->leadTimeDays],
            )->id;
            $demandIds = [];
            foreach ($plan['demands'] as $d) {
                $demandIds[$d['key']] = (string) $db->selectOne(
                    'insert into mps_demands (tenant_id, mps_version_id, mps_item_id, kind, schedule_line_id, forecast_line_id, due_date, original_date, qty)
                     values ((select c.tenant from ctx_api.context() c), ?, ?, ?, ?, ?, ?, ?, ?) returning id',
                    [$id, $mi, $d['kind'], $d['kind'] === 'FIRM' ? $d['key'] : null, $d['kind'] === 'FORECAST' ? $d['key'] : null, $d['date'], $d['originalDate'], $d['qty']],
                )->id;
            }
            foreach ($plan['supplies'] as $s) {
                $db->insert(
                    'insert into mps_supplies (tenant_id, mps_version_id, mps_item_id, kind, work_order_id, supply_date, qty) values ((select c.tenant from ctx_api.context() c), ?, ?, ?, ?, ?, ?)',
                    [$id, $mi, $s['kind'], $s['kind'] === 'WORK_ORDER' ? $s['key'] : null, $s['date'], $s['qty']],
                );
            }
            foreach ($plan['buckets'] as $b) {
                $db->insert(
                    'insert into mps_buckets (tenant_id, mps_version_id, mps_item_id, week_start, opening, firm_demand, forecast_demand, receipts, planned, closing)
                     values ((select c.tenant from ctx_api.context() c), ?, ?, ?, ?, ?, ?, ?, ?, ?)',
                    [$id, $mi, $b['weekStart'], $b['opening'], $b['firm'], $b['forecast'], $b['receipts'], $b['planned'], $b['closing']],
                );
            }
            foreach ($plan['plannedOrders'] as $n => $o) {
                $po = (string) $db->selectOne(
                    'insert into mps_planned_orders (tenant_id, mps_version_id, mps_item_id, week_start, calc_qty, calc_due_date, qty, due_date, start_date, warnings)
                     values ((select c.tenant from ctx_api.context() c), ?, ?, ?, ?, ?, ?, ?, ?, ?::text[]) returning id',
                    [$id, $mi, $o['weekStart'], $o['qty'], $o['dueDate'], $o['qty'], $o['dueDate'], $o['startDate'],
                        self::pgArray(self::orderWarnings($o['startDate'], $in->today, $item->leadTimeDays === null, isset($noRouting["{$key}|{$n}"])))],
                )->id;
                foreach ($o['pegs'] as $p) {
                    $db->insert(
                        'insert into mps_pegs (tenant_id, mps_version_id, planned_order_id, mps_demand_id, qty) values ((select c.tenant from ctx_api.context() c), ?, ?, ?, ?)',
                        [$id, $po, $p['demand'] === null ? null : $demandIds[$p['demand']], $p['qty']],
                    );
                }
            }
        }
        $this->saveCapacity($db, $id, $load['rows']);
    }

    /**
     * Replaces the capacity rows of the MPS.
     *
     * @param  list<array{workCenterId: string, weekStart: string, load: string, available: ?string, status: string, warnings: list<string>}>  $rows
     */
    private function saveCapacity(EoraPostgresConnection $db, string $id, array $rows): void
    {
        $db->delete('delete from mps_capacity where mps_version_id = ?', [$id]);
        foreach ($rows as $r) {
            $db->insert(
                'insert into mps_capacity (tenant_id, mps_version_id, work_center_id, week_start, load_hours, available_hours, status, warnings)
                 values ((select c.tenant from ctx_api.context() c), ?, ?, ?, ?, ?, ?, ?::text[])',
                [$id, $r['workCenterId'], $r['weekStart'], $r['load'], $r['available'], $r['status'], self::pgArray($r['warnings'])],
            );
        }
    }

    /** @return list<string> */
    private static function orderWarnings(string $start, string $today, bool $noLeadTime, bool $noRouting): array
    {
        return array_values(array_filter([$start < $today ? 'LATE_START' : null, $noLeadTime ? 'LEAD_TIME_NOT_CONFIGURED' : null, $noRouting ? 'NO_ROUTING' : null]));
    }

    /** @param  list<string>  $values  plain upper-case codes */
    private static function pgArray(array $values): string
    {
        return '{'.implode(',', $values).'}';
    }

    /**
     * The locked MPS when the actor holds $permission in its plant (404 otherwise) and it is in one of $statuses (409).
     *
     * @param  list<string>  $statuses
     */
    private function locked(EoraPostgresConnection $db, string $version, string $permission, array $statuses): stdClass
    {
        $this->authorizer->requireAnywhere($db, $permission);
        $id = $this->existing($db, 'mps_versions', $version);
        $m = $db->selectOne(
            'select m.*, m.plan_date::text as plan_date, m.horizon_start::text as horizon_start, (m.horizon_start + 7 * m.horizon_weeks - 1)::text as horizon_end
               from mps_versions m where m.id = ? for update',
            [$id],
        );
        $this->authorizer->authorize($db, $permission, $this->plantScope($db, (string) $m->plant_id));
        if (! in_array($m->status, $statuses, true)) {
            throw new DomainProblem(409, 'INVALID_STATE_TRANSITION', 'MPS status does not allow this', "{$m->mps_no} is ".strtolower((string) $m->status).'.');
        }

        return $m;
    }

    /** @param  list<mixed>  $bindings */
    private function transition(EoraPostgresConnection $db, stdClass $m, int $expected, string $set, array $bindings, string $action): void
    {
        $before = $this->state($db, 'mps_versions', (string) $m->id);
        if ($db->update("update mps_versions set {$set} where id = ? and version = ?", [...$bindings, $m->id, $expected]) === 0) {
            throw Concurrency::conflict();
        }
        $this->audit->record($db, $action, 'mps_version', (string) $m->id, $before, $this->state($db, 'mps_versions', (string) $m->id), [], (string) $m->plant_id);
    }

    private static function openExists(): DomainProblem
    {
        return new DomainProblem(409, 'INVALID_STATE_TRANSITION', 'Open MPS exists', 'This plant has a draft or submitted MPS; finish or cancel it first.');
    }

    private function respondVersion(EoraPostgresConnection $db, string $id, int $status = 200): JsonResponse
    {
        $row = $this->rows->find($db, new MpsVersionsQuery, $id) ?? throw new LogicException('Written row not visible.');
        $m = $db->selectOne('select plant_id, status, input_fingerprint from mps_versions where id = ?', [$id]);
        $row['inputsChanged'] = in_array($m->status, ['DRAFT', 'SUBMITTED'], true)
            ? $this->inputs->load($db, (string) $m->plant_id)->fingerprint() !== $m->input_fingerprint
            : null;
        $row['items'] = $this->items($db, $id);
        $row['capacity'] = array_map(static fn (stdClass $c) => [
            'workCenterId' => (string) $c->work_center_id, 'workCenterCode' => (string) $c->code, 'workCenterName' => (string) $c->name,
            'weekStart' => (string) $c->week_start, 'loadHours' => (string) $c->load_hours,
            'availableHours' => $c->available_hours === null ? null : (string) $c->available_hours,
            'loadPercent' => $c->pct === null ? null : (int) $c->pct, 'status' => (string) $c->status, 'warnings' => self::list((string) $c->warnings),
        ], $db->select(
            'select c.work_center_id, w.code, w.name, c.week_start::text as week_start, trim_scale(round(c.load_hours, 2))::text as load_hours,
                    trim_scale(round(c.available_hours, 2))::text as available_hours,
                    case when c.available_hours > 0 then round(100 * c.load_hours / c.available_hours) when c.available_hours = 0 then null end as pct,
                    c.status, c.warnings::text as warnings
               from mps_capacity c join work_centers w on w.id = c.work_center_id where c.mps_version_id = ? order by w.code, c.week_start',
            [$id],
        ));
        $mrp = $db->selectOne('select id, run_no from mrp_runs where mps_version_id = ? order by created_at desc, id desc limit 1', [$id]);
        $row['pairedBlanking'] = $mrp === null ? null : ['runCode' => (string) $mrp->run_no,
            'workloads' => array_values(MpsCapacity::pairedWorkloads($db, (string) $mrp->id))];

        return Concurrency::withVersion(response()->json(['data' => $row], $status), (int) $row['version']);
    }

    /** @return list<array<string, mixed>> */
    private function items(EoraPostgresConnection $db, string $id): array
    {
        $byItem = [];
        foreach ($db->select(
            'select mi.id, mi.item_id, i.code, i.name, u.code as uom, u.decimals, trim_scale(mi.on_hand)::text as on_hand,
                    trim_scale(mi.out_of_scope_allocated)::text as out_of_scope, trim_scale(mi.safety_stock)::text as safety_stock,
                    trim_scale(mi.pending_qc)::text as pending_qc, mi.production_lead_time_days as lead
               from mps_items mi join items i on i.id = mi.item_id join uoms u on u.id = mi.uom_id where mi.mps_version_id = ? order by i.code',
            [$id],
        ) as $r) {
            $byItem[(string) $r->id] = [
                'id' => (string) $r->id, 'itemId' => (string) $r->item_id, 'itemCode' => (string) $r->code, 'itemName' => (string) $r->name,
                'uomCode' => (string) $r->uom, 'uomDecimals' => (int) $r->decimals, 'onHand' => (string) $r->on_hand, 'outOfScopeAllocated' => (string) $r->out_of_scope,
                'safetyStock' => $r->safety_stock === null ? null : (string) $r->safety_stock, 'pendingQc' => (string) $r->pending_qc,
                'leadTimeDays' => $r->lead === null ? null : (int) $r->lead, 'buckets' => [], 'demands' => [], 'supplies' => [], 'plannedOrders' => [],
            ];
        }
        $pab = [];
        foreach ($db->select(
            'select b.mps_item_id, b.week_start::text as week_start, trim_scale(b.opening)::text as opening, trim_scale(b.firm_demand)::text as firm,
                    trim_scale(b.forecast_demand)::text as forecast, trim_scale(b.receipts)::text as receipts, trim_scale(b.planned)::text as planned,
                    trim_scale(b.closing)::text as closing,
                    trim_scale(coalesce((select sum(o.qty) from mps_planned_orders o
                                          where o.mps_item_id = b.mps_item_id and o.due_date between b.week_start and b.week_start + 6), 0))::text as plan_qty
               from mps_buckets b where b.mps_version_id = ? order by b.week_start',
            [$id],
        ) as $b) {
            // the balances shown follow the plan as it stands: a planned order counts in the week of its (adjusted) due date
            // with its (adjusted) quantity; a negative closing is a shortage the adjustment left (the week's net requirement stays)
            $key = (string) $b->mps_item_id;
            $opening = $pab[$key] ?? MpsItemInput::num($b->opening);
            $pab[$key] = bcsub(bcadd(bcadd($opening, MpsItemInput::num($b->receipts), 6), MpsItemInput::num($b->plan_qty), 6),
                bcadd(MpsItemInput::num($b->firm), MpsItemInput::num($b->forecast), 6), 6);
            $byItem[$key]['buckets'][] = ['weekStart' => (string) $b->week_start, 'opening' => self::plain($opening), 'firm' => (string) $b->firm,
                'forecast' => (string) $b->forecast, 'receipts' => (string) $b->receipts, 'netRequirement' => (string) $b->planned, 'plannedQty' => (string) $b->plan_qty,
                'closing' => self::plain($pab[$key])];
        }
        foreach ($db->select(
            "select d.id, d.mps_item_id, d.kind, d.due_date::text as due, d.original_date::text as original, trim_scale(d.qty)::text as qty,
                    coalesce(h.so_no || ' / ' || ol.line_no || '.' || s.schedule_no, f.forecast_no) as ref, coalesce(ch.code, cf.code) as customer
               from mps_demands d
               left join delivery_schedule_lines s on s.id = d.schedule_line_id
               left join sales_order_lines ol on ol.id = s.so_line_id
               left join sales_order_headers h on h.id = ol.sales_order_id
               left join customers ch on ch.id = h.customer_id
               left join forecast_lines fl on fl.id = d.forecast_line_id
               left join forecast_versions f on f.id = fl.forecast_version_id
               left join customers cf on cf.id = f.customer_id
              where d.mps_version_id = ? order by d.due_date, d.kind, ref",
            [$id],
        ) as $d) {
            $byItem[(string) $d->mps_item_id]['demands'][] = ['id' => (string) $d->id, 'kind' => (string) $d->kind, 'reference' => (string) $d->ref,
                'customerCode' => (string) $d->customer, 'dueDate' => (string) $d->due, 'originalDate' => (string) $d->original, 'qty' => (string) $d->qty];
        }
        foreach ($db->select(
            'select s.mps_item_id, s.kind, w.wo_no, s.supply_date::text as date, trim_scale(s.qty)::text as qty
               from mps_supplies s left join work_orders w on w.id = s.work_order_id where s.mps_version_id = ? order by s.supply_date, w.wo_no',
            [$id],
        ) as $s) {
            $byItem[(string) $s->mps_item_id]['supplies'][] = ['kind' => (string) $s->kind, 'reference' => $s->wo_no === null ? null : (string) $s->wo_no,
                'date' => (string) $s->date, 'qty' => (string) $s->qty];
        }
        $pegs = [];
        foreach ($db->select('select planned_order_id, mps_demand_id, trim_scale(qty)::text as qty from mps_pegs where mps_version_id = ? order by id', [$id]) as $p) {
            $pegs[(string) $p->planned_order_id][] = ['demandId' => $p->mps_demand_id === null ? null : (string) $p->mps_demand_id, 'qty' => (string) $p->qty];
        }
        foreach ($db->select(
            'select o.id, o.mps_item_id, o.week_start::text as week_start, trim_scale(o.calc_qty)::text as calc_qty, o.calc_due_date::text as calc_due,
                    trim_scale(o.qty)::text as qty, o.due_date::text as due, o.start_date::text as start, o.warnings::text as warnings,
                    o.adjust_reason, o.adjusted_at, ua.display_name as adjusted_by
               from mps_planned_orders o left join users ua on ua.id = o.adjusted_by where o.mps_version_id = ? order by o.week_start',
            [$id],
        ) as $o) {
            $byItem[(string) $o->mps_item_id]['plannedOrders'][] = ['id' => (string) $o->id, 'weekStart' => (string) $o->week_start,
                'calcQty' => (string) $o->calc_qty, 'calcDueDate' => (string) $o->calc_due, 'qty' => (string) $o->qty, 'dueDate' => (string) $o->due,
                'startDate' => (string) $o->start, 'warnings' => self::list((string) $o->warnings), 'adjusted' => $o->adjusted_at !== null,
                'adjustReason' => $o->adjust_reason, 'adjustedByName' => $o->adjusted_by, 'pegs' => $pegs[(string) $o->id] ?? []];
        }

        return array_values($byItem);
    }

    private static function plain(string $number): string
    {
        $n = str_contains($number, '.') ? rtrim(rtrim($number, '0'), '.') : $number;

        return $n === '-0' ? '0' : $n;
    }

    /** @return list<string> a text[] literal of plain codes */
    private static function list(string $literal): array
    {
        $inner = trim($literal, '{}');

        return $inner === '' ? [] : explode(',', $inner);
    }
}
