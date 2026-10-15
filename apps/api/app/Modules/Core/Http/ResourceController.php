<?php

declare(strict_types=1);

namespace App\Modules\Core\Http;

use App\Modules\Core\Queries\DiesQuery;
use App\Modules\Core\Queries\MachinesQuery;
use App\Modules\Core\Queries\WorkCentersQuery;
use App\Platform\Access\Authorizer;
use App\Platform\Audit\AuditRecorder;
use App\Platform\Database\EoraPostgresConnection;
use App\Platform\Http\Concurrency;
use App\Platform\Http\MasterWrites;
use App\Platform\Query\QueryRunner;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use stdClass;

/**
 * Master Data → production resources (doc 29 §1.1, doc 26 §2.1): work centers (each with its Material at Production
 * location), machines and dies. Same rules as the organisation master: `master.org.create` in a scope covering the
 * plant, immutable codes, If-Match edits, audit. Lists are `core.work_centers`, `core.machines`, `core.dies`.
 */
final class ResourceController
{
    use MasterWrites;

    private const string CREATE = 'master.org.create';

    public const string WC_CODE = 'regex:/^[A-Z0-9][A-Z0-9_-]{0,15}$/';

    public const string TYPE_CODE = 'regex:/^[A-Z][A-Z0-9_]{1,31}$/';

    public function __construct(
        private readonly Authorizer $authorizer,
        private readonly AuditRecorder $audit,
        private readonly QueryRunner $rows,
    ) {}

    /** Create a work center in a plant; its `MAP-<code>` Material at Production location is created with it. */
    public function storeWorkCenter(Request $request): JsonResponse
    {
        $data = $request->validate([
            'plantId' => ['required', 'uuid'],
            'code' => ['required', 'string', self::WC_CODE],
            'name' => ['required', 'string', 'min:1', 'max:200'],
            'workCenterType' => ['required', 'string', self::TYPE_CODE],
            'availableHoursPerShift' => ['nullable', 'numeric', 'gt:0', 'lte:24', 'decimal:0,2'],
        ], ['code.regex' => 'Use 1–16 characters A-Z, 0-9, "_" or "-".']);
        $db = $this->db();
        $this->authorizer->requireAnywhere($db, self::CREATE);
        $plant = $this->knownPlant($db, $data['plantId']);
        $this->authorizer->authorize($db, self::CREATE, $this->plantScope($db, $plant));

        $id = $this->insert($db,
            'insert into work_centers (tenant_id, plant_id, code, name, work_center_type, available_hours_per_shift)
             values ((select c.tenant from ctx_api.context() c), ?, ?, ?, ?, ?) returning id',
            [$plant, $data['code'], $data['name'], $data['workCenterType'], $data['availableHoursPerShift'] ?? null],
            'A work center with this code already exists in this plant.');
        $this->audit->record($db, 'work_center.created', 'work_center', $id, null, $this->state($db, 'work_centers', $id), ['code' => $data['code']], $plant);

        return $this->respond($db, new WorkCentersQuery, $id, 201);
    }

    /** Change a work center's name, type, capacity or status (If-Match). `availableHoursPerShift: null` clears it. */
    public function updateWorkCenter(Request $request, string $workCenter): JsonResponse
    {
        $data = $request->validate([
            'name' => ['sometimes', 'string', 'min:1', 'max:200'],
            'workCenterType' => ['sometimes', 'string', self::TYPE_CODE],
            'availableHoursPerShift' => ['sometimes', 'nullable', 'numeric', 'gt:0', 'lte:24', 'decimal:0,2'],
            'status' => ['sometimes', Rule::in(['ACTIVE', 'INACTIVE'])],
        ]);
        $version = Concurrency::expectedVersion($request);
        $db = $this->db();
        $this->authorizer->requireAnywhere($db, self::CREATE);
        $id = $this->existing($db, 'work_centers', $workCenter);
        $plant = (string) $db->selectOne('select plant_id from work_centers where id = ?', [$id])->plant_id;
        $this->authorizer->authorize($db, self::CREATE, $this->plantScope($db, $plant));

        $changes = [];
        foreach (['name' => 'name', 'workCenterType' => 'work_center_type', 'status' => 'status'] as $key => $column) {
            if (isset($data[$key])) {
                $changes[$column] = $data[$key];
            }
        }
        if (array_key_exists('availableHoursPerShift', $data)) {
            $changes['available_hours_per_shift'] = $data['availableHoursPerShift'] === null ? null : (string) $data['availableHoursPerShift'];
        }
        $this->update($db, 'work_centers', 'work_center', $id, $version, $changes, 'work_center.updated', $plant);

        return $this->respond($db, new WorkCentersQuery, $id);
    }

