<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Http;

use App\Modules\Inventory\Application\InventoryPostingService;
use App\Modules\Inventory\Application\LotNumbers;
use App\Modules\Inventory\Contracts\PostingCommand;
use App\Modules\Inventory\Contracts\PostingLeg;
use App\Platform\Access\Authorizer;
use App\Platform\Calendar\BusinessCalendar;
use App\Platform\Database\EoraPostgresConnection;
use App\Platform\Exceptions\DomainProblem;
use DateTimeImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

/**
 * Raw-material receipt (doc 04 row 1, M3-D3/D4) and incoming release (D-062). Quantities are in the item's base unit
 * (M3-D5); every request carries an Idempotency-Key, so a retried request answers with the document it posted.
 */
final class ReceiptController
{
    use PostingRequests;

    /** D-097: purchased material only; semi-finished from a subcontractor comes with its own flow. */
    private const array RECEIVED_TYPES = ['RAW', 'CONSUMABLE'];

    public const string QTY = '/^(?!0+(\.0+)?$)\d{1,12}(\.\d{1,6})?$/';

    public function __construct(
        private readonly Authorizer $authorizer,
        private readonly InventoryPostingService $postings,
        private readonly LotNumbers $lots,
        private readonly BusinessCalendar $calendar,
    ) {}

    /**
     * Receive raw material from a supplier (Idempotency-Key header required). A lot-controlled item gets a new internal
     * lot; an item that needs its incoming release is received BLOCKED. 201 when posted, 200 when the same request was
     * posted before under the key, 409 when the key was used for another request.
     */
    public function receive(Request $request): JsonResponse
    {
        $data = $request->validate([
            'plantId' => ['required', 'uuid'],
            'itemId' => ['required', 'uuid'],
            'locationId' => ['required', 'uuid'],
            'supplierId' => ['required', 'uuid'],
            'qty' => ['required', 'string', 'regex:'.self::QTY],
            'supplierLotNo' => ['nullable', 'string', 'max:60'],
            'note' => ['nullable', 'string', 'max:500'],
            'effectiveDate' => ['nullable', 'date_format:Y-m-d'],
        ]);
        $key = $this->key($request);
        $db = $this->db();
        $plant = $this->plant($db, $data['plantId'], 'materials.receipt.post');
        $itemId = strtolower($data['itemId']);
        $supplierId = strtolower($data['supplierId']);
        $locationId = strtolower($data['locationId']);
        $supplierLot = self::optionalText($data['supplierLotNo'] ?? null);
        $note = self::optionalText($data['note'] ?? null);
        $hash = hash('sha256', json_encode(['RM_RECEIPT', $plant, $itemId, $locationId, $supplierId, $data['qty'], $supplierLot, $note, $data['effectiveDate'] ?? null], JSON_THROW_ON_ERROR));

        // a retried request answers with what it posted, whatever master data changed since; a concurrent one waits
        $previous = $this->postings->claim($db, $key, $hash);
        if ($previous !== null) {
            return $this->answer($db, $previous);
        }
        $item = $db->selectOne('select i.id, i.status, i.item_type, i.lot_controlled, i.incoming_release_required, u.code as uom, u.decimals from items i join uoms u on u.id = i.base_uom_id where i.id = ?', [$itemId])
            ?? throw ValidationException::withMessages(['itemId' => 'Unknown item.']);
        if ($item->status !== 'ACTIVE') {
            throw ValidationException::withMessages(['itemId' => 'Only active items are received.']);
        }
        if (! in_array($item->item_type, self::RECEIVED_TYPES, true)) {
            throw ValidationException::withMessages(['itemId' => 'A supplier receipt takes raw material or consumables.']);
        }
        self::requireUnitPrecision($data['qty'], $item, 'qty');
        // a supplier lot is kept on the lot; an item without lots has nowhere to keep it, so it is refused, never dropped
        if (! (bool) $item->lot_controlled && $supplierLot !== null) {
            throw ValidationException::withMessages(['supplierLotNo' => 'This item is not tracked by lot. Leave the supplier lot or heat number empty.']);
        }
        $supplier = $db->selectOne("select id from suppliers where id = ? and status = 'ACTIVE'", [$supplierId])
            ?? throw ValidationException::withMessages(['supplierId' => 'Unknown or inactive supplier.']);
        $location = $this->rmLocation($db, $plant, $locationId, 'locationId');
        // the day the receipt belongs to, resolved once: its lot number and its posting use the same day
        $day = $data['effectiveDate'] ?? $this->calendar->resolve($db, $plant, new DateTimeImmutable((string) $db->selectOne('select now()::text as t')->t))->productionDate;
        $lot = null;
        if ((bool) $item->lot_controlled) {
            $lot = (string) $db->selectOne(
                "insert into lots (tenant_id, item_id, lot_no, supplier_lot_no, origin) values ((select c.tenant from ctx_api.context() c), ?, ?, ?, 'RECEIPT') returning id",
                [$item->id, $this->lots->next($db, (string) $item->id, $day), $supplierLot],
            )->id;
        }
        $status = (bool) $item->incoming_release_required ? 'BLOCKED' : 'UNRESTRICTED';
        $from = (string) ($db->selectOne("select id from locations where plant_id = ? and location_type = 'V_SUPPLIER'", [$plant])
            ?? throw new DomainProblem(409, 'NO_SUPPLIER_LOCATION', 'No supplier location', 'This plant has no supplier location.'))->id;

        return $this->answer($db, $this->postings->post($db, new PostingCommand('RM_RECEIPT', $plant, $key, [
            new PostingLeg('RCPT_SUPPLIER', $from, (string) $item->id, $lot, $status, '-'.$data['qty']),
            new PostingLeg('RCPT_SUPPLIER', $location, (string) $item->id, $lot, $status, $data['qty']),
        ], effectiveDate: $day, note: $note, sourceType: 'supplier', sourceId: (string) $supplier->id, requestHash: $hash)));
    }

