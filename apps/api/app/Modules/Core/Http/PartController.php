<?php

declare(strict_types=1);

namespace App\Modules\Core\Http;

use App\Modules\Core\Queries\DieItemsQuery;
use App\Modules\Core\Queries\ItemsQuery;
use App\Modules\Core\Queries\UomConversionsQuery;
use App\Modules\Core\Queries\UomsQuery;
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

/**
 * Master Data → part master (doc 29 §1.2, doc 26 §2.2): units, items, material specifications, item unit conversions
 * and die ↔ item links. Units, items, specs and conversions are tenant-wide master data under `master.part.create`
 * (held in any scope — they are not plant-bound); die links follow the die's plant under `master.org.create`.
 * Codes, unit classes/decimals and an item's base unit are fixed once created; edits use If-Match; every write is
 * audited.
 */
final class PartController
{
    use MasterWrites;

    private const string PART = 'master.part.create';

    private const string ORG = 'master.org.create';

    public const string ITEM_CODE = 'regex:/^[A-Z0-9][A-Z0-9._\/-]{0,39}$/';

    public function __construct(
        private readonly Authorizer $authorizer,
        private readonly AuditRecorder $audit,
        private readonly QueryRunner $rows,
    ) {}

    /** Create a unit (code, class and decimals are fixed afterwards). */
    public function storeUom(Request $request): JsonResponse
    {
        $data = $request->validate([
            'code' => ['required', 'string', 'regex:/^[A-Z][A-Z0-9_]{0,9}$/'],
            'name' => ['required', 'string', 'min:1', 'max:100'],
            'uomClass' => ['required', Rule::in(UomsQuery::CLASSES)],
            'decimals' => ['required', 'integer', 'between:0,6'],
        ]);
        $db = $this->db();
        $this->authorizer->requireAnywhere($db, self::PART);

        $id = $this->insert($db, 'insert into uoms (tenant_id, code, name, uom_class, decimals) values ((select c.tenant from ctx_api.context() c), ?, ?, ?, ?) returning id',
            [$data['code'], $data['name'], $data['uomClass'], $data['decimals']], 'A unit with this code already exists.');
        $this->audit->record($db, 'uom.created', 'uom', $id, null, $this->state($db, 'uoms', $id), ['code' => $data['code']]);

        return $this->respond($db, new UomsQuery, $id, 201);
    }

    /** Rename a unit or change its status (If-Match). */
    public function updateUom(Request $request, string $uom): JsonResponse
    {
        $data = $request->validate([
            'name' => ['sometimes', 'string', 'min:1', 'max:100'],
            'status' => ['sometimes', Rule::in(['ACTIVE', 'INACTIVE'])],
        ]);
        $version = Concurrency::expectedVersion($request);
        $db = $this->db();
        $this->authorizer->requireAnywhere($db, self::PART);
        $id = $this->existing($db, 'uoms', $uom);

        $this->update($db, 'uoms', 'uom', $id, $version, $this->pick($data, ['name' => 'name', 'status' => 'status']), 'uom.updated');

        return $this->respond($db, new UomsQuery, $id);
    }

