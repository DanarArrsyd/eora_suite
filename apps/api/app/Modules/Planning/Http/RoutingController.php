<?php

declare(strict_types=1);

namespace App\Modules\Planning\Http;

use App\Modules\Planning\Queries\ProcessTypesQuery;
use App\Modules\Planning\Queries\RoutingsQuery;
use App\Modules\Planning\Queries\RoutingVersionsQuery;
use App\Platform\Access\Authorizer;
use App\Platform\Audit\AuditRecorder;
use App\Platform\Database\EoraPostgresConnection;
use App\Platform\Exceptions\DomainProblem;
use App\Platform\Http\Concurrency;
use App\Platform\Http\MasterWrites;
use App\Platform\Query\QueryRunner;
use Illuminate\Database\QueryException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use stdClass;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Master Data → routings (doc 29 §1.3, doc 26 §2.3–§2.4a, D-058, D-060, D-072). Process types are tenant-wide; routings
 * belong to an item and a plant and are maintained in a scope covering that plant. A version is edited while DRAFT
 * (its operation list is replaced as a whole), submitted, then approved by a second person through
 * `lifecycle_api.approve_routing_version` — the database function that alone can approve and supersede, and checks
 * actor, membership, permission, plant scope and maker ≠ checker itself. Edits use If-Match; every write is audited.
 */
final class RoutingController
{
    use MasterWrites;

    private const string CREATE = 'master.routing.create';

    private const string SUBMIT = 'master.routing.submit';

    private const string APPROVE = 'master.routing.approve';

    public function __construct(
        private readonly Authorizer $authorizer,
        private readonly AuditRecorder $audit,
        private readonly QueryRunner $rows,
    ) {}

    /** Create a process type (tenant-wide catalogue). */
    public function storeProcessType(Request $request): JsonResponse
    {
        $data = $request->validate([
            'code' => ['required', 'string', 'regex:/^[A-Z][A-Z0-9_-]{0,31}$/'],
            'name' => ['required', 'string', 'min:1', 'max:100'],
            'category' => ['required', Rule::in(ProcessTypesQuery::CATEGORIES)],
            'defaultWorkCenterType' => ['nullable', 'string', 'regex:/^[A-Z][A-Z0-9_]{1,31}$/'],
        ]);
        $db = $this->db();
        $this->authorizer->requireAnywhere($db, self::CREATE);

        $id = $this->insert($db,
            'insert into process_types (tenant_id, code, name, category, default_work_center_type) values ((select c.tenant from ctx_api.context() c), ?, ?, ?, ?) returning id',
            [$data['code'], $data['name'], $data['category'], self::optionalText($data['defaultWorkCenterType'] ?? null)], 'A process type with this code already exists.');
        $this->audit->record($db, 'process_type.created', 'process_type', $id, null, $this->state($db, 'process_types', $id), ['code' => $data['code']]);

        return $this->respond($db, new ProcessTypesQuery, $id, 201);
    }

    /** Change a process type's name, category, default work-center type or status (If-Match). */
    public function updateProcessType(Request $request, string $processType): JsonResponse
    {
        $data = $request->validate([
            'name' => ['sometimes', 'string', 'min:1', 'max:100'],
            'category' => ['sometimes', Rule::in(ProcessTypesQuery::CATEGORIES)],
            'defaultWorkCenterType' => ['sometimes', 'nullable', 'string', 'regex:/^[A-Z][A-Z0-9_]{1,31}$/'],
            'status' => ['sometimes', Rule::in(['ACTIVE', 'INACTIVE'])],
        ]);
        $version = Concurrency::expectedVersion($request);
        $db = $this->db();
        $this->authorizer->requireAnywhere($db, self::CREATE);
        $id = $this->existing($db, 'process_types', $processType);

        $changes = [];
        foreach (['name' => 'name', 'category' => 'category', 'status' => 'status'] as $key => $column) {
            if (isset($data[$key])) {
                $changes[$column] = $data[$key];
            }
        }
        if (array_key_exists('defaultWorkCenterType', $data)) {
            $changes['default_work_center_type'] = self::optionalText($data['defaultWorkCenterType']);
        }
        $this->update($db, 'process_types', 'process_type', $id, $version, $changes, 'process_type.updated');

        return $this->respond($db, new ProcessTypesQuery, $id);
    }

