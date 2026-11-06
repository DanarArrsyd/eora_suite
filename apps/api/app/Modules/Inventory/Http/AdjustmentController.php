<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Http;

use App\Modules\Inventory\Application\InventoryPostingService;
use App\Modules\Inventory\Contracts\PostingCommand;
use App\Modules\Inventory\Contracts\PostingLeg;
use App\Modules\Inventory\Queries\StockQuery;
use App\Platform\Access\Authorizer;
use App\Platform\Database\EoraPostgresConnection;
use App\Platform\Exceptions\DomainProblem;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Stock adjustment (doc 04 row 12, D-060, D-100): one line per request — plus or minus at one location, item, lot and
 * status, with a reason code from the fixed M3 list — requested by inventory control and approved or rejected by a second
 * person. Nothing moves until it is approved; the stock is checked again then.
 */
final class AdjustmentController
{
    use PostingRequests;

    /** D-100: the M3 reason codes (tenant configuration later); OTHER needs a note that explains it. */
    public const array REASONS = ['COUNT_CORRECTION', 'DAMAGE', 'FOUND', 'OTHER'];

    public function __construct(
        private readonly Authorizer $authorizer,
        private readonly InventoryPostingService $postings,
    ) {}

    /**
     * Request an adjustment (Idempotency-Key header required). 201 with the submitted request, 200 when the same request
     * was submitted before under the key, 409 when the key was used for another request.
     */
    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'plantId' => ['required', 'uuid'],
            'itemId' => ['required', 'uuid'],
            'lotId' => ['nullable', 'uuid'],
            'locationId' => ['required', 'uuid'],
            'stockStatus' => ['required', Rule::in(StockQuery::STATUSES)],
            'direction' => ['required', Rule::in(['PLUS', 'MINUS'])],
            'qty' => ['required', 'string', 'regex:'.ReceiptController::QTY],
            'reasonCode' => ['required', Rule::in(AdjustmentController::REASONS)],
            'note' => ['nullable', 'string', 'max:500'],
        ]);
        $key = $this->key($request);
        $db = $this->db();
        $plant = $this->plant($db, $data['plantId'], 'inventory.adjustment.create');
        $item = strtolower($data['itemId']);
        $lot = isset($data['lotId']) ? strtolower($data['lotId']) : null;
        $location = strtolower($data['locationId']);
        $note = self::optionalText($data['note'] ?? null);
        if ($data['reasonCode'] === 'OTHER' && mb_strlen((string) $note) < 3) {
            throw ValidationException::withMessages(['note' => 'Explain the adjustment when the reason is Other.']);
        }
        $hash = hash('sha256', json_encode([$plant, $item, $lot, $location, $data['stockStatus'], $data['direction'], $data['qty'], $data['reasonCode'], $note], JSON_THROW_ON_ERROR));
        // a retried request answers with the request it submitted, whatever the stock or master data did since
        $previous = $this->postings->requested($db, $key, 'STOCK_ADJUSTMENT', $hash);
        if ($previous !== null) {
            return response()->json(['data' => ['documentId' => $previous, 'replayed' => true, 'status' => 'SUBMITTED']]);
        }

        $unit = $db->selectOne('select i.lot_controlled, u.code as uom, u.decimals from items i join uoms u on u.id = i.base_uom_id where i.id = ?', [$item])
            ?? throw ValidationException::withMessages(['itemId' => 'Unknown item.']);
        self::requireUnitPrecision($data['qty'], $unit, 'qty');
        if ((bool) $unit->lot_controlled && ($lot === null || $db->selectOne('select 1 as ok from lots where id = ? and item_id = ?', [$lot, $item]) === null)) {
            throw ValidationException::withMessages(['lotId' => 'This item is tracked by lot: choose one of its lots.']);
        }
        if (! (bool) $unit->lot_controlled && $lot !== null) {
            throw ValidationException::withMessages(['lotId' => 'This item is not tracked by lot. Leave the lot empty.']);
        }
        $db->selectOne("select 1 as ok from locations where id = ? and plant_id = ? and location_type not like 'V\\_%'", [$location, $plant])
            ?? throw ValidationException::withMessages(['locationId' => 'Choose a location of this plant that holds stock.']);
        $virtual = (string) ($db->selectOne("select id from locations where plant_id = ? and location_type = 'V_ADJUSTMENT'", [$plant])
            ?? throw new DomainProblem(409, 'NO_ADJUSTMENT_LOCATION', 'No adjustment location', 'This plant has no adjustment location.'))->id;
        if ($data['direction'] === 'MINUS') {
            // checked again at approval; asked now so a request for stock that is not there is not left to the approver
            $onHand = (string) ($db->selectOne(
                'select trim_scale(qty)::text as q from stock_balances where location_id = ? and item_id = ? and lot_id is not distinct from ?::uuid and stock_status = ?',
                [$location, $item, $lot, $data['stockStatus']],
            )->q ?? '0');
            $qty = (string) $data['qty'];
            if (! is_numeric($onHand) || ! is_numeric($qty) || bccomp($onHand, $qty, 6) < 0) {
                throw ValidationException::withMessages(['qty' => "Only {$onHand} {$unit->uom} is on hand there."]);
            }
        }

        [$code, $from, $to] = $data['direction'] === 'PLUS' ? ['ADJ_PLUS', $virtual, $location] : ['ADJ_MINUS', $location, $virtual];
        $result = $this->postings->submit($db, new PostingCommand('STOCK_ADJUSTMENT', $plant, $key, [
            new PostingLeg($code, $from, $item, $lot, $data['stockStatus'], '-'.$data['qty']),
            new PostingLeg($code, $to, $item, $lot, $data['stockStatus'], $data['qty']),
        ], note: $note, requestHash: $hash), $data['reasonCode']);

        return response()->json(['data' => $result + ['status' => 'SUBMITTED']], $result['replayed'] ? 200 : 201);
    }

    /**
     * Approve a submitted adjustment (Idempotency-Key header required): it is posted now, by the approver, never by its
     * requester. 201 when posted, 200 when this key already posted it.
     */
    public function approve(Request $request, string $adjustment): JsonResponse
    {
        $key = $this->key($request);
        $db = $this->db();
        $id = $this->decided($db, $adjustment);

        return $this->answer($db, $this->postings->approve($db, $id, $key));
    }

    /** Reject a submitted adjustment with the reason; nothing moves. 204, also when it was already rejected. */
    public function reject(Request $request, string $adjustment): Response
    {
        $data = $request->validate(['reason' => ['required', 'string', 'min:3', 'max:500']]);
        $db = $this->db();
        $this->postings->reject($db, $this->decided($db, $adjustment), trim($data['reason']));

        return response()->noContent();
    }

    /** The adjustment request, when the actor approves adjustments in its plant (404 for anything else). */
    private function decided(EoraPostgresConnection $db, string $id): string
    {
        $id = strtolower($id);
        $doc = preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/', $id) === 1
            ? $db->selectOne("select plant_id from inventory_documents where id = ? and doc_type = 'STOCK_ADJUSTMENT'", [$id])
            : null;
        if ($doc === null) {
            throw new DomainProblem(404, 'NOT_FOUND', 'Not found');
        }
        $this->plant($db, (string) $doc->plant_id, 'inventory.adjustment.approve');

        return $id;
    }
}