    /**
     * Release BLOCKED raw material to UNRESTRICTED in place, one line per rack (Idempotency-Key header required).
     */
    public function release(Request $request): JsonResponse
    {
        $data = $request->validate([
            'plantId' => ['required', 'uuid'],
            'itemId' => ['required', 'uuid'],
            'lotId' => ['nullable', 'uuid'],
            'lines' => ['required', 'array', 'min:1', 'max:50'],
            'lines.*.locationId' => ['required', 'uuid', 'distinct'],
            'lines.*.qty' => ['required', 'string', 'regex:'.self::QTY],
            'note' => ['nullable', 'string', 'max:500'],
        ]);
        $key = $this->key($request);
        $db = $this->db();
        $plant = $this->plant($db, $data['plantId'], 'materials.incoming_release.post');
        $item = strtolower($data['itemId']);
        $lot = isset($data['lotId']) ? strtolower($data['lotId']) : null;
        $legs = [];
        foreach ($data['lines'] as $line) {
            $location = strtolower($line['locationId']);
            $legs[] = new PostingLeg('INCOMING_RELEASE', $location, $item, $lot, 'BLOCKED', '-'.$line['qty']);
            $legs[] = new PostingLeg('INCOMING_RELEASE', $location, $item, $lot, 'UNRESTRICTED', $line['qty']);
        }
        $command = new PostingCommand('INCOMING_RELEASE', $plant, $key, $legs, note: self::optionalText($data['note'] ?? null));
        // a retried request answers with what it posted, whatever master data changed since
        $previous = $this->postings->claim($db, $key, $command->hash());
        if ($previous !== null) {
            return $this->answer($db, $previous);
        }
        $unit = $db->selectOne('select u.code as uom, u.decimals from items i join uoms u on u.id = i.base_uom_id where i.id = ?', [$item])
            ?? throw ValidationException::withMessages(['itemId' => 'Unknown item.']);
        foreach ($data['lines'] as $i => $line) {
            $this->rmLocation($db, $plant, $line['locationId'], "lines.{$i}.locationId");
            self::requireUnitPrecision($line['qty'], $unit, "lines.{$i}.qty");
        }

        return $this->answer($db, $this->postings->post($db, $command));
    }

    private function rmLocation(EoraPostgresConnection $db, string $plant, string $id, string $field): string
    {
        $row = $db->selectOne("select id from locations where id = ? and plant_id = ? and location_type = 'RM_WAREHOUSE' and status = 'ACTIVE'", [strtolower($id), $plant])
            ?? throw ValidationException::withMessages([$field => 'Choose an active raw-material location of this plant.']);

        return (string) $row->id;
    }
}
