<?php

declare(strict_types=1);

namespace App\Modules\Planning\Http;

use App\Modules\Planning\Recovery\DailyRecoveryPlanner;
use App\Platform\Access\Authorizer;
use App\Platform\Audit\AuditRecorder;
use App\Platform\Database\EoraPostgresConnection;
use App\Platform\Exceptions\DomainProblem;
use App\Platform\Http\Concurrency;
use App\Platform\Http\MasterWrites;
use App\Platform\Query\QueryRunner;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

final class DailyRecoveryController
{
    use MasterWrites;

    public function __construct(private readonly Authorizer $authorizer, private readonly AuditRecorder $audit,
        private readonly QueryRunner $rows, private readonly DailyRecoveryPlanner $planner, private readonly WorkOrderController $workOrders) {}

    /** Live Daily Recovery. Shared supply is netted before filters and paging. */
    public function index(Request $request): JsonResponse
    {
        $data = $request->validate(['plantId' => ['required', 'uuid'], 'targetDate' => ['required', 'date_format:Y-m-d'],
            'customerId' => ['nullable', 'uuid'], 'q' => ['nullable', 'string', 'max:100'],
            'page' => ['nullable', 'integer', 'min:1', 'max:100000'], 'perPage' => ['nullable', 'integer', 'min:1', 'max:100']]);
        $db = $this->db();
        $plant = $this->plant($db, $data['plantId'], 'planning.recovery.view');
        $result = $this->planner->read($db, $plant, $data['targetDate']);
        if ($data['targetDate'] < $result['today']) {
            throw ValidationException::withMessages(['targetDate' => 'Choose today or a future production target.']);
        }
        $rows = array_values(array_filter($result['rows'], static function (array $row) use ($data): bool {
            $matchesCustomer = ! isset($data['customerId']) || $row['customerId'] === strtolower($data['customerId']);
            $search = strtolower(trim($data['q'] ?? ''));

            return $matchesCustomer && ($search === '' || str_contains(strtolower($row['customerCode'].' '.$row['itemName'].' '.$row['partNo'].' '.$row['itemCode']), $search));
        }));
        $page = (int) ($data['page'] ?? 1);
        $size = (int) ($data['perPage'] ?? 25);

        return response()->json(['data' => array_slice($rows, ($page - 1) * $size, $size),
            'meta' => ['total' => count($rows), 'page' => $page, 'perPage' => $size, ...array_diff_key($result, ['rows' => true])]]);
    }