    /** Create an item with an active base unit. */
    public function storeItem(Request $request): JsonResponse
    {
        $data = $request->validate([
            'code' => ['required', 'string', self::ITEM_CODE],
            'name' => ['required', 'string', 'min:1', 'max:200'],
            'itemType' => ['required', Rule::in(ItemsQuery::TYPES)],
            'baseUomId' => ['required', 'uuid'],
            'lotControlled' => ['sometimes', 'boolean'],
            'inspectionRequired' => ['sometimes', 'boolean'],
            'incomingReleaseRequired' => ['sometimes', 'boolean'],
            'drawingNo' => ['sometimes', 'nullable', 'string', 'max:60'],
            'drawingRev' => ['sometimes', 'nullable', 'string', 'max:20'],
        ], ['code.regex' => 'Use 1–40 characters A-Z, 0-9, ".", "_", "/" or "-", starting with a letter or digit.']);
        $this->requireDrawingNo($data['drawingNo'] ?? null, $data['drawingRev'] ?? null);
        $db = $this->db();
        $this->authorizer->requireAnywhere($db, self::PART);
        $uom = $db->selectOne('select id, status from uoms where id = ?', [strtolower($data['baseUomId'])])
            ?? throw ValidationException::withMessages(['baseUomId' => 'Unknown unit.']);
        if ($uom->status !== 'ACTIVE') {
            throw ValidationException::withMessages(['baseUomId' => 'This unit is inactive.']);
        }

        $id = $this->insert($db,
            'insert into items (tenant_id, code, name, item_type, base_uom_id, lot_controlled, inspection_required, incoming_release_required, drawing_no, drawing_rev)
             values ((select c.tenant from ctx_api.context() c), ?, ?, ?, ?, ?, ?, ?, ?, ?) returning id',
            [$data['code'], $data['name'], $data['itemType'], $uom->id, (bool) ($data['lotControlled'] ?? true), (bool) ($data['inspectionRequired'] ?? false),
                (bool) ($data['incomingReleaseRequired'] ?? false), self::optionalText($data['drawingNo'] ?? null), self::optionalText($data['drawingRev'] ?? null)],
            'An item with this code already exists.');
        $this->audit->record($db, 'item.created', 'item', $id, null, $this->state($db, 'items', $id), ['code' => $data['code'], 'itemType' => $data['itemType']]);

        return $this->respond($db, new ItemsQuery, $id, 201);
    }

    /** Change an item's attributes or status (If-Match). Code and base unit stay. */
    public function updateItem(Request $request, string $item): JsonResponse
    {
        $data = $request->validate([
            'name' => ['sometimes', 'string', 'min:1', 'max:200'],
            'itemType' => ['sometimes', Rule::in(ItemsQuery::TYPES)],
            'lotControlled' => ['sometimes', 'boolean'],
            'inspectionRequired' => ['sometimes', 'boolean'],
            'incomingReleaseRequired' => ['sometimes', 'boolean'],
            'drawingNo' => ['sometimes', 'nullable', 'string', 'max:60'],
            'drawingRev' => ['sometimes', 'nullable', 'string', 'max:20'],
            'status' => ['sometimes', Rule::in(ItemsQuery::STATUSES)],
        ]);
        $version = Concurrency::expectedVersion($request);
        $db = $this->db();
        $this->authorizer->requireAnywhere($db, self::PART);
        $id = $this->existing($db, 'items', $item);

        $changes = $this->pick($data, [
            'name' => 'name', 'itemType' => 'item_type', 'lotControlled' => 'lot_controlled', 'inspectionRequired' => 'inspection_required',
            'incomingReleaseRequired' => 'incoming_release_required', 'status' => 'status',
        ]);
        foreach (['drawingNo' => 'drawing_no', 'drawingRev' => 'drawing_rev'] as $key => $column) {
            if (array_key_exists($key, $data)) {
                $changes[$column] = self::optionalText($data[$key]);
            }
        }
        $current = $db->selectOne('select drawing_no, drawing_rev from items where id = ?', [$id]);
        $this->requireDrawingNo(
            array_key_exists('drawing_no', $changes) ? $changes['drawing_no'] : $current->drawing_no,
            array_key_exists('drawing_rev', $changes) ? $changes['drawing_rev'] : $current->drawing_rev,
        );
        $this->update($db, 'items', 'item', $id, $version, $changes, 'item.updated');

        return $this->respond($db, new ItemsQuery, $id);
    }

