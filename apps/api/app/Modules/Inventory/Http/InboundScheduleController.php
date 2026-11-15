<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Http;

use App\Modules\Inventory\Queries\InboundScheduleQuery;
use App\Platform\Access\Authorizer;
use App\Platform\Audit\AuditRecorder;
use App\Platform\Calendar\BusinessCalendar;
use App\Platform\Database\EoraPostgresConnection;
use App\Platform\Exceptions\DomainProblem;
use App\Platform\Http\Concurrency;
use App\Platform\Http\MasterWrites;
use App\Platform\Query\QueryRunner;
use DateTimeImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use stdClass;

/**
 * Materials → Inbound Schedule (doc 34 §1.1, D-087, D-121): what a supplier is expected to deliver — supplier, item,
 * plant, quantity in the item's base unit, expected date. Reference data for MPS projected supply and RM receipt: no
 * price, approval or invoice. MAT or PPC writes it (`materials.inbound_schedule.create`); a line keeps its quantity and
 * date once something was received against it and is cancelled with a reason. Edits use If-Match; every write is audited.
 */
final class InboundScheduleController
{
    use MasterWrites {
        update as private updateRow;
    }

    private const string CREATE = 'materials.inbound_schedule.create';

    public function __construct(
        private readonly Authorizer $authorizer,
        private readonly AuditRecorder $audit,
        private readonly QueryRunner $rows,
        private readonly BusinessCalendar $calendar,
    ) {}

