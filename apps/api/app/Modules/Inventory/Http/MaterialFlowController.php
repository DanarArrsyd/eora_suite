<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Http;

use App\Modules\Inventory\Application\InventoryPostingService;
use App\Modules\Inventory\Contracts\PostingCommand;
use App\Modules\Inventory\Contracts\PostingLeg;
use App\Modules\Inventory\Contracts\PostingResult;
use App\Platform\Access\Authorizer;
use App\Platform\Database\EoraPostgresConnection;
use App\Platform\Exceptions\DomainProblem;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Validation\ValidationException;
use stdClass;

/**
 * Material issue and return (doc 04 rows 3 and 6, doc 33 §1.3, D-110).
 *
 * - Issue (`materials.issue.create` + `.post`): materials moves UNRESTRICTED lots of a released work order's material
 *   lines from raw-material locations to the Material at Production location of one work center of the WO's routing —
 *   several lines, one WO, one work center, posted together (BR-ISS-01: no consumption; quantity free, over-issue allowed).
 * - Return: production asks (`materials.return.submit`: WO, work center, item, lot, quantity); materials receives it with
 *   the measured quantity into a raw-material location of its choice (`materials.return.post`), or refuses it with the
 *   reason. Asked and received quantities are both kept. Nothing moves until it is received.
 */
final class MaterialFlowController
{
    use PostingRequests;

    public function __construct(
        private readonly Authorizer $authorizer,
        private readonly InventoryPostingService $postings,
    ) {}

    /**
     * Issue material to a released work order (Idempotency-Key header required). 201 when posted, 200 when the same request
     * was posted before under the key, 409 when the key was used for another request.
     */
    public function issue(Request $request): JsonResponse
    {
        $data = $request->validate([
            'workOrderId' => ['required', 'uuid'],
            'workCenterId' => ['required', 'uuid'],
            'lines' => ['required', 'array', 'min:1', 'max:20'],
            'lines.*.itemId' => ['required', 'uuid'],
            'lines.*.lotId' => ['nullable', 'uuid'],
            'lines.*.fromLocationId' => ['required', 'uuid'],
            'lines.*.qty' => ['required', 'string', 'regex:'.ReceiptController::QTY],
            'note' => ['nullable', 'string', 'max:500'],
        ]);
        $key = $this->key($request);
        $db = $this->db();
        $wo = $this->workOrder($db, $data['workOrderId']);
        $plant = $this->plant($db, (string) $wo->plant_id, 'materials.issue.post');
        $this->plant($db, $plant, 'materials.issue.create');
        // resolved whatever its state, so a retry replays even after the location was deactivated (checked active below)
        $production = $this->productionLocation($db, $wo, $data['workCenterId']);
        $legs = [];
        $seen = [];
        foreach (array_values($data['lines']) as $i => $line) {
            $item = strtolower($line['itemId']);
            $lot = isset($line['lotId']) ? strtolower($line['lotId']) : null;
            $from = strtolower($line['fromLocationId']);
            $k = "{$item}|{$lot}|{$from}";
            if (isset($seen[$k])) {
                throw ValidationException::withMessages(["lines.{$i}.lotId" => 'This lot and location are already on another line; enter the quantity once.']);
            }
            $seen[$k] = true;
            $legs[] = new PostingLeg('ISSUE_TO_PROD', $from, $item, $lot, 'UNRESTRICTED', '-'.$line['qty'], woId: (string) $wo->id);
            $legs[] = new PostingLeg('ISSUE_TO_PROD', $production, $item, $lot, 'UNRESTRICTED', $line['qty'], woId: (string) $wo->id);
        }
        $command = new PostingCommand('MAT_ISSUE', $plant, $key, $legs, note: self::optionalText($data['note'] ?? null), sourceType: 'work_order', sourceId: (string) $wo->id);
        // a retried request answers with what it posted, whatever the WO or master data did since
        $previous = $this->postings->claim($db, $key, $command->hash());
        if ($previous !== null) {
            return $this->issued($db, $previous);
        }

        // locked until the posting commits: the WO stays released meanwhile (BR-ISS-01, doc 04 row 3)
        $status = (string) $db->selectOne('select status from work_orders where id = ? for share', [$wo->id])->status;
        if (! in_array($status, ['RELEASED', 'IN_PROGRESS'], true)) {
            throw new DomainProblem(409, 'INVALID_STATE_TRANSITION', 'Work order not released', "Material is issued to a released work order; {$wo->wo_no} is ".strtolower($status).'.');
        }
        // a new issue goes only to an active location, held until the posting commits so it is not deactivated in between
        $db->selectOne("select 1 as ok from locations where id = ? and status = 'ACTIVE' for share", [$production])
            ?? throw ValidationException::withMessages(['workCenterId' => 'The Material at Production location of this work center is inactive. Choose another work center or reactivate it.']);
        $materials = [];
        foreach ($db->select('select distinct component_item_id from work_order_materials where work_order_id = ?', [$wo->id]) as $row) {
            $materials[(string) $row->component_item_id] = true;
        }
        foreach (array_values($data['lines']) as $i => $line) {
            $item = strtolower($line['itemId']);
            if (! isset($materials[$item])) {
                throw ValidationException::withMessages(["lines.{$i}.itemId" => "Choose a material of {$wo->wo_no}'s material lines."]);
            }
            $unit = $db->selectOne('select i.lot_controlled, u.code as uom, u.decimals from items i join uoms u on u.id = i.base_uom_id where i.id = ?', [$item]);
            self::requireUnitPrecision($line['qty'], $unit, "lines.{$i}.qty");
            $lot = isset($line['lotId']) ? strtolower($line['lotId']) : null;
            if ((bool) $unit->lot_controlled && ($lot === null || $db->selectOne('select 1 as ok from lots where id = ? and item_id = ?', [$lot, $item]) === null)) {
                throw ValidationException::withMessages(["lines.{$i}.lotId" => 'This material is tracked by lot: choose one of its lots.']);
            }
            if (! (bool) $unit->lot_controlled && $lot !== null) {
                throw ValidationException::withMessages(["lines.{$i}.lotId" => 'This material is not tracked by lot. Leave the lot empty.']);
            }
            $db->selectOne("select 1 as ok from locations where id = ? and plant_id = ? and location_type = 'RM_WAREHOUSE'", [strtolower($line['fromLocationId']), $plant])
                ?? throw ValidationException::withMessages(["lines.{$i}.fromLocationId" => 'Choose a raw-material location of this plant.']);
        }

        return $this->issued($db, $this->postings->post($db, $command));
    }