    /** Create a routing for an item in a plant, with its first DRAFT version. */
    public function storeRouting(Request $request): JsonResponse
    {
        $data = $request->validate([
            'plantId' => ['required', 'uuid'],
            'itemId' => ['required', 'uuid'],
            'routingCode' => ['required', 'string', 'regex:/^[A-Z0-9][A-Z0-9_-]{0,19}$/'],
            'routingKind' => ['required', Rule::in(['PRIMARY', 'ALTERNATE'])],
            'effectiveFrom' => ['required', 'date_format:Y-m-d'],
            'changeReason' => ['nullable', 'string', 'max:500'],
        ]);
        $db = $this->db();
        $this->authorizer->requireAnywhere($db, self::CREATE);
        $plant = strtolower($data['plantId']);
        if ($db->selectOne('select 1 as ok from plants where id = ?', [$plant]) === null) {
            throw ValidationException::withMessages(['plantId' => 'Unknown plant.']);
        }
        $this->authorizer->authorize($db, self::CREATE, $this->plantScope($db, $plant));
        $item = $db->selectOne('select id, status from items where id = ?', [strtolower($data['itemId'])])
            ?? throw ValidationException::withMessages(['itemId' => 'Unknown item.']);
        if ($item->status !== 'ACTIVE') {
            throw ValidationException::withMessages(['itemId' => 'Only active items get a routing.']);
        }

        try {
            $id = (string) $db->selectOne(
                'insert into routings (tenant_id, plant_id, item_id, routing_code, routing_kind) values ((select c.tenant from ctx_api.context() c), ?, ?, ?, ?) returning id',
                [$plant, $item->id, $data['routingCode'], $data['routingKind']],
            )->id;
        } catch (QueryException $e) {
            if ($e->getCode() === '23505') {
                throw ValidationException::withMessages([
                    str_contains($e->getMessage(), 'routings_one_primary_uq') ? 'routingKind' : 'routingCode' => str_contains($e->getMessage(), 'routings_one_primary_uq')
                        ? 'This item already has a primary routing in this plant.'
                        : 'This item already has a routing with this code in this plant.',
                ]);
            }
            throw $e;
        }
        $this->audit->record($db, 'routing.created', 'routing', $id, null, $this->state($db, 'routings', $id), ['routingCode' => $data['routingCode']], $plant);
        $this->newVersion($db, $id, $plant, 1, $data['effectiveFrom'], self::optionalText($data['changeReason'] ?? null), null);

        return $this->respond($db, new RoutingsQuery, $id, 201);
    }

    /** Activate or deactivate a routing (If-Match). */
    public function updateRouting(Request $request, string $routing): JsonResponse
    {
        $data = $request->validate(['status' => ['required', Rule::in(['ACTIVE', 'INACTIVE'])]]);
        $version = Concurrency::expectedVersion($request);
        $db = $this->db();
        $this->authorizer->requireAnywhere($db, self::CREATE);
        $id = $this->existing($db, 'routings', $routing);
        $plant = (string) $db->selectOne('select plant_id from routings where id = ?', [$id])->plant_id;
        $this->authorizer->authorize($db, self::CREATE, $this->plantScope($db, $plant));

        try {
            $this->update($db, 'routings', 'routing', $id, $version, ['status' => $data['status']], 'routing.updated', $plant);
        } catch (QueryException $e) {
            if ($e->getCode() === '23505') {
                throw ValidationException::withMessages(['status' => 'This item already has an active primary routing in this plant.']);
            }
            throw $e;
        }

        return $this->respond($db, new RoutingsQuery, $id);
    }

