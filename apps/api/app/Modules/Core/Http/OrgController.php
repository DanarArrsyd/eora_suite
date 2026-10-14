<?php

declare(strict_types=1);

namespace App\Modules\Core\Http;

use App\Modules\Core\Queries\CompaniesQuery;
use App\Modules\Core\Queries\LocationsQuery;
use App\Modules\Core\Queries\PlantsQuery;
use App\Modules\Core\Queries\WarehousesQuery;
use App\Platform\Access\Authorizer;
use App\Platform\Access\Scope;
use App\Platform\Audit\AuditRecorder;
use App\Platform\Database\EoraPostgresConnection;
use App\Platform\Exceptions\DomainProblem;
use App\Platform\Http\Concurrency;
use App\Platform\Query\QueryDefinition;
use App\Platform\Query\QueryRunner;
use DateTimeZone;
use Illuminate\Database\QueryException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use LogicException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Master Data → organisation (doc 29 §1.1, doc 26 §2.1): companies, plants, warehouses, locations. Runs inside the
 * `tenant` middleware (one TenantTransaction, signed context of the session user). Permission `master.org.create`
 * in a scope covering the record (tenant ⊇ company ⊇ plant). Codes are immutable; edits use If-Match versions;
 * every write is audited with the stored row before and after. Lists are the `core.*` query resources.
 */
final class OrgController
{
    private const string CREATE = 'master.org.create';

    public const string CODE = 'regex:/^[A-Z0-9][A-Z0-9_-]{0,19}$/';

    public function __construct(
        private readonly Authorizer $authorizer,
        private readonly AuditRecorder $audit,
        private readonly QueryRunner $rows,
    ) {}

    /** Create a company of the tenant (tenant scope). */
    public function storeCompany(Request $request): JsonResponse
    {
        $data = $request->validate([
            'code' => ['required', 'string', self::CODE],
            'legalName' => ['required', 'string', 'min:1', 'max:200'],
        ]);
        $db = $this->db();
        $this->authorizer->authorize($db, self::CREATE, Scope::tenant());

        $id = $this->insert($db, 'insert into companies (tenant_id, code, legal_name) values ((select c.tenant from ctx_api.context() c), ?, ?) returning id',
            [$data['code'], $data['legalName']], 'A company with this code already exists.');
        $this->audit->record($db, 'company.created', 'company', $id, null, $this->state($db, 'companies', $id), ['code' => $data['code']]);

        return $this->respond($db, new CompaniesQuery, $id, 201);
    }

    /** Rename a company (If-Match). */
    public function updateCompany(Request $request, string $company): JsonResponse
    {
        $data = $request->validate(['legalName' => ['required', 'string', 'min:1', 'max:200']]);
        $version = Concurrency::expectedVersion($request);
        $db = $this->db();
        $this->authorizer->requireAnywhere($db, self::CREATE);
        $id = $this->existing($db, 'companies', $company);
        $this->authorizer->authorize($db, self::CREATE, Scope::company($id));

        $this->update($db, 'companies', $id, $version, ['legal_name' => $data['legalName']], 'company.updated');

        return $this->respond($db, new CompaniesQuery, $id);
    }

    /** Create a plant in a company (company scope); its virtual locations are created with it. */
    public function storePlant(Request $request): JsonResponse
    {
        $data = $request->validate([
            'companyId' => ['required', 'uuid'],
            'code' => ['required', 'string', self::CODE],
            'name' => ['required', 'string', 'min:1', 'max:200'],
            'timezone' => ['nullable', 'string', Rule::in(DateTimeZone::listIdentifiers())],
        ]);
        $db = $this->db();
        $this->authorizer->requireAnywhere($db, self::CREATE);
        $company = $db->selectOne('select id from companies where id = ?', [strtolower($data['companyId'])])
            ?? throw ValidationException::withMessages(['companyId' => 'Unknown company.']);
        $this->authorizer->authorize($db, self::CREATE, Scope::company((string) $company->id));

        $id = $this->insert($db,
            'insert into plants (tenant_id, company_id, code, name, timezone)
             values ((select c.tenant from ctx_api.context() c), ?, ?, ?, coalesce(?, (select t.timezone from tenants t where t.id = (select c.tenant from ctx_api.context() c))))
             returning id',
            [$company->id, $data['code'], $data['name'], $data['timezone'] ?? null], 'A plant with this code already exists.');
        $this->audit->record($db, 'plant.created', 'plant', $id, null, $this->state($db, 'plants', $id), ['code' => $data['code'], 'companyId' => (string) $company->id], $id);

        return $this->respond($db, new PlantsQuery, $id, 201);
    }

