<?php

declare(strict_types=1);

namespace App\Modules\Warehouse\Http;

use App\Modules\Inventory\Application\InventoryPostingService;
use App\Modules\Inventory\Contracts\PostingCommand;
use App\Modules\Inventory\Contracts\PostingLeg;
use App\Modules\Inventory\Http\PostingRequests;
use App\Modules\Inventory\Http\ReceiptController;
use App\Platform\Access\Authorizer;
use App\Platform\Exceptions\DomainProblem;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use LogicException;

/**
 * Warehouse → FG receipt (doc 33 §1.6, D-114, doc 04 row 10, BR-FG-01, doc 14 #7).
 *
 * The FG warehouse (`warehouse.fg_receipt.post`, FGW) receives QC-released finished goods from the QC area into one FG
 * warehouse location of its choice as UNRESTRICTED, posted directly. One document holds several lots, each part or all
 * of what was released: the counted quantity is received, the rest stays QC_RELEASED for a later receipt. A wrong
 * receipt is corrected through the M3 reversal flow.
 */
final class FgReceiptController
{
    use PostingRequests;

    public function __construct(
        private readonly Authorizer $authorizer,
        private readonly InventoryPostingService $postings,
    ) {}

    /**
     * Receive QC-released finished goods into the FG warehouse (Idempotency-Key header required). 201 when posted, 200
     * when the same request was posted before under the key, 409 when the key was used for another request.
     */
    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'plantId' => ['required', 'uuid'],
            // the FG warehouse location received into; the first active one of the plant when omitted
            'locationId' => ['nullable', 'uuid'],
            'lines' => ['required', 'array', 'min:1', 'max:20'],
            'lines.*.stockLineId' => ['required', 'uuid', 'distinct:ignore_case'],
            'lines.*.qty' => ['required', 'string', 'regex:'.ReceiptController::QTY],
            'note' => ['nullable', 'string', 'max:500'],
        ]);
        $key = $this->key($request);
        $db = $this->db();
        $plant = $this->plant($db, $data['plantId'], 'warehouse.fg_receipt.post');
        $hash = hash('sha256', json_encode(['FG_RECEIPT', $plant, $data], JSON_THROW_ON_ERROR));
        // a retried request answers with what it posted, whatever the stock did since
        $previous = $this->postings->claim($db, $key, $hash);
        if ($previous !== null) {
            return $this->answer($db, $previous);
        }

        $location = isset($data['locationId'])
            ? $db->selectOne("select id from locations where id = ? and plant_id = ? and location_type = 'FG_WAREHOUSE' and status = 'ACTIVE' for share", [strtolower($data['locationId']), $plant])
            : $db->selectOne("select id from locations where plant_id = ? and location_type = 'FG_WAREHOUSE' and status = 'ACTIVE' order by code limit 1 for share", [$plant]);
        $to = (string) ($location ?? throw ValidationException::withMessages(['locationId' => 'Choose an active FG warehouse location of this plant.']))->id;

        $legs = [];
        foreach (array_values($data['lines']) as $i => $l) {
            $line = $db->selectOne(
                "select b.location_id, b.item_id, b.lot_id, trim_scale(b.qty)::text as qty, i.code as item_code, u.code as uom, u.decimals
                   from stock_balances b join locations l on l.id = b.location_id join items i on i.id = b.item_id join uoms u on u.id = i.base_uom_id
                  where b.id = ? and b.plant_id = ? and l.location_type = 'QC_AREA' and b.stock_status = 'QC_RELEASED' and i.item_type = 'FINISHED'",
                [strtolower($l['stockLineId']), $plant],
            ) ?? throw ValidationException::withMessages(["lines.{$i}.stockLineId" => 'Choose QC-released finished goods at the QC area of this plant.']);
            self::requireUnitPrecision($l['qty'], $line, "lines.{$i}.qty");
            if (bccomp(self::num($l['qty']), '0', 6) <= 0) {
                throw ValidationException::withMessages(["lines.{$i}.qty" => 'Enter the counted quantity, above zero.']);
            }
            if (bccomp(self::num((string) $line->qty), self::num($l['qty']), 6) < 0) {
                throw new DomainProblem(409, 'INSUFFICIENT_STOCK', 'Insufficient stock', "Only {$line->qty} {$line->uom} of {$line->item_code} is QC-released there.");
            }
            $legs[] = new PostingLeg('FG_RECEIPT', (string) $line->location_id, (string) $line->item_id, $line->lot_id, 'QC_RELEASED', '-'.$l['qty']);
            $legs[] = new PostingLeg('FG_RECEIPT', $to, (string) $line->item_id, $line->lot_id, 'UNRESTRICTED', $l['qty']);
        }

        return $this->answer($db, $this->postings->post($db, new PostingCommand('FG_RECEIPT', $plant, $key, $legs,
            note: self::optionalText($data['note'] ?? null), requestHash: $hash)));
    }

    /** @return numeric-string */
    private static function num(mixed $value): string
    {
        return is_string($value) && is_numeric($value) ? $value : throw new LogicException('Not a quantity.');
    }
}