    /**
     * Revise: a new DRAFT version, copying the operations of `copyFromVersionId` (default: the latest approved version).
     * A routing has at most one open version (DRAFT, SUBMITTED or REJECTED) at a time.
     */
    public function storeVersion(Request $request, string $routing): JsonResponse
    {
        $data = $request->validate([
            'effectiveFrom' => ['required', 'date_format:Y-m-d'],
            'changeReason' => ['required', 'string', 'min:1', 'max:500'],
            'copyFromVersionId' => ['nullable', 'uuid'],
        ]);
        $db = $this->db();
        $this->authorizer->requireAnywhere($db, self::CREATE);
        $id = $this->existing($db, 'routings', $routing);
        $rt = $db->selectOne('select id, plant_id from routings where id = ? for update', [$id]);
        $this->authorizer->authorize($db, self::CREATE, $this->plantScope($db, (string) $rt->plant_id));
        if ($db->selectOne("select 1 as ok from routing_versions where routing_id = ? and status in ('DRAFT', 'SUBMITTED', 'REJECTED')", [$id]) !== null) {
            throw new DomainProblem(409, 'INVALID_STATE_TRANSITION', 'Open version exists', 'Finish or withdraw the open version of this routing first.');
        }
        $source = isset($data['copyFromVersionId'])
            ? $db->selectOne('select id from routing_versions where id = ? and routing_id = ?', [strtolower($data['copyFromVersionId']), $id])
            : $db->selectOne("select id from routing_versions where routing_id = ? and status in ('APPROVED', 'SUPERSEDED') order by version_no desc limit 1", [$id]);
        if (isset($data['copyFromVersionId']) && $source === null) {
            throw ValidationException::withMessages(['copyFromVersionId' => 'Unknown version of this routing.']);
        }
        $next = (int) $db->selectOne('select coalesce(max(version_no), 0) + 1 as n from routing_versions where routing_id = ?', [$id])->n;
        $versionId = $this->newVersion($db, $id, (string) $rt->plant_id, $next, $data['effectiveFrom'], $data['changeReason'], $source?->id);

        return $this->respondVersion($db, $versionId, 201);
    }

    /** One version with its operations, machines, tools and inspection requirements. */
    public function showVersion(string $version): JsonResponse
    {
        $db = $this->db();
        $scopes = $this->authorizer->requireAnywhere($db, 'master.routing.view');
        $id = $this->existing($db, 'routing_versions', $version);
        $this->authorizer->requireCovered($scopes, $this->plantScope($db, (string) $db->selectOne('select plant_id from routing_versions where id = ?', [$id])->plant_id));

        return $this->respondVersion($db, $id);
    }

    /** Edit a DRAFT version's start day, reason or standard yield (If-Match). */
    public function updateVersion(Request $request, string $version): JsonResponse
    {
        $data = $request->validate([
            'effectiveFrom' => ['sometimes', 'date_format:Y-m-d'],
            'changeReason' => ['sometimes', 'nullable', 'string', 'max:500'],
            'standardYield' => ['sometimes', 'nullable', 'numeric', 'gt:0', 'lte:1', 'decimal:0,5'],
        ]);
        $expected = Concurrency::expectedVersion($request);
        [$db, $id, $ver] = $this->draft($version, self::CREATE);

        $changes = [];
        if (isset($data['effectiveFrom'])) {
            if ($data['effectiveFrom'] < $this->today($db)) {
                throw ValidationException::withMessages(['effectiveFrom' => 'A version takes effect today or later.']);
            }
            $changes['effective_from'] = $data['effectiveFrom'];
        }
        if (array_key_exists('changeReason', $data)) {
            $changes['change_reason'] = self::optionalText($data['changeReason']);
        }
        if (array_key_exists('standardYield', $data)) {
            $changes['standard_yield'] = $data['standardYield'] === null ? null : (string) $data['standardYield'];
        }
        $this->update($db, 'routing_versions', 'routing_version', $id, $expected, $changes, 'routing_version.updated', (string) $ver->plant_id);

        return $this->respondVersion($db, $id);
    }