    /**
     * Set an item's material specification: created on the first call, then edited at its version (If-Match). The
     * response is the item row, which carries the specification.
     */
    public function putMaterialSpec(Request $request, string $item): JsonResponse
    {
        $data = $request->validate([
            'grade' => ['nullable', 'string', 'max:60'],
            'thicknessMm' => ['nullable', 'numeric', 'gt:0', 'decimal:0,3'],
            'widthMm' => ['nullable', 'numeric', 'gt:0', 'decimal:0,3'],
            'densityGCm3' => ['nullable', 'numeric', 'gt:0', 'decimal:0,4'],
        ]);
        $db = $this->db();
        $this->authorizer->requireAnywhere($db, self::PART);
        $id = $this->existing($db, 'items', $item);
        $values = [
            'grade' => ($data['grade'] ?? '') === '' ? null : $data['grade'],
            'thickness_mm' => $data['thicknessMm'] ?? null,
            'width_mm' => $data['widthMm'] ?? null,
            'density_g_cm3' => $data['densityGCm3'] ?? null,
        ];

        // serialize the first creation: concurrent first-time PUTs wait here and then edit the created specification
        $db->selectOne('select id from items where id = ? for update', [$id]);
        $spec = $db->selectOne('select id from material_specs where item_id = ?', [$id]);
        if ($spec === null) {
            $specId = (string) $db->selectOne(
                'insert into material_specs (tenant_id, item_id, grade, thickness_mm, width_mm, density_g_cm3) values ((select c.tenant from ctx_api.context() c), ?, ?, ?, ?, ?) returning id',
                [$id, ...array_values($values)],
            )->id;
            $this->audit->record($db, 'material_spec.created', 'material_spec', $specId, null, $this->state($db, 'material_specs', $specId), ['itemId' => $id]);

            return $this->respondSpec($db, $id, 201);
        }
        $this->update($db, 'material_specs', 'material_spec', (string) $spec->id, Concurrency::expectedVersion($request), $values, 'material_spec.updated');

        return $this->respondSpec($db, $id);
    }

    /**
     * Add a conversion version for an item and a unit pair, in effect from a day that is not in the past (tenant
     * calendar, D-044). The open version of the same pair is closed the day before; an existing version starting on or
     * after that day is a conflict (422). Factors read "1 from-unit = factor to-units".
     */
    public function storeUomConversion(Request $request): JsonResponse
    {
        $data = $request->validate([
            'itemId' => ['required', 'uuid'],
            'fromUomId' => ['required', 'uuid'],
            'toUomId' => ['required', 'uuid', 'different:fromUomId'],
            'factor' => ['required', 'numeric', 'gt:0', 'decimal:0,10'],
            'effectiveFrom' => ['required', 'date_format:Y-m-d'],
        ]);
        $db = $this->db();
        $this->authorizer->requireAnywhere($db, self::PART);
        $item = $this->existingOr422($db, 'items', $data['itemId'], 'itemId', 'Unknown item.');
        $from = $this->existingOr422($db, 'uoms', $data['fromUomId'], 'fromUomId', 'Unknown unit.');
        $to = $this->existingOr422($db, 'uoms', $data['toUomId'], 'toUomId', 'Unknown unit.');
        $today = (string) $db->selectOne('select (now() at time zone t.timezone)::date::text as d from tenants t where t.id = (select c.tenant from ctx_api.context() c)')->d;
        if ($data['effectiveFrom'] < $today) {
            throw ValidationException::withMessages(['effectiveFrom' => "Conversions take effect today ({$today}) or later; past days keep the factor they had."]);
        }

        // serialize versions of this item: the open version is closed in the same transaction
        $db->selectOne('select id from items where id = ? for update', [$item]);
        $pair = 'item_id = ? and least(from_uom_id, to_uom_id) = least(?::uuid, ?::uuid) and greatest(from_uom_id, to_uom_id) = greatest(?::uuid, ?::uuid)';
        $pairBindings = [$item, $from, $to, $from, $to];
        if ($db->selectOne("select 1 as ok from uom_conversions where {$pair} and effective_from >= ?", [...$pairBindings, $data['effectiveFrom']]) !== null) {
            throw ValidationException::withMessages(['effectiveFrom' => 'A version of this conversion already starts on or after this day.']);
        }
        $open = $db->selectOne("select id from uom_conversions where {$pair} and effective_to is null", $pairBindings);
        if ($open !== null) {
            $before = $this->state($db, 'uom_conversions', (string) $open->id);
            $db->update('update uom_conversions set effective_to = ?::date - 1 where id = ?', [$data['effectiveFrom'], $open->id]);
            $this->audit->record($db, 'uom_conversion.closed', 'uom_conversion', (string) $open->id, $before, $this->state($db, 'uom_conversions', (string) $open->id), ['itemId' => $item]);
        }
        $id = (string) $db->selectOne(
            'insert into uom_conversions (tenant_id, item_id, from_uom_id, to_uom_id, factor, effective_from) values ((select c.tenant from ctx_api.context() c), ?, ?, ?, ?, ?) returning id',
            [$item, $from, $to, (string) $data['factor'], $data['effectiveFrom']],
        )->id;
        $this->audit->record($db, 'uom_conversion.created', 'uom_conversion', $id, null, $this->state($db, 'uom_conversions', $id), ['itemId' => $item]);

        return $this->respond($db, new UomConversionsQuery, $id, 201);
    }