    /** A new open line `INB-<plant>-<year>-NNNN` (201). */
    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'plantId' => ['required', 'uuid'],
            'supplierId' => ['required', 'uuid'],
            'itemId' => ['required', 'uuid'],
            'qty' => ['required', 'string', 'regex:'.ReceiptController::QTY],
            'expectedDate' => ['required', 'date_format:Y-m-d'],
            'note' => ['nullable', 'string', 'max:500'],
        ]);
        $db = $this->db();
        $this->authorizer->requireAnywhere($db, self::CREATE);
        $plant = $this->existing($db, 'plants', $data['plantId']);
        $this->authorizer->authorize($db, self::CREATE, $this->plantScope($db, $plant));
        $db->selectOne("select id from suppliers where id = ? and status = 'ACTIVE'", [strtolower($data['supplierId'])])
            ?? throw ValidationException::withMessages(['supplierId' => 'Choose an active supplier.']);
        $item = $this->item($db, $data['itemId']);
        $this->precision($item, (string) $data['qty']);
        $year = (int) substr($this->calendar->resolve($db, $plant, new DateTimeImmutable)->calendarDate, 0, 4);
        $number = (int) $db->selectOne(
            "insert into document_counters (tenant_id, plant_id, doc_type, year, last_no) values ((select c.tenant from ctx_api.context() c), ?, 'SUPPLIER_INBOUND', ?, 1)
             on conflict (tenant_id, plant_id, doc_type, year) do update set last_no = document_counters.last_no + 1 returning last_no",
            [$plant, $year],
        )->last_no;
        $code = (string) $db->selectOne('select code from plants where id = ?', [$plant])->code;
        $id = (string) $db->selectOne(
            'insert into supplier_inbound_lines (tenant_id, plant_id, inbound_no, supplier_id, item_id, qty, expected_date, note)
             values ((select c.tenant from ctx_api.context() c), ?, ?, ?, ?, ?, ?, ?) returning id',
            [$plant, sprintf('INB-%s-%d-%04d', $code, $year, $number), strtolower($data['supplierId']), $item->id, $data['qty'], $data['expectedDate'],
                self::optionalText($data['note'] ?? null)],
        )->id;
        $this->audit->record($db, 'inbound_line.created', 'inbound_line', $id, null, $this->state($db, 'supplier_inbound_lines', $id), [], $plant);

        return $this->respond($db, new InboundScheduleQuery, $id, 201);
    }

    /** Change quantity, expected date or note (If-Match); quantity and date stay once a receipt names the line. */
    public function update(Request $request, string $line): JsonResponse
    {
        $data = $request->validate([
            'qty' => ['sometimes', 'string', 'regex:'.ReceiptController::QTY],
            'expectedDate' => ['sometimes', 'date_format:Y-m-d'],
            'note' => ['sometimes', 'nullable', 'string', 'max:500'],
        ]);
        $expected = Concurrency::expectedVersion($request);
        $db = $this->db();
        $row = $this->line($db, $line);
        if ((isset($data['qty']) || isset($data['expectedDate'])) && (int) $row->receipts > 0) {
            throw new DomainProblem(409, 'INVALID_STATE_TRANSITION', 'Received against', "Something was received against {$row->inbound_no}: its quantity and date stay. Cancel the rest and add a new line instead.");
        }
        $changes = [];
        if (isset($data['qty'])) {
            $this->precision($this->item($db, (string) $row->item_id, false), (string) $data['qty']);
            $changes['qty'] = $data['qty'];
        }
        if (isset($data['expectedDate'])) {
            $changes['expected_date'] = $data['expectedDate'];
        }
        if (array_key_exists('note', $data)) {
            $changes['note'] = self::optionalText($data['note']);
        }
        $this->updateRow($db, 'supplier_inbound_lines', 'inbound_line', (string) $row->id, $expected, $changes, 'inbound_line.updated', (string) $row->plant_id);

        return $this->respond($db, new InboundScheduleQuery, (string) $row->id);
    }

    /** Cancel what is still expected, with the reason (If-Match); what was received stays received. */
    public function cancel(Request $request, string $line): JsonResponse
    {
        $data = $request->validate(['reason' => ['required', 'string', 'min:3', 'max:500']]);
        $expected = Concurrency::expectedVersion($request);
        $db = $this->db();
        $row = $this->line($db, $line);
        $before = $this->state($db, 'supplier_inbound_lines', (string) $row->id);
        if ($db->update('update supplier_inbound_lines set cancelled_at = now(), cancelled_by = (select c.actor from ctx_api.context() c), cancel_reason = ?
                          where id = ? and version = ?', [trim($data['reason']), $row->id, $expected]) === 0) {
            throw Concurrency::conflict();
        }
        $this->audit->record($db, 'inbound_line.cancelled', 'inbound_line', (string) $row->id, $before, $this->state($db, 'supplier_inbound_lines', (string) $row->id), [], (string) $row->plant_id);

        return $this->respond($db, new InboundScheduleQuery, (string) $row->id);
    }

    /** The open line, locked, when the actor writes the schedule in its plant (404 for anything else, 409 when cancelled). */
    private function line(EoraPostgresConnection $db, string $id): stdClass
    {
        $this->authorizer->requireAnywhere($db, self::CREATE);
        $id = $this->existing($db, 'supplier_inbound_lines', $id);
        $row = $db->selectOne(
            "select l.id, l.plant_id, l.item_id, l.inbound_no, l.cancelled_at,
                    (select count(*) from inbound_receipts r join inventory_documents d on d.id = r.document_id and d.status = 'POSTED' where r.inbound_line_id = l.id) as receipts
               from supplier_inbound_lines l where l.id = ? for update of l", [$id]);
        $this->authorizer->authorize($db, self::CREATE, $this->plantScope($db, (string) $row->plant_id));
        if ($row->cancelled_at !== null) {
            throw new DomainProblem(409, 'INVALID_STATE_TRANSITION', 'Cancelled', "{$row->inbound_no} is cancelled.");
        }

        return $row;
    }

    private function item(EoraPostgresConnection $db, string $id, bool $active = true): stdClass
    {
        $item = $db->selectOne('select i.id, i.item_type, i.status, u.code as uom, u.decimals from items i join uoms u on u.id = i.base_uom_id where i.id = ?', [strtolower($id)])
            ?? throw ValidationException::withMessages(['itemId' => 'Unknown item.']);
        if ($active && ($item->status !== 'ACTIVE' || ! in_array($item->item_type, ReceiptController::RECEIVED_TYPES, true))) {
            throw ValidationException::withMessages(['itemId' => 'Choose an active raw material or consumable.']);
        }

        return $item;
    }

    private function precision(stdClass $item, string $qty): void
    {
        if (strlen(explode('.', $qty.'.')[1]) > (int) $item->decimals) {
            throw ValidationException::withMessages(['qty' => "{$item->uom} takes ".((int) $item->decimals === 0 ? 'whole numbers' : "at most {$item->decimals} decimals").'.']);
        }
    }
}