    /**
     * Replace the whole operation list of a DRAFT version (If-Match on the version). Sequence numbers are unique within
     * the version; machines must sit in the operation's work center; tools must be dies of the routing's plant linked to
     * the item.
     */
    public function putOperations(Request $request, string $version): JsonResponse
    {
        $data = $request->validate([
            'operations' => ['present', 'array', 'max:200'],
            'operations.*.opSeq' => ['required', 'integer', 'between:1,9999', 'distinct'],
            'operations.*.stepKey' => ['nullable', 'string', 'regex:/^[A-Z][A-Z0-9_-]{0,31}$/'],
            'operations.*.processTypeId' => ['required', 'uuid'],
            'operations.*.workCenterId' => ['required', 'uuid'],
            'operations.*.description' => ['nullable', 'string', 'max:500'],
            'operations.*.stdSetupMinutes' => ['nullable', 'numeric', 'min:0', 'decimal:0,2'],
            'operations.*.stdCycleSeconds' => ['required', 'numeric', 'min:0', 'decimal:0,3'],
            'operations.*.cycleBasis' => ['required', Rule::in(['PER_PIECE', 'PER_STROKE', 'PER_BATCH'])],
            'operations.*.partsPerCycle' => ['nullable', 'integer', 'between:1,100000'],
            'operations.*.inspectionRequired' => ['sometimes', 'boolean'],
            'operations.*.isCountPoint' => ['sometimes', 'boolean'],
            'operations.*.requiresTooling' => ['sometimes', 'boolean'],
            'operations.*.machines' => ['sometimes', 'array', 'max:50'],
            'operations.*.machines.*.machineId' => ['required', 'uuid'],
            'operations.*.machines.*.isPreferred' => ['sometimes', 'boolean'],
            'operations.*.machines.*.cycleSecondsOverride' => ['nullable', 'numeric', 'min:0', 'decimal:0,3'],
            'operations.*.tools' => ['sometimes', 'array', 'max:50'],
            'operations.*.tools.*.dieId' => ['required', 'uuid'],
            'operations.*.tools.*.quantity' => ['nullable', 'integer', 'between:1,100'],
            'operations.*.tools.*.cavitiesUsed' => ['nullable', 'integer', 'between:1,1000'],
            'operations.*.inspections' => ['sometimes', 'array', 'max:50'],
            'operations.*.inspections.*.characteristic' => ['required', 'string', 'min:1', 'max:200'],
            'operations.*.inspections.*.samplingNote' => ['nullable', 'string', 'max:500'],
        ]);
        $expected = Concurrency::expectedVersion($request);
        [$db, $id, $ver] = $this->draft($version, self::CREATE);

        // the version row carries the optimistic lock for its whole content
        if ($db->update('update routing_versions set change_reason = change_reason where id = ? and version = ?', [$id, $expected]) === 0) {
            throw Concurrency::conflict();
        }
        $db->delete('delete from routing_operations where routing_version_id = ?', [$id]);
        foreach ($data['operations'] as $i => $op) {
            $this->insertOperation($db, $ver, $op, $i);
        }
        $this->audit->record($db, 'routing_version.operations_replaced', 'routing_version', $id, null, null, ['operationCount' => count($data['operations'])], (string) $ver->plant_id);

        return $this->respondVersion($db, $id);
    }

    /** DRAFT → SUBMITTED: complete versions only (an operation, a standard yield, a start day not in the past). */
    public function submit(string $version): JsonResponse
    {
        [$db, $id, $ver] = $this->draft($version, self::SUBMIT);
        $problems = [];
        if ($ver->standard_yield === null) {
            $problems['standardYield'] = 'Enter the standard yield before submitting.';
        }
        if ($db->selectOne('select 1 as ok from routing_operations where routing_version_id = ?', [$id]) === null) {
            $problems['operations'] = 'Add at least one operation before submitting.';
        }
        if ($ver->effective_from < $this->today($db)) {
            $problems['effectiveFrom'] = 'The version would take effect in the past. Choose today or a later day.';
        }
        if ($problems !== []) {
            throw ValidationException::withMessages($problems);
        }

        return $this->transition($db, $id, $ver, 'SUBMITTED', ['submitted_at' => now()->toIso8601String()], 'routing_version.submitted');
    }

    /** SUBMITTED → DRAFT: the preparer (or another holder of submit) takes it back to change it. */
    public function withdraw(string $version): JsonResponse
    {
        [$db, $id, $ver] = $this->open($version, self::SUBMIT, 'SUBMITTED');

        return $this->transition($db, $id, $ver, 'DRAFT', ['submitted_at' => null], 'routing_version.withdrawn');
    }

