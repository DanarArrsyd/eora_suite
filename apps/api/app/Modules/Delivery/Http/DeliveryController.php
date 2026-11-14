<?php

declare(strict_types=1);

namespace App\Modules\Delivery\Http;

use App\Modules\Inventory\Application\InventoryPostingService;
use App\Modules\Inventory\Contracts\PostingCommand;
use App\Modules\Inventory\Contracts\PostingLeg;
use App\Modules\Inventory\Http\PostingRequests;
use App\Modules\Inventory\Http\ReceiptController;
use App\Platform\Access\Authorizer;
use App\Platform\Access\Scope;
use App\Platform\Audit\AuditRecorder;
use App\Platform\Database\EoraPostgresConnection;
use App\Platform\Exceptions\DomainProblem;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Validation\ValidationException;
use LogicException;
use stdClass;

/**
 * Delivery → FG allocation and pick (doc 33 §1.7, D-115, doc 04 rows 2 and 11, BR-FG-02, BR-DEM-09/10).
 *
 * - Proposal: the oldest available FG lots first (FIFO by lot, then location), up to what the schedule line still needs.
 * - Allocation (`delivery.shipment.create`, DLV, Idempotency-Key): reserves UNRESTRICTED FG of FG warehouse locations for
 *   one firm schedule line; on-hand stays, available drops. Released by delivery with the reason.
 * - Pick (`delivery.shipment.post`, FGW, Idempotency-Key): allocated FG moves to a staging location, part or all of each
 *   allocation (the rest stays allocated). Unpick: staged goods of a schedule line go back to an FG rack, unallocated.
 */
final class DeliveryController
{
    use PostingRequests;

    public function __construct(
        private readonly Authorizer $authorizer,
        private readonly InventoryPostingService $postings,
        private readonly AuditRecorder $audit,
    ) {}

    /** One schedule line for delivery: what it requires and has allocated, staged and delivered, with its allocations and staged goods. */
    public function show(string $line): JsonResponse
    {
        $db = $this->db();
        $s = $this->scheduleLine($db, $line, 'delivery.shipment.view');
        $head = $db->selectOne(
            'select s.id, s.status, s.requirement_date::text as requirement_date, s.schedule_no, h.id as so_id, h.so_no, c.code as customer_code, c.name as customer_name,
                    ol.line_no, ol.customer_po_no, i.code as item_code, i.name as item_name, p.code as plant_code
               from delivery_schedule_lines s join sales_order_lines ol on ol.id = s.so_line_id join sales_order_headers h on h.id = ol.sales_order_id
               join customers c on c.id = ol.customer_id join items i on i.id = ol.item_id join plants p on p.id = s.plant_id where s.id = ?',
            [$s->id],
        );
        $sum = fn (string $where) => self::plain(self::num((string) $db->selectOne(
            "select coalesce(sum(m.qty_signed), 0)::text as q from inventory_movements m join locations l on l.id = m.location_id where m.schedule_line_id = ? and {$where}", [$s->id])->q));
        $allocations = $db->select(
            "select a.id, a.balance_id, l.code as location_code, lt.lot_no, trim_scale(a.qty)::text as qty, a.created_at
               from stock_allocations a join stock_balances b on b.id = a.balance_id join locations l on l.id = b.location_id left join lots lt on lt.id = b.lot_id
              where a.demand_type = 'SCHEDULE_LINE' and a.demand_id = ? and a.status = 'ACTIVE' order by lt.created_at nulls last, l.code",
            [$s->id],
        );
        $staged = $db->select(
            "select b.id, l.code as location_code, lt.lot_no, trim_scale(sum(m.qty_signed))::text as qty
               from inventory_movements m join locations l on l.id = m.location_id and l.location_type = 'STAGING'
               join stock_balances b on b.location_id = m.location_id and b.item_id = m.item_id and b.lot_id is not distinct from m.lot_id and b.stock_status = 'UNRESTRICTED'
               left join lots lt on lt.id = m.lot_id
              where m.schedule_line_id = ? group by b.id, l.code, lt.lot_no having sum(m.qty_signed) > 0 order by l.code, lt.lot_no",
            [$s->id],
        );
        $allocated = (string) $db->selectOne("select trim_scale(coalesce(sum(qty), 0))::text as q from stock_allocations where demand_type = 'SCHEDULE_LINE' and demand_id = ? and status = 'ACTIVE'", [$s->id])->q;

        return response()->json(['data' => [
            'id' => (string) $s->id, 'status' => (string) $head->status, 'plantId' => (string) $s->plant_id, 'plantCode' => (string) $head->plant_code,
            'salesOrderId' => (string) $head->so_id, 'soNo' => (string) $head->so_no, 'lineNo' => (int) $head->line_no, 'scheduleNo' => (int) $head->schedule_no,
            'customerPoNo' => $head->customer_po_no, 'customerCode' => (string) $head->customer_code, 'customerName' => (string) $head->customer_name,
            'itemId' => (string) $s->item_id, 'itemCode' => (string) $head->item_code, 'itemName' => (string) $head->item_name, 'requirementDate' => (string) $head->requirement_date,
            'requiredQty' => (string) $s->required, 'allocatedQty' => $allocated, 'stagedQty' => $sum("l.location_type = 'STAGING'"),
            'deliveredQty' => $sum("l.location_type = 'V_CUSTOMER'"), 'toAllocateQty' => self::plain($this->toAllocate($db, $s)),
            'uomCode' => (string) $s->uom, 'uomDecimals' => (int) $s->decimals,
            'allocations' => array_map(static fn (stdClass $a) => ['id' => (string) $a->id, 'stockLineId' => (string) $a->balance_id, 'locationCode' => (string) $a->location_code,
                'lotNo' => $a->lot_no, 'qty' => (string) $a->qty, 'createdAt' => (string) $a->created_at], $allocations),
            'staged' => array_map(static fn (stdClass $b) => ['stockLineId' => (string) $b->id, 'locationCode' => (string) $b->location_code, 'lotNo' => $b->lot_no,
                'qty' => (string) $b->qty], $staged),
        ]]);
    }