    /**
     * Ask for a return of material at production to the raw-material warehouse (Idempotency-Key header required). 201 with
     * the request, 200 when the same request was made before under the key, 409 when the key was used for another request.
     */
    public function requestReturn(Request $request): JsonResponse
    {
        $data = $request->validate([
            'workOrderId' => ['required', 'uuid'],
            'workCenterId' => ['required', 'uuid'],
            'itemId' => ['required', 'uuid'],
            'lotId' => ['nullable', 'uuid'],
            'qty' => ['required', 'string', 'regex:'.ReceiptController::QTY],
            'note' => ['nullable', 'string', 'max:500'],
        ]);
        $key = $this->key($request);
        $db = $this->db();
        $wo = $this->workOrder($db, $data['workOrderId']);
        $plant = $this->plant($db, (string) $wo->plant_id, 'materials.return.submit');
        $item = strtolower($data['itemId']);
        $lot = isset($data['lotId']) ? strtolower($data['lotId']) : null;
        $note = self::optionalText($data['note'] ?? null);
        $production = $this->productionLocation($db, $wo, $data['workCenterId']);
        $previous = $this->postings->requested($db, $key, 'MAT_RETURN',
            InventoryPostingService::returnHash($plant, (string) $wo->id, $production, $item, $lot, (string) $data['qty'], $note));
        if ($previous !== null) {
            return response()->json(['data' => ['documentId' => $previous, 'replayed' => true, 'status' => 'SUBMITTED']]);
        }

        $unit = $db->selectOne('select u.code as uom, u.decimals from items i join uoms u on u.id = i.base_uom_id where i.id = ?', [$item])
            ?? throw ValidationException::withMessages(['itemId' => 'Unknown item.']);
        self::requireUnitPrecision($data['qty'], $unit, 'qty');
        // checked again when it is received; asked now so a return of material that is not there is not left to materials
        $there = $this->atProduction($db, (string) $wo->id, $production, $item, $lot);
        $qty = (string) $data['qty'];
        if (! is_numeric($there) || ! is_numeric($qty) || bccomp($there, $qty, 6) < 0) {
            throw ValidationException::withMessages(['qty' => "{$wo->wo_no} has ".$this->trim($there)." {$unit->uom} of it at this work center."]);
        }
        $result = $this->postings->requestReturn($db, $plant, $key, (string) $wo->id, $production, $item, $lot, (string) $data['qty'], $note);

        return response()->json(['data' => $result + ['status' => 'SUBMITTED']], $result['replayed'] ? 200 : 201);
    }