    /** SUBMITTED → REJECTED with a reason, by someone who may approve and did not prepare it. */
    public function reject(Request $request, string $version): JsonResponse
    {
        $data = $request->validate(['reason' => ['required', 'string', 'min:3', 'max:500']]);
        [$db, $id, $ver] = $this->open($version, self::APPROVE, 'SUBMITTED');
        $this->refuseOwn($db, $ver);

        return $this->transition($db, $id, $ver, 'REJECTED', ['reject_reason' => $data['reason']], 'routing_version.rejected');
    }

    /** REJECTED → DRAFT to rework it. */
    public function reopen(string $version): JsonResponse
    {
        [$db, $id, $ver] = $this->open($version, self::SUBMIT, 'REJECTED');

        return $this->transition($db, $id, $ver, 'DRAFT', ['reject_reason' => null, 'submitted_at' => null], 'routing_version.reopened');
    }

    /**
     * SUBMITTED → APPROVED by a second person, superseding the version it replaces. The database function decides:
     * trusted actor, active membership, permission in the plant's scope, maker ≠ checker, lifecycle and content.
     */
    public function approve(string $version): JsonResponse
    {
        $db = $this->db();
        $this->authorizer->requireAnywhere($db, self::APPROVE);
        $id = $this->existing($db, 'routing_versions', $version);
        $before = $this->state($db, 'routing_versions', $id);

        try {
            $superseded = $db->selectOne('select lifecycle_api.approve_routing_version(?) as superseded', [$id])->superseded;
        } catch (QueryException $e) {
            throw match ((string) $e->getCode()) {
                'EO010' => new DomainProblem(403, 'SCOPE_FORBIDDEN', 'Forbidden'),
                'EO011' => new DomainProblem(403, 'SOD_VIOLATION', 'Not your own version', 'A routing version is approved by someone other than its preparer.'),
                'EO012' => new DomainProblem(409, 'INVALID_STATE_TRANSITION', 'Cannot approve', self::reason($e)),
                'EO013' => ValidationException::withMessages(['version' => self::reason($e)]),
                'P0002' => new NotFoundHttpException,
                default => $e,
            };
        }
        $plant = (string) $before['plant_id'];
        $this->audit->record($db, 'routing_version.approved', 'routing_version', $id, $before, $this->state($db, 'routing_versions', $id), ['supersedes' => $superseded], $plant);
        if ($superseded !== null) {
            $this->audit->record($db, 'routing_version.superseded', 'routing_version', (string) $superseded, null, $this->state($db, 'routing_versions', (string) $superseded), ['supersededBy' => $id], $plant);
        }

        return $this->respondVersion($db, $id);
    }

    /** @param  array<string, mixed>  $op */
    private function insertOperation(EoraPostgresConnection $db, stdClass $ver, array $op, int $index): void
    {
        $field = static fn (string $name) => "operations.{$index}.{$name}";
        if ($db->selectOne("select 1 as ok from process_types where id = ? and status = 'ACTIVE'", [strtolower($op['processTypeId'])]) === null) {
            throw ValidationException::withMessages([$field('processTypeId') => 'Unknown or inactive process type.']);
        }
        if ($db->selectOne("select 1 as ok from work_centers where id = ? and plant_id = ? and status = 'ACTIVE'", [strtolower($op['workCenterId']), $ver->plant_id]) === null) {
            throw ValidationException::withMessages([$field('workCenterId') => 'Choose an active work center of the routing\'s plant.']);
        }
        $opId = (string) $db->selectOne(
            'insert into routing_operations (tenant_id, plant_id, routing_version_id, op_seq, step_key, process_type_id, work_center_id, description,
                                             std_setup_minutes, std_cycle_seconds, cycle_basis, parts_per_cycle, inspection_required, is_count_point, requires_tooling)
             values ((select c.tenant from ctx_api.context() c), ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?) returning id',
            [$ver->plant_id, $ver->id, $op['opSeq'], self::optionalText($op['stepKey'] ?? null), strtolower($op['processTypeId']), strtolower($op['workCenterId']),
                self::optionalText($op['description'] ?? null), (string) ($op['stdSetupMinutes'] ?? 0), (string) $op['stdCycleSeconds'], $op['cycleBasis'],
                $op['partsPerCycle'] ?? 1, (bool) ($op['inspectionRequired'] ?? false), (bool) ($op['isCountPoint'] ?? true), (bool) ($op['requiresTooling'] ?? false)],
        )->id;