    /** What the schedule line still needs and the FIFO proposal of available FG to allocate for it. */
    public function proposal(string $line): JsonResponse
    {
        $db = $this->db();
        $s = $this->scheduleLine($db, $line, 'delivery.shipment.view');
        $need = $this->toAllocate($db, $s);
        $rows = $db->select(
            "select b.id, b.location_id, l.code as location_code, b.lot_id, lt.lot_no,
                    trim_scale(b.qty - coalesce((select sum(a.qty) from stock_allocations a where a.balance_id = b.id and a.status = 'ACTIVE'), 0))::text as available
               from stock_balances b join locations l on l.id = b.location_id left join lots lt on lt.id = b.lot_id
              where b.plant_id = ? and b.item_id = ? and b.stock_status = 'UNRESTRICTED' and l.location_type = 'FG_WAREHOUSE' and l.status = 'ACTIVE'
                and b.qty > coalesce((select sum(a.qty) from stock_allocations a where a.balance_id = b.id and a.status = 'ACTIVE'), 0)
              order by lt.created_at nulls last, lt.lot_no nulls last, l.code",
            [$s->plant_id, $s->item_id],
        );
        $left = $need;
        $lines = [];
        foreach ($rows as $r) {
            $take = bccomp($left, self::num((string) $r->available), 6) < 0 ? $left : self::num((string) $r->available);
            $lines[] = [
                'stockLineId' => (string) $r->id, 'locationId' => (string) $r->location_id, 'locationCode' => (string) $r->location_code,
                'lotId' => $r->lot_id, 'lotNo' => $r->lot_no, 'availableQty' => (string) $r->available,
                'proposedQty' => self::plain(bccomp($take, '0', 6) > 0 ? $take : '0'),
            ];
            $left = bccomp($left, $take, 6) > 0 ? bcsub($left, $take, 6) : '0';
        }

        return response()->json(['data' => ['scheduleLineId' => (string) $s->id, 'toAllocate' => self::plain($need), 'uomCode' => (string) $s->uom, 'lines' => $lines]]);
    }