    /** Rename a plant (If-Match). Code, company and time zone are fixed once the plant exists. */
    public function updatePlant(Request $request, string $plant): JsonResponse
    {
        $data = $request->validate(['name' => ['required', 'string', 'min:1', 'max:200']]);
        $version = Concurrency::expectedVersion($request);
        $db = $this->db();
        $this->authorizer->requireAnywhere($db, self::CREATE);
        $id = $this->existing($db, 'plants', $plant);
        $this->authorizer->authorize($db, self::CREATE, $this->plantScope($db, $id));

        $this->update($db, 'plants', $id, $version, ['name' => $data['name']], 'plant.updated', $id);

        return $this->respond($db, new PlantsQuery, $id);
    }

    /** Create a warehouse in a plant (plant scope). */
    public function storeWarehouse(Request $request): JsonResponse
    {
        $data = $request->validate([
            'plantId' => ['required', 'uuid'],
            'code' => ['required', 'string', self::CODE],
            'name' => ['required', 'string', 'min:1', 'max:200'],
            'warehouseType' => ['required', Rule::in(WarehousesQuery::TYPES)],
        ]);
        $db = $this->db();
        $this->authorizer->requireAnywhere($db, self::CREATE);
        $plant = strtolower($data['plantId']);
        if ($db->selectOne('select 1 as ok from plants where id = ?', [$plant]) === null) {
            throw ValidationException::withMessages(['plantId' => 'Unknown plant.']);
        }
        $this->authorizer->authorize($db, self::CREATE, $this->plantScope($db, $plant));

        $id = $this->insert($db,
            'insert into warehouses (tenant_id, plant_id, code, name, warehouse_type) values ((select c.tenant from ctx_api.context() c), ?, ?, ?, ?) returning id',
            [$plant, $data['code'], $data['name'], $data['warehouseType']], 'A warehouse with this code already exists in this plant.');
        $this->audit->record($db, 'warehouse.created', 'warehouse', $id, null, $this->state($db, 'warehouses', $id), ['code' => $data['code']], $plant);

        return $this->respond($db, new WarehousesQuery, $id, 201);
    }

    /** Change a warehouse's name, type or status (If-Match). */
    public function updateWarehouse(Request $request, string $warehouse): JsonResponse
    {
        $data = $request->validate([
            'name' => ['sometimes', 'string', 'min:1', 'max:200'],
            'warehouseType' => ['sometimes', Rule::in(WarehousesQuery::TYPES)],
            'status' => ['sometimes', Rule::in(['ACTIVE', 'INACTIVE'])],
        ]);
        $version = Concurrency::expectedVersion($request);
        $db = $this->db();
        $this->authorizer->requireAnywhere($db, self::CREATE);
        $id = $this->existing($db, 'warehouses', $warehouse);
        $plant = (string) $db->selectOne('select plant_id from warehouses where id = ?', [$id])->plant_id;
        $this->authorizer->authorize($db, self::CREATE, $this->plantScope($db, $plant));

        $this->update($db, 'warehouses', $id, $version, array_filter([
            'name' => $data['name'] ?? null, 'warehouse_type' => $data['warehouseType'] ?? null, 'status' => $data['status'] ?? null,
        ], static fn ($v) => $v !== null), 'warehouse.updated', $plant);

        return $this->respond($db, new WarehousesQuery, $id);
    }

    /** Create a physical location inside an active warehouse (plant scope of the warehouse). */
    public function storeLocation(Request $request): JsonResponse
    {
        $data = $request->validate([
            'warehouseId' => ['required', 'uuid'],
            'code' => ['required', 'string', self::CODE, 'not_regex:/^V_/'],
            'name' => ['required', 'string', 'min:1', 'max:200'],
            'locationType' => ['required', Rule::in(LocationsQuery::WAREHOUSE_TYPES)],
        ], ['code.not_regex' => 'Codes starting with V_ are reserved for virtual locations.']);
        $db = $this->db();
        $this->authorizer->requireAnywhere($db, self::CREATE);
        $warehouse = $db->selectOne('select id, plant_id, status from warehouses where id = ?', [strtolower($data['warehouseId'])])
            ?? throw ValidationException::withMessages(['warehouseId' => 'Unknown warehouse.']);
        $this->authorizer->authorize($db, self::CREATE, $this->plantScope($db, (string) $warehouse->plant_id));
        if ($warehouse->status !== 'ACTIVE') {
            throw ValidationException::withMessages(['warehouseId' => 'This warehouse is inactive.']);
        }

        $id = $this->insert($db,
            'insert into locations (tenant_id, plant_id, warehouse_id, code, name, location_type) values ((select c.tenant from ctx_api.context() c), ?, ?, ?, ?, ?) returning id',
            [$warehouse->plant_id, $warehouse->id, $data['code'], $data['name'], $data['locationType']], 'A location with this code already exists in this plant.');
        $this->audit->record($db, 'location.created', 'location', $id, null, $this->state($db, 'locations', $id), ['code' => $data['code'], 'locationType' => $data['locationType']], (string) $warehouse->plant_id);

        return $this->respond($db, new LocationsQuery, $id, 201);
    }

