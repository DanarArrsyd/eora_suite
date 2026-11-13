<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Http;

use App\Modules\Inventory\Application\InventoryPostingService;
use App\Modules\Inventory\Contracts\PostingCommand;
use App\Modules\Inventory\Contracts\PostingLeg;
use App\Modules\Inventory\Queries\StockQuery;
use App\Platform\Access\Authorizer;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Material transfer within a plant (doc 04 row 4, D-048, M3-D6): the same item, lot and status move from one location
 * to another in one step. D-098: only between locations of the same kind (rack to rack, finished goods to finished
 * goods, work center to work center); issue, return, QC dispositions and FG receipt have their own documents (M4).
 */
final class TransferController
{
    use PostingRequests;

    public function __construct(
        private readonly Authorizer $authorizer,
        private readonly InventoryPostingService $postings,
    ) {}

    /**
     * Move stock to another location of the same kind in the plant (Idempotency-Key header required). 201 when posted,
     * 200 when the same request was posted before under the key, 409 when the key was used for another request.
     */
    public function transfer(Request $request): JsonResponse
    {
        $data = $request->validate([
            'plantId' => ['required', 'uuid'],
            'itemId' => ['required', 'uuid'],
            'lotId' => ['nullable', 'uuid'],
            'stockStatus' => ['required', Rule::in(StockQuery::STATUSES)],
            'fromLocationId' => ['required', 'uuid'],
            'toLocationId' => ['required', 'uuid', 'different:fromLocationId'],
            'qty' => ['required', 'string', 'regex:'.ReceiptController::QTY],
            'note' => ['nullable', 'string', 'max:500'],
        ]);
        $key = $this->key($request);
        $db = $this->db();
        $plant = $this->plant($db, $data['plantId'], 'materials.transfer.post');
        $item = strtolower($data['itemId']);
        $lot = isset($data['lotId']) ? strtolower($data['lotId']) : null;
        $from = strtolower($data['fromLocationId']);
        $to = strtolower($data['toLocationId']);
        $command = new PostingCommand('MAT_TRANSFER', $plant, $key, [
            new PostingLeg('TRANSFER_LOC', $from, $item, $lot, $data['stockStatus'], '-'.$data['qty']),
            new PostingLeg('TRANSFER_LOC', $to, $item, $lot, $data['stockStatus'], $data['qty']),
        ], note: self::optionalText($data['note'] ?? null));
        // a retried request answers with what it posted, whatever master data changed since
        $previous = $this->postings->claim($db, $key, $command->hash());
        if ($previous !== null) {
            return $this->answer($db, $previous);
        }

        $unit = $db->selectOne('select u.code as uom, u.decimals from items i join uoms u on u.id = i.base_uom_id where i.id = ?', [$item])
            ?? throw ValidationException::withMessages(['itemId' => 'Unknown item.']);
        self::requireUnitPrecision($data['qty'], $unit, 'qty');
        // stock may leave a location that no longer takes new stock; it only enters an active one
        $source = $db->selectOne("select location_type from locations where id = ? and plant_id = ? and location_type not like 'V\\_%'", [$from, $plant])
            ?? throw ValidationException::withMessages(['fromLocationId' => 'Choose a location of this plant that holds stock.']);
        // locked until the posting commits, so the location cannot be deactivated in between
        $target = $db->selectOne("select location_type from locations where id = ? and plant_id = ? and status = 'ACTIVE' for share", [$to, $plant])
            ?? throw ValidationException::withMessages(['toLocationId' => 'Choose an active location of this plant.']);
        // M4-D3: material at a work center belongs to a work order; it leaves by a return (and a new issue), not a transfer
        if ($source->location_type === 'MAT_AT_PROD') {
            throw ValidationException::withMessages(['fromLocationId' => 'Material at a work center belongs to a work order: return it, then issue it where it is needed.']);
        }
        if ($target->location_type !== $source->location_type) {
            throw ValidationException::withMessages(['toLocationId' => 'A transfer stays between locations of the same kind. Issue to production, returns, QC and finished-goods receipt have their own documents.']);
        }

        return $this->answer($db, $this->postings->post($db, $command));
    }
}