    /** Create a machine in an active work center (the machine's plant is the work center's). */
    public function storeMachine(Request $request): JsonResponse
    {
        $data = $request->validate([
            'workCenterId' => ['required', 'uuid'],
            'code' => ['required', 'string', OrgController::CODE],
            'name' => ['required', 'string', 'min:1', 'max:200'],
            'strokeCounter' => ['sometimes', 'boolean'],
        ]);
        $db = $this->db();
        $this->authorizer->requireAnywhere($db, self::CREATE);
        $wc = $this->activeWorkCenter($db, $data['workCenterId']);
        $this->authorizer->authorize($db, self::CREATE, $this->plantScope($db, (string) $wc->plant_id));

        $id = $this->insert($db,
            'insert into machines (tenant_id, plant_id, work_center_id, code, name, stroke_counter)
             values ((select c.tenant from ctx_api.context() c), ?, ?, ?, ?, ?) returning id',
            [$wc->plant_id, $wc->id, $data['code'], $data['name'], (bool) ($data['strokeCounter'] ?? false)],
            'A machine with this code already exists in this plant.');
        $this->audit->record($db, 'machine.created', 'machine', $id, null, $this->state($db, 'machines', $id), ['code' => $data['code'], 'workCenterId' => (string) $wc->id], (string) $wc->plant_id);

        return $this->respond($db, new MachinesQuery, $id, 201);
    }

    /** Change a machine's name, stroke-counter flag, status, or move it to another active work center of its plant. */
    public function updateMachine(Request $request, string $machine): JsonResponse
    {
        $data = $request->validate([
            'name' => ['sometimes', 'string', 'min:1', 'max:200'],
            'workCenterId' => ['sometimes', 'uuid'],
            'strokeCounter' => ['sometimes', 'boolean'],
            'status' => ['sometimes', Rule::in(['ACTIVE', 'INACTIVE'])],
        ]);
        $version = Concurrency::expectedVersion($request);
        $db = $this->db();
        $this->authorizer->requireAnywhere($db, self::CREATE);
        $id = $this->existing($db, 'machines', $machine);
        $plant = (string) $db->selectOne('select plant_id from machines where id = ?', [$id])->plant_id;
        $this->authorizer->authorize($db, self::CREATE, $this->plantScope($db, $plant));

        $changes = [];
        if (isset($data['workCenterId'])) {
            $wc = $this->activeWorkCenter($db, $data['workCenterId']);
            if ((string) $wc->plant_id !== $plant) {
                throw ValidationException::withMessages(['workCenterId' => 'A machine moves only between work centers of its own plant.']);
            }
            $changes['work_center_id'] = (string) $wc->id;
        }
        if (isset($data['name'])) {
            $changes['name'] = $data['name'];
        }
        if (isset($data['strokeCounter'])) {
            $changes['stroke_counter'] = (bool) $data['strokeCounter'];
        }
        if (isset($data['status'])) {
            $changes['status'] = $data['status'];
        }
        $this->update($db, 'machines', 'machine', $id, $version, $changes, 'machine.updated', $plant);

        return $this->respond($db, new MachinesQuery, $id);
    }