    /** Change a location's name or status (If-Match). Virtual locations are fixed. */
    public function updateLocation(Request $request, string $location): JsonResponse
    {
        $data = $request->validate([
            'name' => ['sometimes', 'string', 'min:1', 'max:200'],
            'status' => ['sometimes', Rule::in(['ACTIVE', 'INACTIVE'])],
        ]);
        $version = Concurrency::expectedVersion($request);
        $db = $this->db();
        $this->authorizer->requireAnywhere($db, self::CREATE);
        $id = $this->existing($db, 'locations', $location);
        $row = $db->selectOne('select plant_id, location_type from locations where id = ?', [$id]);
        $this->authorizer->authorize($db, self::CREATE, $this->plantScope($db, (string) $row->plant_id));
        if (str_starts_with((string) $row->location_type, 'V_')) {
            throw new DomainProblem(409, 'INVALID_STATE_TRANSITION', 'Virtual location', 'Virtual locations are part of every plant and cannot be changed.');
        }

        $this->update($db, 'locations', $id, $version, array_filter([
            'name' => $data['name'] ?? null, 'status' => $data['status'] ?? null,
        ], static fn ($v) => $v !== null), 'location.updated', (string) $row->plant_id);

        return $this->respond($db, new LocationsQuery, $id);
    }

    private function db(): EoraPostgresConnection
    {
        $db = DB::connection();

        return $db instanceof EoraPostgresConnection ? $db : throw new LogicException('Master data requires the EORA PostgreSQL connection.');
    }

    /** Lower-cased id of a visible row, or 404 (also for malformed ids and other tenants' ids). */
    private function existing(EoraPostgresConnection $db, string $table, string $id): string
    {
        $id = strtolower($id);
        if (preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/', $id) !== 1
            || $db->selectOne("select 1 as ok from {$table} where id = ?", [$id]) === null) {
            throw new NotFoundHttpException;
        }

        return $id;
    }

    private function plantScope(EoraPostgresConnection $db, string $plant): Scope
    {
        return Scope::plant($plant, (string) $db->selectOne('select company_id from plants where id = ?', [$plant])->company_id);
    }

    /** @param  list<mixed>  $bindings */
    private function insert(EoraPostgresConnection $db, string $sql, array $bindings, string $duplicate): string
    {
        try {
            return (string) $db->selectOne($sql, $bindings)->id;
        } catch (QueryException $e) {
            if ($e->getCode() === '23505') {
                throw ValidationException::withMessages(['code' => $duplicate]);
            }
            throw $e;
        }
    }

    /**
     * Applies $changes to the row at $version (412 if another version committed first) and audits before/after.
     *
     * @param  array<string, string>  $changes  column => value (column names are code, never input)
     */
    private function update(EoraPostgresConnection $db, string $table, string $id, int $version, array $changes, string $action, ?string $plant = null): void
    {
        if ($changes === []) {
            throw ValidationException::withMessages(['name' => 'Nothing to change.']);
        }
        $before = $this->state($db, $table, $id);
        $set = implode(', ', array_map(static fn (string $c) => "{$c} = ?", array_keys($changes)));
        if ($db->update("update {$table} set {$set} where id = ? and version = ?", [...array_values($changes), $id, $version]) === 0) {
            throw Concurrency::conflict();
        }
        $entity = ['companies' => 'company', 'plants' => 'plant', 'warehouses' => 'warehouse', 'locations' => 'location'][$table];
        $this->audit->record($db, $action, $entity, $id, $before, $this->state($db, $table, $id), ['changed' => array_keys($changes)], $plant);
    }

    /** @return array<string, mixed> the stored row, the same projection before and after a change */
    private function state(EoraPostgresConnection $db, string $table, string $id): array
    {
        return (array) $db->selectOne("select * from {$table} where id = ?", [$id]);
    }

    private function respond(EoraPostgresConnection $db, QueryDefinition $definition, string $id, int $status = 200): JsonResponse
    {
        $row = $this->rows->find($db, $definition, $id) ?? throw new LogicException('Written row not visible.');

        return Concurrency::withVersion(response()->json(['data' => $row], $status), (int) $row['version']);
    }
}
