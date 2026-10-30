<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Http;

use App\Modules\Inventory\Application\InventoryPostingService;
use App\Modules\Inventory\Application\LotNumbers;
use App\Modules\Inventory\Contracts\PostingCommand;
use App\Modules\Inventory\Contracts\PostingLeg;
use App\Modules\Inventory\Contracts\PostingResult;
use App\Platform\Access\Authorizer;
use App\Platform\Access\Scope;
use App\Platform\Calendar\BusinessCalendar;
use App\Platform\Database\EoraPostgresConnection;
use App\Platform\Exceptions\DomainProblem;
use DateTimeImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use LogicException;

/**
 * Raw-material receipt (doc 04 row 1, M3-D3/D4) and incoming release (D-062). Quantities are in the item's base unit
 * (M3-D5); every request carries an Idempotency-Key, so a retried request answers with the document it posted.
 */
final class ReceiptController
{
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
        $item = $db->selectOne('select id, status, item_type, lot_controlled, incoming_release_required from items where id = ?', [strtolower($data['itemId'])])
            ?? throw ValidationException::withMessages(['itemId' => 'Unknown item.']);
        if ($item->status !== 'ACTIVE') {
            throw ValidationException::withMessages(['itemId' => 'Only active items are received.']);
        }
        if (! in_array($item->item_type, self::RECEIVED_TYPES, true)) {
            throw ValidationException::withMessages(['itemId' => 'A supplier receipt takes raw material or consumables.']);
        }
        $supplier = $db->selectOne("select id from suppliers where id = ? and status = 'ACTIVE'", [strtolower($data['supplierId'])])
            ?? throw ValidationException::withMessages(['supplierId' => 'Unknown or inactive supplier.']);
        $location = $this->rmLocation($db, $plant, $data['locationId'], 'locationId');
        $supplierLot = self::optionalText($data['supplierLotNo'] ?? null);
        $note = self::optionalText($data['note'] ?? null);
        $hash = hash('sha256', json_encode(['RM_RECEIPT', $plant, (string) $item->id, $location, (string) $supplier->id, $data['qty'], $supplierLot, $note, $data['effectiveDate'] ?? null], JSON_THROW_ON_ERROR));

        $previous = $this->postings->previous($db, $key, $hash);
        if ($previous !== null) {
            return $this->answer($db, $previous);
        }
        $lot = null;
        if ((bool) $item->lot_controlled) {
            $day = $data['effectiveDate'] ?? $this->calendar->resolve($db, $plant, new DateTimeImmutable((string) $db->selectOne('select now()::text as t')->t))->productionDate;
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
        ], effectiveDate: $data['effectiveDate'] ?? null, note: $note, sourceType: 'supplier', sourceId: (string) $supplier->id, requestHash: $hash)));
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
        foreach ($data['lines'] as $i => $line) {
            $location = $this->rmLocation($db, $plant, $line['locationId'], "lines.{$i}.locationId");
            $legs[] = new PostingLeg('INCOMING_RELEASE', $location, $item, $lot, 'BLOCKED', '-'.$line['qty']);
            $legs[] = new PostingLeg('INCOMING_RELEASE', $location, $item, $lot, 'UNRESTRICTED', $line['qty']);
        }

        return $this->answer($db, $this->postings->post($db, new PostingCommand('INCOMING_RELEASE', $plant, $key, $legs, note: self::optionalText($data['note'] ?? null))));
    }

    private function db(): EoraPostgresConnection
    {
        $db = DB::connection();

        return $db instanceof EoraPostgresConnection ? $db : throw new LogicException('Postings require the EORA PostgreSQL connection.');
    }

    private static function optionalText(mixed $value): ?string
    {
        $text = is_string($value) ? trim($value) : null;

        return $text === null || $text === '' ? null : $text;
    }

    private function key(Request $request): string
    {
        $key = (string) $request->header('Idempotency-Key', '');
        if (preg_match('/^[A-Za-z0-9._:-]{8,200}$/', $key) !== 1) {
            throw new DomainProblem(400, 'IDEMPOTENCY_KEY_REQUIRED', 'Idempotency key required', 'Send an Idempotency-Key header (8–200 letters, digits, . _ : -).');
        }

        return $key;
    }

    private function plant(EoraPostgresConnection $db, string $plantId, string $permission): string
    {
        $this->authorizer->requireAnywhere($db, $permission);
        $plant = strtolower($plantId);
        if ($db->selectOne('select 1 as ok from plants where id = ?', [$plant]) === null) {
            throw ValidationException::withMessages(['plantId' => 'Unknown plant.']);
        }
        $this->authorizer->authorize($db, $permission, Scope::plant($plant, (string) $db->selectOne('select company_id from plants where id = ?', [$plant])->company_id));

        return $plant;
    }

    private function rmLocation(EoraPostgresConnection $db, string $plant, string $id, string $field): string
    {
        $row = $db->selectOne("select id from locations where id = ? and plant_id = ? and location_type = 'RM_WAREHOUSE' and status = 'ACTIVE'", [strtolower($id), $plant])
            ?? throw ValidationException::withMessages([$field => 'Choose an active raw-material location of this plant.']);

        return (string) $row->id;
    }

    private function answer(EoraPostgresConnection $db, PostingResult $result): JsonResponse
    {
        $lot = $db->selectOne('select l.id, l.lot_no from inventory_movements m join lots l on l.id = m.lot_id where m.document_id = ? limit 1', [$result->documentId]);

        return response()->json(['data' => $result->toArray() + ['replayed' => $result->replayed, 'lotId' => $lot?->id, 'lotNo' => $lot?->lot_no]], $result->replayed ? 200 : 201);
    }
}