    /** Create a die, jig or fixture in a plant. Codes are unique across the tenant (doc 26 §2.1). */
    public function storeDie(Request $request): JsonResponse
    {
        $data = $request->validate([
            'plantId' => ['required', 'uuid'],
            'code' => ['required', 'string', OrgController::CODE],
            'name' => ['required', 'string', 'min:1', 'max:200'],
            'dieType' => ['required', Rule::in(DiesQuery::TYPES)],
            'cavities' => ['sometimes', 'integer', 'between:1,1000'],
            'strokeLife' => ['nullable', 'integer', 'min:1'],
            'currentStrokes' => ['sometimes', 'integer', 'min:0'],
        ]);
        $db = $this->db();
        $this->authorizer->requireAnywhere($db, self::CREATE);
        $plant = $this->knownPlant($db, $data['plantId']);
        $this->authorizer->authorize($db, self::CREATE, $this->plantScope($db, $plant));

        $id = $this->insert($db,
            'insert into dies (tenant_id, plant_id, code, name, die_type, cavities, stroke_life, current_strokes)
             values ((select c.tenant from ctx_api.context() c), ?, ?, ?, ?, ?, ?, ?) returning id',
            [$plant, $data['code'], $data['name'], $data['dieType'], $data['cavities'] ?? 1, $data['strokeLife'] ?? null, $data['currentStrokes'] ?? 0],
            'A die with this code already exists.');
        $this->audit->record($db, 'die.created', 'die', $id, null, $this->state($db, 'dies', $id), ['code' => $data['code']], $plant);

        return $this->respond($db, new DiesQuery, $id, 201);
    }

    /**
     * Change a die's attributes or status, or move it to another plant (If-Match). Moving needs `master.org.create`
     * in both plants; the move is an ordinary audited update (no tooling-transfer module, doc 26 §2.1).
     */
    public function updateDie(Request $request, string $die): JsonResponse
    {
        $data = $request->validate([
            'plantId' => ['sometimes', 'uuid'],
            'name' => ['sometimes', 'string', 'min:1', 'max:200'],
            'dieType' => ['sometimes', Rule::in(DiesQuery::TYPES)],
            'cavities' => ['sometimes', 'integer', 'between:1,1000'],
            'strokeLife' => ['sometimes', 'nullable', 'integer', 'min:1'],
            'currentStrokes' => ['sometimes', 'integer', 'min:0'],
            'status' => ['sometimes', Rule::in(DiesQuery::STATUSES)],
        ]);
        $version = Concurrency::expectedVersion($request);
        $db = $this->db();
        $this->authorizer->requireAnywhere($db, self::CREATE);
        $id = $this->existing($db, 'dies', $die);
        $plant = (string) $db->selectOne('select plant_id from dies where id = ?', [$id])->plant_id;
        $this->authorizer->authorize($db, self::CREATE, $this->plantScope($db, $plant));

        $changes = [];
        if (isset($data['plantId'])) {
            $target = $this->knownPlant($db, $data['plantId']);
            $this->authorizer->authorize($db, self::CREATE, $this->plantScope($db, $target));
            $changes['plant_id'] = $target;
        }
        foreach (['name' => 'name', 'dieType' => 'die_type', 'cavities' => 'cavities', 'currentStrokes' => 'current_strokes', 'status' => 'status'] as $key => $column) {
            if (isset($data[$key])) {
                $changes[$column] = $data[$key];
            }
        }
        if (array_key_exists('strokeLife', $data)) {
            $changes['stroke_life'] = $data['strokeLife'];
        }
        $this->update($db, 'dies', 'die', $id, $version, $changes, 'die.updated', $plant);

        return $this->respond($db, new DiesQuery, $id);
    }

    private function knownPlant(EoraPostgresConnection $db, string $plantId): string
    {
        $plant = strtolower($plantId);

        return $db->selectOne('select 1 as ok from plants where id = ?', [$plant]) !== null
            ? $plant
            : throw ValidationException::withMessages(['plantId' => 'Unknown plant.']);
    }

    private function activeWorkCenter(EoraPostgresConnection $db, string $workCenterId): stdClass
    {
        $wc = $db->selectOne('select id, plant_id, status from work_centers where id = ?', [strtolower($workCenterId)])
            ?? throw ValidationException::withMessages(['workCenterId' => 'Unknown work center.']);
        if ($wc->status !== 'ACTIVE') {
            throw ValidationException::withMessages(['workCenterId' => 'This work center is inactive.']);
        }

        return $wc;
    }
}