    /** Link a die to a part it makes (scope of the die's plant). */
    public function storeDieItem(Request $request): JsonResponse
    {
        $data = $request->validate([
            'dieId' => ['required', 'uuid'],
            'itemId' => ['required', 'uuid'],
            'partsPerStroke' => ['sometimes', 'integer', 'between:1,1000'],
        ]);
        $db = $this->db();
        $this->authorizer->requireAnywhere($db, self::ORG);
        $die = $db->selectOne('select id, plant_id from dies where id = ?', [strtolower($data['dieId'])])
            ?? throw ValidationException::withMessages(['dieId' => 'Unknown die.']);
        $this->authorizer->authorize($db, self::ORG, $this->plantScope($db, (string) $die->plant_id));
        $item = $this->existingOr422($db, 'items', $data['itemId'], 'itemId', 'Unknown item.');

        $id = $this->insert($db, 'insert into die_items (tenant_id, die_id, item_id, parts_per_stroke) values ((select c.tenant from ctx_api.context() c), ?, ?, ?) returning id',
            [$die->id, $item, $data['partsPerStroke'] ?? 1], 'This die is already linked to this item.');
        $this->audit->record($db, 'die_item.created', 'die_item', $id, null, $this->state($db, 'die_items', $id), ['dieId' => (string) $die->id, 'itemId' => $item], (string) $die->plant_id);

        return $this->respond($db, new DieItemsQuery, $id, 201);
    }

    /** Change parts per stroke or status of a die link (If-Match). */
    public function updateDieItem(Request $request, string $dieItem): JsonResponse
    {
        $data = $request->validate([
            'partsPerStroke' => ['sometimes', 'integer', 'between:1,1000'],
            'status' => ['sometimes', Rule::in(['ACTIVE', 'INACTIVE'])],
        ]);
        $version = Concurrency::expectedVersion($request);
        $db = $this->db();
        $this->authorizer->requireAnywhere($db, self::ORG);
        $id = $this->existing($db, 'die_items', $dieItem);
        $plant = (string) $db->selectOne('select d.plant_id from die_items di join dies d on d.id = di.die_id where di.id = ?', [$id])->plant_id;
        $this->authorizer->authorize($db, self::ORG, $this->plantScope($db, $plant));

        $this->update($db, 'die_items', 'die_item', $id, $version, $this->pick($data, ['partsPerStroke' => 'parts_per_stroke', 'status' => 'status']), 'die_item.updated', $plant);

        return $this->respond($db, new DieItemsQuery, $id);
    }

    /** The item row, with the specification's version as ETag (the version the next spec edit must send). */
    private function respondSpec(EoraPostgresConnection $db, string $item, int $status = 200): JsonResponse
    {
        $row = $this->rows->find($db, new ItemsQuery, $item) ?? throw new \LogicException('Written row not visible.');

        return Concurrency::withVersion(response()->json(['data' => $row], $status), (int) $row['specVersion']);
    }

    /** A drawing revision belongs to a drawing number. */
    private function requireDrawingNo(mixed $drawingNo, mixed $drawingRev): void
    {
        if (($drawingRev ?? '') !== '' && ($drawingNo ?? '') === '') {
            throw ValidationException::withMessages(['drawingRev' => 'Enter the drawing number for this revision.']);
        }
    }

    /**
     * Request keys present in $data mapped onto their columns.
     *
     * @param  array<string, mixed>  $data
     * @param  array<string, string>  $map  key => column
     * @return array<string, mixed>
     */
    private function pick(array $data, array $map): array
    {
        $out = [];
        foreach ($map as $key => $column) {
            if (array_key_exists($key, $data)) {
                $out[$column] = $data[$key];
            }
        }

        return $out;
    }

    private function existingOr422(EoraPostgresConnection $db, string $table, string $id, string $field, string $message): string
    {
        $id = strtolower($id);

        return $db->selectOne("select 1 as ok from {$table} where id = ?", [$id]) !== null ? $id : throw ValidationException::withMessages([$field => $message]);
    }
}