    /**
     * Allocate available FG to one firm schedule line (Idempotency-Key header required): 201 with the allocations, 200 when
     * the same request was made before under the key, 409 when the key was used for another request.
     */
    public function allocate(Request $request): JsonResponse
    {
        $data = $request->validate([
            'scheduleLineId' => ['required', 'uuid'],
            'lines' => ['required', 'array', 'min:1', 'max:20'],
            'lines.*.stockLineId' => ['required', 'uuid', 'distinct:ignore_case'],
            'lines.*.qty' => ['required', 'string', 'regex:'.ReceiptController::QTY],
        ]);
        $key = $this->key($request);
        $db = $this->db();
        $s = $this->scheduleLine($db, $data['scheduleLineId'], 'delivery.shipment.create');
        $hash = hash('sha256', json_encode(['delivery.allocate', $data], JSON_THROW_ON_ERROR));
        $result = $this->postings->once($db, $key, 'delivery.allocate', $hash, function () use ($db, $data, $s): array {
            // held until commit: the schedule line keeps its status and what it needs
            $line = $db->selectOne('select status from delivery_schedule_lines where id = ? for update', [$s->id]);
            if (! in_array($line?->status, ['FIRM', 'PARTIALLY_FULFILLED'], true)) {
                throw new DomainProblem(409, 'INVALID_STATE_TRANSITION', 'Schedule line not open', 'Only a firm schedule line still to deliver gets FG allocated.');
            }
            $left = $this->toAllocate($db, $s);
            $ids = [];
            foreach (array_values($data['lines']) as $i => $l) {
                $b = $db->selectOne(
                    "select b.id, trim_scale(b.qty)::text as qty from stock_balances b join locations l on l.id = b.location_id
                      where b.id = ? and b.plant_id = ? and b.item_id = ? and b.stock_status = 'UNRESTRICTED' and l.location_type = 'FG_WAREHOUSE' for update of b",
                    [strtolower($l['stockLineId']), $s->plant_id, $s->item_id],
                ) ?? throw ValidationException::withMessages(["lines.{$i}.stockLineId" => "Choose unrestricted {$s->item_code} in an FG warehouse location of this plant."]);
                self::requireUnitPrecision($l['qty'], $s, "lines.{$i}.qty");
                $qty = self::num($l['qty']);
                if (bccomp($qty, '0', 6) <= 0) {
                    throw ValidationException::withMessages(["lines.{$i}.qty" => 'Enter a quantity above zero.']);
                }
                $allocated = self::num((string) $db->selectOne("select coalesce(sum(qty), 0)::text as q from stock_allocations where balance_id = ? and status = 'ACTIVE'", [$b->id])->q);
                $available = bcsub(self::num((string) $b->qty), $allocated, 6);
                if (bccomp($qty, $available, 6) > 0) {
                    throw new DomainProblem(409, 'INSUFFICIENT_AVAILABLE', 'Not available', 'Only '.self::plain($available)." {$s->uom} is available there.");
                }
                if (bccomp($qty, $left, 6) > 0) {
                    throw new DomainProblem(409, 'OVER_ALLOCATION', 'More than needed', "The schedule line needs only {$this->plainOf($left)} {$s->uom} more (allocated, staged and delivered count).");
                }
                $left = bcsub($left, $qty, 6);
                $id = (string) $db->selectOne(
                    "insert into stock_allocations (tenant_id, balance_id, demand_type, demand_id, qty) values ((select c.tenant from ctx_api.context() c), ?, 'SCHEDULE_LINE', ?, ?) returning id",
                    [$b->id, $s->id, $qty],
                )->id;
                $this->audit->record($db, 'stock_allocation.created', 'stock_allocation', $id, null, ['balanceId' => (string) $b->id, 'scheduleLineId' => (string) $s->id, 'qty' => $qty]);
                $ids[] = $id;
            }

            return ['scheduleLineId' => (string) $s->id, 'allocationIds' => $ids];
        });

        return response()->json(['data' => $result['response'] + ['replayed' => $result['replayed']]], $result['replayed'] ? 200 : 201);
    }