    /** Save batch, buffer and editable shifts with If-Match (0 creates the first row). */
    public function save(Request $request): JsonResponse
    {
        $data = $request->validate(['plantId' => ['required', 'uuid'], 'targetDate' => ['required', 'date_format:Y-m-d'],
            'planId' => ['nullable', 'uuid'], 'customerId' => ['required', 'uuid'], 'itemId' => ['required', 'uuid'],
            'minimumQty' => ['required', 'integer', 'min:0', 'max:1000000000'],
            'batchQty' => ['nullable', 'integer', 'min:1', 'max:1000000000'],
            'bufferQty' => ['required', 'integer', 'min:0', 'max:1000000000'],
            'shift1Qty' => ['required', 'integer', 'min:0', 'max:1000000000'],
            'shift2Qty' => ['required', 'integer', 'min:0', 'max:1000000000'],
            'shift3Qty' => ['required', 'integer', 'min:0', 'max:1000000000'],
            'reason' => ['required', 'string', 'min:3', 'max:500']]);
        $expected = Concurrency::expectedVersion($request);
        $db = $this->db();
        $plant = $this->plant($db, $data['plantId'], 'planning.recovery.create');
        $this->lockPlant($db, $plant);
        $result = $this->planner->read($db, $plant, $data['targetDate']);
        if ($data['targetDate'] < $result['today']) {
            throw ValidationException::withMessages(['targetDate' => 'Choose today or a future production target.']);
        }
        $row = $this->part($result, $data['customerId'], $data['itemId']);
        if ((int) $row['version'] !== $expected || $row['planId'] !== ($data['planId'] ?? null)) {
            throw Concurrency::conflict();
        }
        if (mb_strlen(trim($data['reason'])) < 3) {
            throw ValidationException::withMessages(['reason' => 'Explain this plan in at least three characters.']);
        }
        $changes = ['minimum_qty' => $data['minimumQty'], 'batch_qty' => $data['batchQty'] ?? null, 'buffer_qty' => $data['bufferQty'],
            'shift_1_qty' => $data['shift1Qty'], 'shift_2_qty' => $data['shift2Qty'], 'shift_3_qty' => $data['shift3Qty'], 'reason' => trim($data['reason'])];
        $create = $row['planId'] === null || $row['workOrderId'] !== null;
        $before = $create ? null : $this->state($db, 'daily_recovery_plans', $row['planId']);
        if ($create) {
            $columns = implode(',', array_keys($changes));
            $bindings = [$plant, strtolower($data['customerId']), strtolower($data['itemId']), $data['targetDate'], ((int) $row['revision']) + 1, ...array_values($changes)];
            $placeholders = implode(',', array_fill(0, count($bindings), '?'));
            $id = (string) $db->selectOne("insert into daily_recovery_plans(tenant_id,plant_id,customer_id,item_id,target_date,revision,{$columns})
                values((select c.tenant from ctx_api.context() c),{$placeholders}) returning id", $bindings)->id;
        } else {
            $id = $row['planId'];
            $set = implode(',', array_map(static fn (string $key): string => $key.' = ?', array_keys($changes)));
            if ($db->update("update daily_recovery_plans set {$set} where id = ? and version = ?", [...array_values($changes), $id, $expected]) !== 1) {
                throw Concurrency::conflict();
            }
        }
        $result = $this->planner->read($db, $plant, $data['targetDate']);
        $row = $this->part($result, $data['customerId'], $data['itemId']);
        $snapshot = array_diff_key($row, array_flip(['lots', 'production', 'demands', 'workOrders']));
        $db->update('update daily_recovery_plans set input_fingerprint = ?, input_snapshot = ?::jsonb where id = ?',
            [$result['fingerprint'], json_encode($snapshot, JSON_THROW_ON_ERROR), $id]);
        $state = $this->state($db, 'daily_recovery_plans', $id);
        $this->audit->record($db, 'daily_recovery.saved', 'daily_recovery_plan', $id, $before, $state, [], $plant);
        $row['version'] = $state['version'];
        $row['inputsChanged'] = false;

        return Concurrency::withVersion(response()->json(['data' => $row, 'fingerprint' => $result['fingerprint']]), (int) $state['version']);
    }

    /** Create one normal draft WO from the saved shifts. Repeated calls return the same linked WO. */
    public function createWorkOrder(Request $request, string $plan): JsonResponse
    {
        $expected = Concurrency::expectedVersion($request);
        $db = $this->db();
        $this->authorizer->requireAnywhere($db, 'planning.recovery.create');
        $id = $this->existing($db, 'daily_recovery_plans', $plan);
        $initial = $db->selectOne('select plant_id from daily_recovery_plans where id = ?', [$id]);
        $plant = $this->plant($db, (string) $initial->plant_id, 'planning.recovery.create');
        $this->authorizer->authorize($db, 'planning.wo.create', $this->plantScope($db, $plant));
        $this->lockPlant($db, $plant);
        $p = $db->selectOne('select * from daily_recovery_plans where id = ? for update', [$id]);
        if ($p->work_order_id !== null) {
            return response()->json(['data' => ['workOrderId' => $p->work_order_id, 'replayed' => true]]);
        }
        if ((int) $p->version !== $expected) {
            throw Concurrency::conflict();
        }
        $live = $this->planner->read($db, $plant, (string) $p->target_date);
        if ($p->target_date < $live['today']) {
            throw new DomainProblem(409, 'INVALID_STATE_TRANSITION', 'Target date has passed', 'Review a plan for today or a future target.');
        }
        if ($live['fingerprint'] !== $p->input_fingerprint) {
            throw new DomainProblem(409, 'RECOVERY_INPUTS_CHANGED', 'Planning inputs changed', 'Stock, QC, demand or another plan changed. Refresh and save this plan after reviewing the new quantities.');
        }
        $row = $this->part($live, (string) $p->customer_id, (string) $p->item_id);
        if (bccomp($row['planQty'], '0', 0) <= 0) {
            throw ValidationException::withMessages(['shift1Qty' => 'Enter the total quantity to produce in Shift I–III.']);
        }
        $schedule = count($row['demands']) === 1 ? $row['demands'][0]->id : null;
        $body = ['plantId' => $plant, 'itemId' => $p->item_id, 'qty' => $row['planQty'], 'dueDate' => $p->target_date,
            'reasonCode' => 'OTHER', 'note' => 'Daily Recovery '.$p->target_date.' / '.$row['customerCode'].' — '.mb_substr((string) $p->reason, 0, 350),
            'scheduleLineId' => $schedule, 'productionCustomerId' => (string) $p->customer_id];
        $wo = $this->workOrders->createDraft($body)->getData(true)['data'];
        $before = (array) $p;
        $db->update('update daily_recovery_plans set work_order_id = ? where id = ?', [$wo['id'], $id]);
        $this->audit->record($db, 'daily_recovery.work_order_created', 'daily_recovery_plan', $id, $before,
            $this->state($db, 'daily_recovery_plans', $id), ['workOrderId' => $wo['id']], $plant);

        return response()->json(['data' => ['workOrderId' => $wo['id'], 'workOrderCode' => $wo['code'], 'replayed' => false]], 201);
    }

    private function plant(EoraPostgresConnection $db, string $id, string $permission): string
    {
        $this->authorizer->requireAnywhere($db, $permission);
        $plant = $this->existing($db, 'plants', $id);
        $this->authorizer->authorize($db, $permission, $this->plantScope($db, $plant));

        return $plant;
    }

    private function lockPlant(EoraPostgresConnection $db, string $plant): void
    {
        $db->select('select pg_advisory_xact_lock(hashtextextended(?,0))', ['daily-recovery:'.$plant]);
    }

    /**
     * @param  array<string,mixed>  $result
     * @return array<string,mixed>
     */
    private function part(array $result, string $customer, string $item): array
    {
        foreach ($result['rows'] as $row) {
            if ($row['customerId'] === strtolower($customer) && $row['itemId'] === strtolower($item)) {
                return $row;
            }
        }

        throw new NotFoundHttpException;
    }
}