        $preferred = 0;
        foreach ($op['machines'] ?? [] as $m => $machine) {
            $preferred += (int) (bool) ($machine['isPreferred'] ?? false);
            $this->child($db, 'insert into routing_operation_machines (tenant_id, operation_id, machine_id, is_preferred, cycle_seconds_override) values ((select c.tenant from ctx_api.context() c), ?, ?, ?, ?)',
                [$opId, strtolower($machine['machineId']), (bool) ($machine['isPreferred'] ?? false), isset($machine['cycleSecondsOverride']) ? (string) $machine['cycleSecondsOverride'] : null],
                $field("machines.{$m}.machineId"), 'Choose a machine of this operation\'s work center, once.');
        }
        if ($preferred > 1) {
            throw ValidationException::withMessages([$field('machines') => 'Mark at most one preferred machine.']);
        }
        foreach ($op['tools'] ?? [] as $t => $tool) {
            $this->child($db, 'insert into routing_operation_tools (tenant_id, operation_id, die_id, quantity, cavities_used) values ((select c.tenant from ctx_api.context() c), ?, ?, ?, ?)',
                [$opId, strtolower($tool['dieId']), $tool['quantity'] ?? 1, $tool['cavitiesUsed'] ?? null],
                $field("tools.{$t}.dieId"), 'Choose a die of the routing\'s plant that is linked to this item, once.');
        }
        foreach ($op['inspections'] ?? [] as $inspection) {
            $db->insert('insert into routing_operation_inspections (tenant_id, operation_id, characteristic, sampling_note) values ((select c.tenant from ctx_api.context() c), ?, ?, ?)',
                [$opId, trim($inspection['characteristic']), self::optionalText($inspection['samplingNote'] ?? null)]);
        }
    }

    /** @param  list<mixed>  $bindings */
    private function child(EoraPostgresConnection $db, string $sql, array $bindings, string $field, string $message): void
    {
        try {
            $db->insert($sql, $bindings);
        } catch (QueryException $e) {
            if (in_array((string) $e->getCode(), ['EO013', '23505', '23503'], true)) {
                throw ValidationException::withMessages([$field => $message]);
            }
            throw $e;
        }
    }

    private function newVersion(EoraPostgresConnection $db, string $routing, string $plant, int $no, string $effectiveFrom, ?string $reason, ?string $copyFrom): string
    {
        if ($effectiveFrom < $this->today($db)) {
            throw ValidationException::withMessages(['effectiveFrom' => 'A version takes effect today or later.']);
        }
        $id = (string) $db->selectOne(
            'insert into routing_versions (tenant_id, plant_id, routing_id, version_no, effective_from, change_reason, standard_yield)
             values ((select c.tenant from ctx_api.context() c), ?, ?, ?, ?, ?, (select v.standard_yield from routing_versions v where v.id = ?)) returning id',
            [$plant, $routing, $no, $effectiveFrom, $reason, $copyFrom],
        )->id;
        if ($copyFrom !== null) {
            $this->copyOperations($db, $copyFrom, $id);
        }
        $this->audit->record($db, 'routing_version.created', 'routing_version', $id, null, $this->state($db, 'routing_versions', $id), ['versionNo' => $no, 'copiedFrom' => $copyFrom], $plant);

        return $id;
    }

    private function copyOperations(EoraPostgresConnection $db, string $from, string $to): void
    {
        foreach ($db->select('select * from routing_operations where routing_version_id = ? order by op_seq', [$from]) as $op) {
            $new = (string) $db->selectOne(
                'insert into routing_operations (tenant_id, plant_id, routing_version_id, op_seq, step_key, process_type_id, work_center_id, description,
                                                 std_setup_minutes, std_cycle_seconds, cycle_basis, parts_per_cycle, inspection_required, is_count_point, requires_tooling)
                 select tenant_id, plant_id, ?, op_seq, step_key, process_type_id, work_center_id, description, std_setup_minutes, std_cycle_seconds,
                        cycle_basis, parts_per_cycle, inspection_required, is_count_point, requires_tooling
                   from routing_operations where id = ? returning id',
                [$to, $op->id],
            )->id;
            // machines and tools that are no longer eligible are left out; the draft shows what remains
            $db->insert('insert into routing_operation_machines (tenant_id, operation_id, machine_id, is_preferred, cycle_seconds_override)
                         select om.tenant_id, ?, om.machine_id, om.is_preferred, om.cycle_seconds_override
                           from routing_operation_machines om join machines m on m.id = om.machine_id
                          where om.operation_id = ? and m.work_center_id = ?', [$new, $op->id, $op->work_center_id]);
            $db->insert('insert into routing_operation_tools (tenant_id, operation_id, die_id, quantity, cavities_used)
                         select ot.tenant_id, ?, ot.die_id, ot.quantity, ot.cavities_used
                           from routing_operation_tools ot
                           join dies d on d.id = ot.die_id and d.plant_id = ?
                           join routing_versions v on v.id = ?
                           join routings r on r.id = v.routing_id
                           join die_items di on di.die_id = d.id and di.item_id = r.item_id and di.status = \'ACTIVE\'
                          where ot.operation_id = ?', [$new, $op->plant_id, $to, $op->id]);
            $db->insert('insert into routing_operation_inspections (tenant_id, operation_id, characteristic, sampling_note)
                         select tenant_id, ?, characteristic, sampling_note from routing_operation_inspections where operation_id = ?', [$new, $op->id]);
        }
    }

    /**
     * A DRAFT version the caller may change (permission in a scope covering the routing's plant).
     *
     * @return array{0: EoraPostgresConnection, 1: string, 2: stdClass}
     */
    private function draft(string $version, string $permission): array
    {
        return $this->open($version, $permission, 'DRAFT');
    }

    /** @return array{0: EoraPostgresConnection, 1: string, 2: stdClass} */
    private function open(string $version, string $permission, string $status): array
    {
        $db = $this->db();
        $this->authorizer->requireAnywhere($db, $permission);
        $id = $this->existing($db, 'routing_versions', $version);
        $ver = $db->selectOne('select * from routing_versions where id = ? for update', [$id]);
        $this->authorizer->authorize($db, $permission, $this->plantScope($db, (string) $ver->plant_id));
        if ($ver->status !== $status) {
            throw new DomainProblem(409, 'INVALID_STATE_TRANSITION', 'Not possible in this state', sprintf('This needs a %s version; this one is %s.', strtolower($status), strtolower((string) $ver->status)));
        }

        return [$db, $id, $ver];
    }

    private function refuseOwn(EoraPostgresConnection $db, stdClass $ver): void
    {
        if ($db->selectOne('select 1 as self from ctx_api.context() c where c.actor = ?', [$ver->prepared_by]) !== null) {
            throw new DomainProblem(403, 'SOD_VIOLATION', 'Not your own version', 'A routing version is reviewed by someone other than its preparer.');
        }
    }

    /** @param  array<string, mixed>  $extra */
    private function transition(EoraPostgresConnection $db, string $id, stdClass $ver, string $to, array $extra, string $action): JsonResponse
    {
        $before = $this->state($db, 'routing_versions', $id);
        $set = implode(', ', array_map(static fn (string $c) => "{$c} = ?", ['status', ...array_keys($extra)]));
        $db->update("update routing_versions set {$set} where id = ?", [$to, ...array_values($extra), $id]);
        $this->audit->record($db, $action, 'routing_version', $id, $before, $this->state($db, 'routing_versions', $id), ['from' => $ver->status, 'to' => $to], (string) $ver->plant_id);

        return $this->respondVersion($db, $id);
    }

    private function today(EoraPostgresConnection $db): string
    {
        return (string) $db->selectOne('select (now() at time zone t.timezone)::date::text as d from tenants t where t.id = (select c.tenant from ctx_api.context() c)')->d;
    }

    /** The version row (list representation) with its operations and their children; ETag = version row version. */
    private function respondVersion(EoraPostgresConnection $db, string $id, int $status = 200): JsonResponse
    {
        $row = $this->rows->find($db, new RoutingVersionsQuery, $id) ?? throw new \LogicException('Written row not visible.');
        $ops = $db->select(
            'select o.id, o.op_seq, o.step_key, o.process_type_id, pt.code as process_type_code, pt.name as process_type_name,
                    o.work_center_id, wc.code as work_center_code, o.description, o.std_setup_minutes::text as std_setup_minutes,
                    o.std_cycle_seconds::text as std_cycle_seconds, o.cycle_basis, o.parts_per_cycle, o.inspection_required, o.is_count_point,
                    o.requires_tooling, (o.op_seq = max(o.op_seq) over ()) as is_final_operation
               from routing_operations o
               join process_types pt on pt.id = o.process_type_id
               join work_centers wc on wc.id = o.work_center_id
              where o.routing_version_id = ? order by o.op_seq',
            [$id],
        );
        $children = static fn (string $sql) => collect($db->select($sql, [$id]))->groupBy('operation_id');
        $machines = $children('select om.operation_id, om.machine_id, m.code as machine_code, om.is_preferred, om.cycle_seconds_override::text as cycle_seconds_override
                                 from routing_operation_machines om join machines m on m.id = om.machine_id
                                 join routing_operations o on o.id = om.operation_id where o.routing_version_id = ? order by m.code');
        $tools = $children('select ot.operation_id, ot.die_id, d.code as die_code, ot.quantity, ot.cavities_used
                              from routing_operation_tools ot join dies d on d.id = ot.die_id
                              join routing_operations o on o.id = ot.operation_id where o.routing_version_id = ? order by d.code');
        $inspections = $children('select oi.operation_id, oi.characteristic, oi.sampling_note
                                    from routing_operation_inspections oi join routing_operations o on o.id = oi.operation_id
                                   where o.routing_version_id = ? order by oi.created_at, oi.id');

        $row['operations'] = array_map(static fn (stdClass $o) => [
            'id' => $o->id, 'opSeq' => $o->op_seq, 'stepKey' => $o->step_key, 'processTypeId' => $o->process_type_id,
            'processTypeCode' => $o->process_type_code, 'processTypeName' => $o->process_type_name, 'workCenterId' => $o->work_center_id,
            'workCenterCode' => $o->work_center_code, 'description' => $o->description, 'stdSetupMinutes' => $o->std_setup_minutes,
            'stdCycleSeconds' => $o->std_cycle_seconds, 'cycleBasis' => $o->cycle_basis, 'partsPerCycle' => $o->parts_per_cycle,
            'inspectionRequired' => $o->inspection_required, 'isCountPoint' => $o->is_count_point, 'requiresTooling' => $o->requires_tooling,
            'isFinalOperation' => $o->is_final_operation,
            'machines' => ($machines[$o->id] ?? collect())->map(static fn ($m) => [
                'machineId' => $m->machine_id, 'machineCode' => $m->machine_code, 'isPreferred' => $m->is_preferred, 'cycleSecondsOverride' => $m->cycle_seconds_override,
            ])->values()->all(),
            'tools' => ($tools[$o->id] ?? collect())->map(static fn ($t) => [
                'dieId' => $t->die_id, 'dieCode' => $t->die_code, 'quantity' => $t->quantity, 'cavitiesUsed' => $t->cavities_used,
            ])->values()->all(),
            'inspections' => ($inspections[$o->id] ?? collect())->map(static fn ($i) => [
                'characteristic' => $i->characteristic, 'samplingNote' => $i->sampling_note,
            ])->values()->all(),
        ], $ops);

        return Concurrency::withVersion(response()->json(['data' => $row], $status), (int) $row['version']);
    }

    private static function reason(QueryException $e): string
    {
        $message = $e->getPrevious()?->getMessage() ?? $e->getMessage();

        return preg_match('/ERROR:\s+(.+?)(?:\n|\(Connection|$)/', $message, $m) === 1 ? ucfirst(trim($m[1])).'.' : 'The version cannot be approved.';
    }
}