    /** Release an active allocation with the reason (delivery): available again, nothing moves. 204, also when already released. */
    public function release(Request $request, string $allocation): Response
    {
        $data = $request->validate(['reason' => ['required', 'string', 'min:3', 'max:500']]);
        $db = $this->db();
        $a = $this->allocation($db, $allocation, 'delivery.shipment.create');
        if ($a->status === 'RELEASED') {
            return response()->noContent();
        }
        if ($a->status !== 'ACTIVE') {
            throw new DomainProblem(409, 'INVALID_STATE_TRANSITION', 'Allocation not active', 'This allocation was already picked.');
        }
        $db->update(
            "update stock_allocations set status = 'RELEASED', release_reason = ?, released_by = (select c.actor from ctx_api.context() c), released_at = now() where id = ?",
            [trim($data['reason']), $a->id],
        );
        $this->audit->record($db, 'stock_allocation.released', 'stock_allocation', (string) $a->id, ['status' => 'ACTIVE'], ['status' => 'RELEASED', 'reason' => trim($data['reason'])]);

        return response()->noContent();
    }

    /**
     * Pick allocated FG to staging (Idempotency-Key header required): part or all of each allocation; the rest stays
     * allocated. 201 when posted, 200 when the same request was posted before under the key.
     */
    public function pick(Request $request): JsonResponse
    {
        $data = $request->validate([
            'plantId' => ['required', 'uuid'],
            // the staging location picked to; the first active one of the plant when omitted
            'stagingLocationId' => ['nullable', 'uuid'],
            'lines' => ['required', 'array', 'min:1', 'max:20'],
            'lines.*.allocationId' => ['required', 'uuid', 'distinct:ignore_case'],
            'lines.*.qty' => ['required', 'string', 'regex:'.ReceiptController::QTY],
            'note' => ['nullable', 'string', 'max:500'],
        ]);
        $key = $this->key($request);
        $db = $this->db();
        $plant = $this->plant($db, $data['plantId'], 'delivery.shipment.post');
        $hash = hash('sha256', json_encode(['PICK', $plant, $data], JSON_THROW_ON_ERROR));
        $previous = $this->postings->claim($db, $key, $hash);
        if ($previous !== null) {
            return $this->answer($db, $previous);
        }
        $staging = $this->location($db, $plant, 'STAGING', $data['stagingLocationId'] ?? null, 'stagingLocationId');

        $legs = [];
        $consumed = [];
        foreach (array_values($data['lines']) as $i => $l) {
            $a = $db->selectOne(
                'select a.id, a.status, a.demand_id, trim_scale(a.qty)::text as qty, b.location_id, b.item_id, b.lot_id, u.code as uom, u.decimals
                   from stock_allocations a join stock_balances b on b.id = a.balance_id join items i on i.id = b.item_id join uoms u on u.id = i.base_uom_id
                  where a.id = ? and b.plant_id = ? for update of a',
                [strtolower($l['allocationId']), $plant],
            );
            if ($a === null || $a->status !== 'ACTIVE') {
                throw ValidationException::withMessages(["lines.{$i}.allocationId" => 'Choose an active allocation of this plant.']);
            }
            self::requireUnitPrecision($l['qty'], $a, "lines.{$i}.qty");
            $qty = self::num($l['qty']);
            if (bccomp($qty, '0', 6) <= 0 || bccomp($qty, self::num((string) $a->qty), 6) > 0) {
                throw ValidationException::withMessages(["lines.{$i}.qty" => "Pick above zero and at most the {$a->qty} {$a->uom} allocated."]);
            }
            $consumed[] = [$a, $qty];
            $legs[] = new PostingLeg('PICK', (string) $a->location_id, (string) $a->item_id, $a->lot_id, 'UNRESTRICTED', '-'.$qty, scheduleLineId: (string) $a->demand_id);
            $legs[] = new PostingLeg('PICK', $staging, (string) $a->item_id, $a->lot_id, 'UNRESTRICTED', $qty, scheduleLineId: (string) $a->demand_id);
        }
        // consumed before the posting, so the picked quantity is no longer held back as allocated; the rest of a partly
        // picked allocation stays allocated as its own row
        foreach ($consumed as [$a, $qty]) {
            $db->update("update stock_allocations set status = 'CONSUMED' where id = ?", [$a->id]);
            $rest = bcsub(self::num((string) $a->qty), $qty, 6);
            if (bccomp($rest, '0', 6) > 0) {
                $db->insert(
                    'insert into stock_allocations (tenant_id, balance_id, demand_type, demand_id, qty, split_from)
                     select tenant_id, balance_id, demand_type, demand_id, ?, id from stock_allocations where id = ?',
                    [$rest, $a->id],
                );
            }
        }
        $ids = '{'.implode(',', array_map(static fn (array $c) => (string) $c[0]->id, $consumed)).'}';
        $result = $this->postings->post($db, new PostingCommand('PICK', $plant, $key, $legs, note: self::optionalText($data['note'] ?? null), requestHash: $hash),
            function (string $document) use ($db, $ids): void {
                $db->update('update stock_allocations set consumed_by_document_id = ? where id = any(?::uuid[])', [$document, $ids]);
            });

        return $this->answer($db, $result);
    }

