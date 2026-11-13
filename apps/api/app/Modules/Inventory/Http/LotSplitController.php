<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Http;

use App\Modules\Inventory\Application\InventoryPostingService;
use App\Modules\Inventory\Contracts\GenealogyLink;
use App\Modules\Inventory\Contracts\PostingCommand;
use App\Modules\Inventory\Contracts\PostingLeg;
use App\Modules\Inventory\Contracts\PostingResult;
use App\Modules\Inventory\Queries\StockQuery;
use App\Platform\Access\Authorizer;
use App\Platform\Database\EoraPostgresConnection;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Lot split / remnant (doc 04 row 7, DD-04, D-099): part of a lot becomes child lots at the same place and status, each
 * with its measured quantity and optional dimensions; the parent keeps the rest. A child is numbered after its parent
 * (`<parent>-01`, `-02` …), keeps the parent's supplier lot and properties, and is linked to it in the genealogy.
 */
final class LotSplitController
{
    use PostingRequests;

    /** A measured dimension in millimetres, above zero. */
    public const string MM = '/^(?!0+(\.0+)?$)\d{1,6}(\.\d{1,3})?$/';

    private const array DIMENSIONS = ['widthMm', 'lengthMm', 'thicknessMm'];

    public function __construct(
        private readonly Authorizer $authorizer,
        private readonly InventoryPostingService $postings,
    ) {}