    /**
     * Receive a return with the measured quantity into a raw-material location (Idempotency-Key header required): posted
     * now, by materials, never by who asked. 201 when posted, 200 when this key already posted it.
     */
    public function receive(Request $request, string $return): JsonResponse
    {
        $data = $request->validate([
            'toLocationId' => ['required', 'uuid'],
            'qty' => ['required', 'string', 'regex:'.ReceiptController::QTY],
        ]);
        $key = $this->key($request);
        $db = $this->db();
        $doc = $this->returnRequest($db, $return);
        $to = strtolower($data['toLocationId']);
        $previous = $this->postings->claim($db, $key, InventoryPostingService::receiveHash((string) $doc->id, $to, (string) $data['qty']));
        if ($previous !== null) {
            return $this->answer($db, $previous);
        }
        if ($doc->status !== 'SUBMITTED') {
            throw new DomainProblem(409, 'INVALID_STATE_TRANSITION', 'Already decided', 'This return has already been received or refused.');
        }
        $asked = json_decode((string) $doc->request, true, flags: JSON_THROW_ON_ERROR)['asked'];
        $unit = $db->selectOne('select u.code as uom, u.decimals from items i join uoms u on u.id = i.base_uom_id where i.id = ?', [$asked['itemId']]);
        self::requireUnitPrecision($data['qty'], $unit, 'qty');
        // locked until the posting commits, so the location cannot be deactivated in between
        $db->selectOne("select 1 as ok from locations where id = ? and plant_id = ? and location_type = 'RM_WAREHOUSE' and status = 'ACTIVE' for share", [$to, $doc->plant_id])
            ?? throw ValidationException::withMessages(['toLocationId' => 'Choose an active raw-material location of this plant.']);

        return $this->answer($db, $this->postings->receiveReturn($db, (string) $doc->id, $key, $to, (string) $data['qty']));
    }

    /** Refuse a return with the reason; nothing moves. 204, also when it was already refused. */
    public function refuse(Request $request, string $return): Response
    {
        $data = $request->validate(['reason' => ['required', 'string', 'min:3', 'max:500']]);
        $db = $this->db();
        $this->postings->reject($db, (string) $this->returnRequest($db, $return)->id, trim($data['reason']));

        return response()->noContent();
    }

    private function workOrder(EoraPostgresConnection $db, string $id): stdClass
    {
        return $db->selectOne('select id, plant_id, wo_no, status, routing_version_id from work_orders where id = ?', [strtolower($id)])
            ?? throw ValidationException::withMessages(['workOrderId' => 'Unknown work order.']);
    }

    /**
     * D-110: the Material at Production location of a work center of the WO's routing (M4-D3: one per work center), active
     * or not; a new issue checks it is active after its key is claimed.
     */
    private function productionLocation(EoraPostgresConnection $db, stdClass $wo, string $workCenterId): string
    {
        $row = $db->selectOne(
            "select l.id from locations l
              where l.plant_id = ? and l.location_type = 'MAT_AT_PROD' and l.work_center_id = ?
                and exists (select 1 from routing_operations o where o.routing_version_id = ?::uuid and o.work_center_id = l.work_center_id)",
            [$wo->plant_id, strtolower($workCenterId), $wo->routing_version_id],
        );

        return (string) ($row ?? throw ValidationException::withMessages(['workCenterId' => "Choose a work center of {$wo->wo_no}'s routing."]))->id;
    }

    /** What the WO has of the item and lot at that Material at Production location (BR-ISS-02). */
    private function atProduction(EoraPostgresConnection $db, string $wo, string $location, string $item, ?string $lot): string
    {
        $q = (string) $db->selectOne(
            "select coalesce(sum(qty_signed), 0)::text as q from inventory_movements
              where wo_id = ? and location_id = ? and item_id = ? and lot_id is not distinct from ?::uuid and stock_status = 'UNRESTRICTED'",
            [$wo, $location, $item, $lot],
        )->q;

        return is_numeric($q) ? $q : '0';
    }

    /** The return request, when the actor receives returns in its plant (404 for anything else). */
    private function returnRequest(EoraPostgresConnection $db, string $id): stdClass
    {
        $id = strtolower($id);
        $doc = preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/', $id) === 1
            ? $db->selectOne("select id, plant_id, status, request::text as request from inventory_documents where id = ? and doc_type = 'MAT_RETURN' and requested_by is not null", [$id])
            : null;
        if ($doc === null) {
            throw new DomainProblem(404, 'NOT_FOUND', 'Not found');
        }
        $this->plant($db, (string) $doc->plant_id, 'materials.return.post');

        return $doc;
    }

    private function issued(EoraPostgresConnection $db, PostingResult $result): JsonResponse
    {
        // the posting answer of doc 24 §5.3; an issue creates no lot
        return response()->json(['data' => $result->toArray() + ['replayed' => $result->replayed, 'lotId' => null, 'lotNo' => null]], $result->replayed ? 200 : 201);
    }

    private function trim(string $qty): string
    {
        return str_contains($qty, '.') ? (rtrim(rtrim($qty, '0'), '.') ?: '0') : $qty;
    }
}