    /**
     * Put staged goods of schedule lines back into an FG rack, unallocated (Idempotency-Key header required). 201 when
     * posted, 200 when the same request was posted before under the key.
     */
    public function unpick(Request $request): JsonResponse
    {
        $data = $request->validate([
            'plantId' => ['required', 'uuid'],
            // the FG warehouse location the goods go back to; the first active one of the plant when omitted
            'locationId' => ['nullable', 'uuid'],
            'lines' => ['required', 'array', 'min:1', 'max:20'],
            'lines.*.scheduleLineId' => ['required', 'uuid'],
            'lines.*.stockLineId' => ['required', 'uuid'],
            'lines.*.qty' => ['required', 'string', 'regex:'.ReceiptController::QTY],
            'note' => ['nullable', 'string', 'max:500'],
        ]);
        $key = $this->key($request);
        $db = $this->db();
        $plant = $this->plant($db, $data['plantId'], 'delivery.shipment.post');
        $hash = hash('sha256', json_encode(['UNPICK', $plant, $data], JSON_THROW_ON_ERROR));
        $previous = $this->postings->claim($db, $key, $hash);
        if ($previous !== null) {
            return $this->answer($db, $previous);
        }
        $to = $this->location($db, $plant, 'FG_WAREHOUSE', $data['locationId'] ?? null, 'locationId');

        $legs = [];
        $seen = [];
        foreach (array_values($data['lines']) as $i => $l) {
            $line = strtolower($l['scheduleLineId']);
            $stock = strtolower($l['stockLineId']);
            if (isset($seen["{$line}|{$stock}"])) {
                throw ValidationException::withMessages(["lines.{$i}.stockLineId" => 'Enter each schedule line and staged lot once.']);
            }
            $seen["{$line}|{$stock}"] = true;
            $b = $db->selectOne(
                "select b.location_id, b.item_id, b.lot_id, u.code as uom, u.decimals,
                        trim_scale(coalesce((select sum(m.qty_signed) from inventory_movements m where m.schedule_line_id = ? and m.location_id = b.location_id
                                                and m.item_id = b.item_id and m.lot_id is not distinct from b.lot_id), 0))::text as staged
                   from stock_balances b join locations l on l.id = b.location_id join items i on i.id = b.item_id join uoms u on u.id = i.base_uom_id
                  where b.id = ? and b.plant_id = ? and l.location_type = 'STAGING' and b.stock_status = 'UNRESTRICTED'",
                [$line, $stock, $plant],
            ) ?? throw ValidationException::withMessages(["lines.{$i}.stockLineId" => 'Choose goods at a staging location of this plant.']);
            self::requireUnitPrecision($l['qty'], $b, "lines.{$i}.qty");
            $qty = self::num($l['qty']);
            if (bccomp($qty, '0', 6) <= 0 || bccomp($qty, self::num((string) $b->staged), 6) > 0) {
                throw ValidationException::withMessages(["lines.{$i}.qty" => "Put back above zero and at most the {$b->staged} {$b->uom} staged for this schedule line."]);
            }
            $legs[] = new PostingLeg('PICK', (string) $b->location_id, (string) $b->item_id, $b->lot_id, 'UNRESTRICTED', '-'.$qty, scheduleLineId: $line);
            $legs[] = new PostingLeg('PICK', $to, (string) $b->item_id, $b->lot_id, 'UNRESTRICTED', $qty, scheduleLineId: $line);
        }

        return $this->answer($db, $this->postings->post($db, new PostingCommand('PICK', $plant, $key, $legs, note: self::optionalText($data['note'] ?? null), requestHash: $hash)));
    }

