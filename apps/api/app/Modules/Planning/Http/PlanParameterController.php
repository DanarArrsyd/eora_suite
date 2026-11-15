<?php

declare(strict_types=1);

namespace App\Modules\Planning\Http;

use App\Modules\Planning\Queries\PlanParametersQuery;
use App\Platform\Access\Authorizer;
use App\Platform\Audit\AuditRecorder;
use App\Platform\Database\EoraPostgresConnection;
use App\Platform\Http\Concurrency;
use App\Platform\Http\MasterWrites;
use App\Platform\Query\QueryRunner;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use stdClass;

/**
 * Master Data → Planning Parameters (doc 34 §1.1, D-121, G-MPS-2/4): per item + plant, the production lead time of a
 * made item or the purchase lead time of a bought item (plant working days), the safety stock in the item's base unit
 * (empty = NOT CONFIGURED, counted as 0 and shown, D-046) and lot-for-lot. A planner changes them directly
 * (`planning.plan_parameter.create`), effective for the next MPS run; every change is audited, no approval.
 */
final class PlanParameterController
{
    use MasterWrites {
        update as private updateRow;
    }

    private const string CREATE = 'planning.plan_parameter.create';

    /** Items planned with a production lead time; the bought ones take a purchase lead time. */
    private const array MADE = ['FINISHED', 'SEMI_FINISHED'];

    private const array BOUGHT = ['RAW', 'CONSUMABLE'];

    public function __construct(
        private readonly Authorizer $authorizer,
        private readonly AuditRecorder $audit,
        private readonly QueryRunner $rows,
    ) {}

    /** The parameters of one item at one plant (201); an item has one set per plant. */
    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'plantId' => ['required', 'uuid'],
            'itemId' => ['required', 'uuid'],
            'productionLeadTimeDays' => ['nullable', 'integer', 'min:0', 'max:365'],
            'purchaseLeadTimeDays' => ['nullable', 'integer', 'min:0', 'max:365'],
            'safetyStock' => ['nullable', 'string', 'regex:/^\d{1,12}(\.\d{1,6})?$/'],
        ]);
        $db = $this->db();
        $this->authorizer->requireAnywhere($db, self::CREATE);
        $plant = $this->existing($db, 'plants', $data['plantId']);
        $this->authorizer->authorize($db, self::CREATE, $this->plantScope($db, $plant));
        $item = $this->item($db, $data['itemId']);
        $values = $this->values($item, $data);
        if ($db->selectOne('select 1 as ok from item_plan_parameters where plant_id = ? and item_id = ?', [$plant, $item->id]) !== null) {
            throw ValidationException::withMessages(['itemId' => 'This item has planning parameters at this plant already; change them instead.']);
        }
        $id = $this->insert($db,
            'insert into item_plan_parameters (tenant_id, plant_id, item_id, production_lead_time_days, purchase_lead_time_days, safety_stock)
             values ((select c.tenant from ctx_api.context() c), ?, ?, ?, ?, ?) returning id',
            [$plant, $item->id, $values['production_lead_time_days'], $values['purchase_lead_time_days'], $values['safety_stock']],
            'This item has planning parameters at this plant already; change them instead.');
        $this->audit->record($db, 'plan_parameter.created', 'plan_parameter', $id, null, $this->state($db, 'item_plan_parameters', $id), [], $plant);

        return $this->respond($db, new PlanParametersQuery, $id, 201);
    }

    /** Change lead time or safety stock (If-Match); an empty safety stock is NOT CONFIGURED again. */
    public function update(Request $request, string $parameter): JsonResponse
    {
        $data = $request->validate([
            'productionLeadTimeDays' => ['sometimes', 'nullable', 'integer', 'min:0', 'max:365'],
            'purchaseLeadTimeDays' => ['sometimes', 'nullable', 'integer', 'min:0', 'max:365'],
            'safetyStock' => ['sometimes', 'nullable', 'string', 'regex:/^\d{1,12}(\.\d{1,6})?$/'],
        ]);
        $expected = Concurrency::expectedVersion($request);
        $db = $this->db();
        $this->authorizer->requireAnywhere($db, self::CREATE);
        $id = $this->existing($db, 'item_plan_parameters', $parameter);
        $row = $db->selectOne('select plant_id, item_id from item_plan_parameters where id = ? for update', [$id]);
        $this->authorizer->authorize($db, self::CREATE, $this->plantScope($db, (string) $row->plant_id));
        $values = $this->values($this->item($db, (string) $row->item_id, false), $data);
        $changes = [];
        foreach (['productionLeadTimeDays' => 'production_lead_time_days', 'purchaseLeadTimeDays' => 'purchase_lead_time_days', 'safetyStock' => 'safety_stock'] as $field => $column) {
            if (array_key_exists($field, $data)) {
                $changes[$column] = $values[$column];
            }
        }
        $this->updateRow($db, 'item_plan_parameters', 'plan_parameter', $id, $expected, $changes, 'plan_parameter.updated', (string) $row->plant_id);

        return $this->respond($db, new PlanParametersQuery, $id);
    }

    private function item(EoraPostgresConnection $db, string $id, bool $active = true): stdClass
    {
        $item = $db->selectOne('select i.id, i.item_type, i.status, u.code as uom, u.decimals from items i join uoms u on u.id = i.base_uom_id where i.id = ?', [strtolower($id)])
            ?? throw ValidationException::withMessages(['itemId' => 'Unknown item.']);
        if ($active && $item->status !== 'ACTIVE') {
            throw ValidationException::withMessages(['itemId' => 'Choose an active item.']);
        }
        if (! in_array($item->item_type, [...self::MADE, ...self::BOUGHT], true)) {
            throw ValidationException::withMessages(['itemId' => 'Scrap is not planned. Choose a made or a bought item.']);
        }

        return $item;
    }

    /**
     * The stored values: a made item has a production lead time only, a bought item a purchase lead time only; the
     * safety stock keeps the unit's decimals.
     *
     * @param  array<string, mixed>  $data
     * @return array{production_lead_time_days: ?int, purchase_lead_time_days: ?int, safety_stock: ?string}
     */
    private function values(stdClass $item, array $data): array
    {
        $made = in_array($item->item_type, self::MADE, true);
        if ($made && isset($data['purchaseLeadTimeDays'])) {
            throw ValidationException::withMessages(['purchaseLeadTimeDays' => 'A made item is planned with its production lead time.']);
        }
        if (! $made && isset($data['productionLeadTimeDays'])) {
            throw ValidationException::withMessages(['productionLeadTimeDays' => 'A bought item is planned with its purchase lead time.']);
        }
        $safety = isset($data['safetyStock']) ? (string) $data['safetyStock'] : null;
        if ($safety !== null && strlen(explode('.', $safety.'.')[1]) > (int) $item->decimals) {
            throw ValidationException::withMessages(['safetyStock' => "{$item->uom} takes ".((int) $item->decimals === 0 ? 'whole numbers' : "at most {$item->decimals} decimals").'.']);
        }

        return [
            'production_lead_time_days' => isset($data['productionLeadTimeDays']) ? (int) $data['productionLeadTimeDays'] : null,
            'purchase_lead_time_days' => isset($data['purchaseLeadTimeDays']) ? (int) $data['purchaseLeadTimeDays'] : null,
            'safety_stock' => $safety,
        ];
    }
}