    /**
     * Split part of a lot into child lots where it lies (Idempotency-Key header required). 201 when posted, 200 when the
     * same request was posted before under the key, 409 when the key was used for another request.
     */
    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'plantId' => ['required', 'uuid'],
            'itemId' => ['required', 'uuid'],
            'lotId' => ['required', 'uuid'],
            'stockStatus' => ['required', Rule::in(StockQuery::STATUSES)],
            'locationId' => ['required', 'uuid'],
            // material at a work center belongs to a work order (M4-D3): which WO's material is split
            'workOrderId' => ['nullable', 'uuid'],
            'children' => ['required', 'array', 'min:1', 'max:10'],
            'children.*.qty' => ['required', 'string', 'regex:'.ReceiptController::QTY],
            'children.*.widthMm' => ['nullable', 'string', 'regex:'.self::MM],
            'children.*.lengthMm' => ['nullable', 'string', 'regex:'.self::MM],
            'children.*.thicknessMm' => ['nullable', 'string', 'regex:'.self::MM],
            'note' => ['nullable', 'string', 'max:500'],
        ]);
        $key = $this->key($request);
        $db = $this->db();
        $plant = $this->plant($db, $data['plantId'], 'production.lot_split.post');
        $item = strtolower($data['itemId']);
        $parent = strtolower($data['lotId']);
        $location = strtolower($data['locationId']);
        $note = self::optionalText($data['note'] ?? null);
        $wo = isset($data['workOrderId']) ? strtolower($data['workOrderId']) : null;
        $children = array_map(static fn (array $c) => ['qty' => $c['qty']] + array_filter(
            array_intersect_key($c, array_flip(self::DIMENSIONS)),
            static fn ($v) => $v !== null,
        ), array_values($data['children']));
        // without a work order the request hashes as it did before splits could name one (ADR-0014 replays)
        $hash = hash('sha256', json_encode(['LOT_SPLIT', $plant, $item, $parent, $data['stockStatus'], $location, $children, $note, ...($wo === null ? [] : [$wo])], JSON_THROW_ON_ERROR));

        // a retried request answers with what it posted, whatever master data changed since; a concurrent one waits
        $previous = $this->postings->claim($db, $key, $hash);
        if ($previous !== null) {
            return $this->split($db, $previous);
        }
        $unit = $db->selectOne('select i.lot_controlled, i.base_uom_id, u.code as uom, u.decimals from items i join uoms u on u.id = i.base_uom_id where i.id = ?', [$item])
            ?? throw ValidationException::withMessages(['itemId' => 'Unknown item.']);
        if (! (bool) $unit->lot_controlled) {
            throw ValidationException::withMessages(['itemId' => 'This item is not tracked by lot, so it has no lot to split.']);
        }
        $total = '0';
        foreach ($children as $i => $child) {
            self::requireUnitPrecision($child['qty'], $unit, "children.{$i}.qty");
            $total = bcadd($total, $child['qty'], 6);
        }
        // children are numbered one after another per parent: concurrent splits of one lot take turns
        $db->selectOne("select pg_advisory_xact_lock(hashtextextended('lot-split:' || ?, 0))", [$parent]);
        $lot = $db->selectOne('select lot_no, supplier_lot_no, properties::text as properties, (select count(*) from lots c where c.parent_lot_id = l.id) as children from lots l where l.id = ? and l.item_id = ?', [$parent, $item])
            ?? throw ValidationException::withMessages(['lotId' => 'Unknown lot of this item.']);
        $place = $db->selectOne("select location_type from locations where id = ? and plant_id = ? and location_type not like 'V\\_%'", [$location, $plant])
            ?? throw ValidationException::withMessages(['locationId' => 'Choose a location of this plant that holds stock.']);
        if ($place->location_type === 'MAT_AT_PROD' && $wo === null) {
            throw ValidationException::withMessages(['workOrderId' => 'Material at a work center belongs to a work order: choose whose material is split.']);
        }
        if ($place->location_type !== 'MAT_AT_PROD' && $wo !== null) {
            throw ValidationException::withMessages(['workOrderId' => 'Only material at a work center belongs to a work order. Leave it empty.']);
        }

        $legs = [new PostingLeg('LOT_SPLIT_OUT', $location, $item, $parent, $data['stockStatus'], '-'.$total, woId: $wo)];
        $genealogy = [];
        foreach ($children as $i => $child) {
            $lotNo = sprintf('%s-%02d', $lot->lot_no, (int) $lot->children + $i + 1);
            if (strlen($lotNo) > 40) {
                throw ValidationException::withMessages(['lotId' => 'This lot has been split too many times to number another child. Split its parent instead.']);
            }
            $properties = array_merge((array) json_decode((string) $lot->properties, true, flags: JSON_THROW_ON_ERROR), array_map('floatval', array_diff_key($child, ['qty' => true])));
            $id = (string) $db->selectOne(
                "insert into lots (tenant_id, item_id, lot_no, supplier_lot_no, origin, parent_lot_id, properties)
                 values ((select c.tenant from ctx_api.context() c), ?, ?, ?, 'SPLIT', ?, ?::jsonb) returning id",
                [$item, $lotNo, $lot->supplier_lot_no, $parent, json_encode((object) $properties, JSON_THROW_ON_ERROR)],
            )->id;
            $legs[] = new PostingLeg('LOT_SPLIT_IN', $location, $item, $id, $data['stockStatus'], $child['qty'], woId: $wo);
            $genealogy[] = new GenealogyLink($parent, $id, $child['qty'], (string) $unit->base_uom_id, $child['qty'], (string) $unit->base_uom_id);
        }

        return $this->split($db, $this->postings->post($db, new PostingCommand('LOT_SPLIT', $plant, $key, $legs, $genealogy, note: $note, requestHash: $hash)));
    }

    /** The posted split: its parent lot and the children it created, in number order. */
    private function split(EoraPostgresConnection $db, PostingResult $result): JsonResponse
    {
        $rows = $db->select(
            'select m.movement_code, l.id, l.lot_no, trim_scale(abs(m.qty_signed))::text as qty
               from inventory_movements m join lots l on l.id = m.lot_id
              where m.document_id = ? order by m.movement_code desc, l.lot_no',
            [$result->documentId],
        );
        $parent = collect($rows)->firstWhere('movement_code', 'LOT_SPLIT_OUT');
        $children = collect($rows)->where('movement_code', 'LOT_SPLIT_IN')
            ->map(static fn ($r) => ['lotId' => $r->id, 'lotNo' => $r->lot_no, 'qty' => $r->qty])->values()->all();

        return response()->json(['data' => $result->toArray() + ['replayed' => $result->replayed, 'lotId' => $parent?->id, 'lotNo' => $parent?->lot_no, 'children' => $children]], $result->replayed ? 200 : 201);
    }
}