    /** The schedule line with its item, plant and unit, when the actor holds $permission in its plant (404 otherwise). */
    private function scheduleLine(EoraPostgresConnection $db, string $id, string $permission): stdClass
    {
        $id = strtolower($id);
        $s = preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/', $id) === 1 ? $db->selectOne(
            'select s.id, s.plant_id, s.status, trim_scale(s.required_qty)::text as required, ol.item_id, i.code as item_code, u.code as uom, u.decimals, p.company_id
               from delivery_schedule_lines s join sales_order_lines ol on ol.id = s.so_line_id join items i on i.id = ol.item_id
               join uoms u on u.id = s.uom_id join plants p on p.id = s.plant_id where s.id = ?',
            [$id],
        ) : null;
        if ($s === null) {
            throw new DomainProblem(404, 'NOT_FOUND', 'Not found');
        }
        $this->authorizer->authorize($db, $permission, Scope::plant((string) $s->plant_id, (string) $s->company_id));

        return $s;
    }

    /**
     * What the schedule line still needs allocated: required − (allocated + staged + delivered), never below zero.
     *
     * @return numeric-string
     */
    private function toAllocate(EoraPostgresConnection $db, stdClass $s): string
    {
        $committed = self::num((string) $db->selectOne('select eora_schedule_line_committed((select c.tenant from ctx_api.context() c), ?)::text as q', [$s->id])->q);
        $need = bcsub(self::num((string) $s->required), $committed, 6);

        return bccomp($need, '0', 6) > 0 ? $need : '0';
    }

    private function allocation(EoraPostgresConnection $db, string $id, string $permission): stdClass
    {
        $id = strtolower($id);
        $a = preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/', $id) === 1 ? $db->selectOne(
            'select a.id, a.status, b.plant_id, p.company_id from stock_allocations a join stock_balances b on b.id = a.balance_id join plants p on p.id = b.plant_id
              where a.id = ? for update of a',
            [$id],
        ) : null;
        if ($a === null) {
            throw new DomainProblem(404, 'NOT_FOUND', 'Not found');
        }
        $this->authorizer->authorize($db, $permission, Scope::plant((string) $a->plant_id, (string) $a->company_id));

        return $a;
    }

    private function location(EoraPostgresConnection $db, string $plant, string $type, ?string $id, string $field): string
    {
        $row = $id !== null
            ? $db->selectOne("select id from locations where id = ? and plant_id = ? and location_type = ? and status = 'ACTIVE' for share", [strtolower($id), $plant, $type])
            : $db->selectOne("select id from locations where plant_id = ? and location_type = ? and status = 'ACTIVE' order by code limit 1 for share", [$plant, $type]);

        return (string) ($row ?? throw ValidationException::withMessages([$field => 'Choose an active '.strtolower(str_replace('_', ' ', $type)).' location of this plant.']))->id;
    }

    /** @return numeric-string */
    private static function num(mixed $value): string
    {
        return is_string($value) && is_numeric($value) ? $value : throw new LogicException('Not a quantity.');
    }

    private static function plain(string $qty): string
    {
        return str_contains($qty, '.') ? (rtrim(rtrim($qty, '0'), '.') ?: '0') : $qty;
    }

    private function plainOf(string $qty): string
    {
        return self::plain($qty);
    }
}
