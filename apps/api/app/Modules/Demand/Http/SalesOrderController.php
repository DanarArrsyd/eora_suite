<?php

declare(strict_types=1);

namespace App\Modules\Demand\Http;

use App\Modules\Demand\Queries\SalesOrdersQuery;
use App\Platform\Access\Authorizer;
use App\Platform\Access\Scope;
use App\Platform\Audit\AuditRecorder;
use App\Platform\Calendar\BusinessCalendar;
use App\Platform\Database\EoraPostgresConnection;
use App\Platform\Exceptions\DomainProblem;
use App\Platform\Http\Concurrency;
use App\Platform\Http\MasterWrites;
use App\Platform\Outbox\OutboxPublisher;
use App\Platform\Query\QueryRunner;
use DateTimeImmutable;
use Illuminate\Database\QueryException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use LogicException;
use stdClass;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Demand → Sales Orders (doc 33 §1.1, D-107, D-034, BR-DEM-05/06/10, doc 16 §12). Owner rules of 2026-11-12:
 * - the customer PO is a reference on each SO line (number + line); one active SO line per customer PO line;
 * - a line's quantity is the sum of its Delivery Schedule Lines; the item comes from an active customer part mapping
 *   of the order's customer (finished items only);
 * - requirement dates are today or later at the plant; PPC confirms alone, there is no price;
 * - a confirmed order keeps its FIRM schedule lines: lines and schedule lines are added or cancelled (with a reason),
 *   never edited; a draft that is not used is cancelled, never deleted.
 * Drafts are edited with `demand.sales_order.create`; confirming and every change of a confirmed order need
 * `demand.sales_order.post`, in the order's plant. Every write takes If-Match on the order and is audited.
 */
final class SalesOrderController
{
    use MasterWrites;

    private const string VIEW = 'demand.sales_order.view';

    private const string CREATE = 'demand.sales_order.create';

    private const string POST = 'demand.sales_order.post';

    public const string QTY = '/^\d{1,14}(\.\d{1,6})?$/';

    public function __construct(
        private readonly Authorizer $authorizer,
        private readonly AuditRecorder $audit,
        private readonly QueryRunner $rows,
        private readonly BusinessCalendar $calendar,
        private readonly OutboxPublisher $outbox,
    ) {}

    /** A new DRAFT order for a customer at a plant; its number is taken now and never reused (D-049). */
    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'plantId' => ['required', 'uuid'],
            'customerId' => ['required', 'uuid'],
            'note' => ['nullable', 'string', 'max:500'],
        ]);
        $db = $this->db();
        $this->authorizer->requireAnywhere($db, self::CREATE);
        $plant = $db->selectOne('select id, code, company_id from plants where id = ?', [strtolower($data['plantId'])])
            ?? throw ValidationException::withMessages(['plantId' => 'Unknown plant.']);
        $this->authorizer->authorize($db, self::CREATE, Scope::plant((string) $plant->id, (string) $plant->company_id));
        $customer = $db->selectOne('select id, status from customers where id = ?', [strtolower($data['customerId'])]);
        if ($customer === null || $customer->status !== 'ACTIVE') {
            throw ValidationException::withMessages(['customerId' => 'Choose an active customer.']);
        }

        $year = (int) substr($this->today($db, (string) $plant->id), 0, 4);
        $number = (int) $db->selectOne(
            "insert into document_counters (tenant_id, plant_id, doc_type, year, last_no) values ((select c.tenant from ctx_api.context() c), ?, 'SALES_ORDER', ?, 1)
             on conflict (tenant_id, plant_id, doc_type, year) do update set last_no = document_counters.last_no + 1 returning last_no",
            [$plant->id, $year],
        )->last_no;
        $soNo = sprintf('SO-%s-%d-%04d', $plant->code, $year, $number);
        $id = (string) $db->selectOne(
            'insert into sales_order_headers (tenant_id, plant_id, customer_id, so_no, note) values ((select c.tenant from ctx_api.context() c), ?, ?, ?, ?) returning id',
            [$plant->id, $customer->id, $soNo, self::optionalText($data['note'] ?? null)],
        )->id;
        $this->audit->record($db, 'sales_order.created', 'sales_order', $id, null, $this->state($db, 'sales_order_headers', $id), ['soNo' => $soNo], (string) $plant->id);

        return $this->respondOrder($db, $id, 201);
    }

    /** One order with its lines and schedule lines (404 outside the reader's plants). */
    public function show(string $order): JsonResponse
    {
        $db = $this->db();

        return $this->respondOrder($db, (string) $this->visible($db, $order)->id);
    }

    /** Change the note of a draft or confirmed order (If-Match). */
    public function updateNote(Request $request, string $order): JsonResponse
    {
        $data = $request->validate(['note' => ['present', 'nullable', 'string', 'max:500']]);
        $expected = Concurrency::expectedVersion($request);
        $db = $this->db();
        $so = $this->writable($db, $order, ['DRAFT', 'CONFIRMED']);
        $this->authorizer->authorize($db, $so->status === 'DRAFT' ? self::CREATE : self::POST, $this->plantScope($db, (string) $so->plant_id));
        $this->update($db, 'sales_order_headers', 'sales_order', (string) $so->id, $expected, ['note' => self::optionalText($data['note'])], 'sales_order.updated', (string) $so->plant_id);

        return $this->respondOrder($db, (string) $so->id);
    }

    /**
     * Replace every line of a DRAFT order (If-Match). Each line: an active customer part mapping of the order's customer
     * (`customerItemId`), the customer PO number and line it comes from, and one or more schedule lines (date + quantity
     * in the item's unit). A customer PO line belongs to one active SO line only (BR-DEM-05).
     */
    public function putLines(Request $request, string $order): JsonResponse
    {
        $data = $request->validate([
            'lines' => ['present', 'array', 'max:200'],
            'lines.*.customerItemId' => ['required', 'uuid'],
            'lines.*.customerPoNo' => ['required', 'string', 'max:40'],
            'lines.*.customerPoLine' => ['required', 'string', 'max:20'],
            'lines.*.schedules' => ['required', 'array', 'min:1', 'max:100'],
            'lines.*.schedules.*.requirementDate' => ['required', 'date_format:Y-m-d'],
            'lines.*.schedules.*.qty' => ['required', 'string', 'regex:'.SalesOrderController::QTY],
        ]);
        $expected = Concurrency::expectedVersion($request);
        $db = $this->db();
        $so = $this->writable($db, $order, ['DRAFT']);
        $this->authorizer->authorize($db, self::CREATE, $this->plantScope($db, (string) $so->plant_id));
        $today = $this->today($db, (string) $so->plant_id);
        $lines = [];
        $seen = [];
        foreach ($data['lines'] as $i => $line) {
            $lines[] = $this->checkedLine($db, $so, $line, "lines.{$i}", $today, true);
            $pair = mb_strtoupper(trim($line['customerPoNo'])).'|'.mb_strtoupper(trim($line['customerPoLine']));
            if (isset($seen[$pair])) {
                throw ValidationException::withMessages(["lines.{$i}.customerPoLine" => 'This customer PO line is already on this order.']);
            }
            $seen[$pair] = true;
        }

        $before = $this->summary($db, (string) $so->id);
        $this->bump($db, $so, $expected);
        $db->delete('delete from sales_order_lines where sales_order_id = ?', [$so->id]);
        foreach ($lines as $n => $line) {
            $this->insertLine($db, $so, $n + 1, $line, 'DRAFT');
        }
        $this->audit->record($db, 'sales_order.lines_replaced', 'sales_order', (string) $so->id, $before, $this->summary($db, (string) $so->id), [], (string) $so->plant_id);

        return $this->respondOrder($db, (string) $so->id);
    }

    /** DRAFT → CONFIRMED (If-Match): its schedule lines become FIRM demand. Needs at least one line. */
    public function confirm(Request $request, string $order): JsonResponse
    {
        $expected = Concurrency::expectedVersion($request);
        $db = $this->db();
        $so = $this->writable($db, $order, ['DRAFT']);
        $this->authorizer->authorize($db, self::POST, $this->plantScope($db, (string) $so->plant_id));
        if ($db->selectOne('select 1 as ok from sales_order_lines where sales_order_id = ?', [$so->id]) === null) {
            throw new DomainProblem(409, 'INVALID_STATE_TRANSITION', 'Order has no lines', 'Add at least one line with its delivery schedule before confirming.');
        }
        $today = $this->today($db, (string) $so->plant_id);
        $late = $db->selectOne(
            'select min(s.requirement_date)::text as d from delivery_schedule_lines s join sales_order_lines l on l.id = s.so_line_id where l.sales_order_id = ? and s.requirement_date < ?::date',
            [$so->id, $today],
        )->d;
        if ($late !== null) {
            throw new DomainProblem(409, 'REQUIREMENT_DATE_PAST', 'Delivery date has passed', "A delivery date ({$late}) is before today ({$today}). Change it in the draft first.");
        }
        $stale = $db->selectOne(
            "select l.line_no from sales_order_lines l join customer_items ci on ci.id = l.customer_item_id join items i on i.id = ci.item_id
              where l.sales_order_id = ? and not (ci.status = 'ACTIVE' and ci.effective_from <= ?::date and (ci.effective_to is null or ci.effective_to >= ?::date) and i.status = 'ACTIVE')
              order by l.line_no limit 1",
            [$so->id, $today, $today],
        );
        if ($stale !== null) {
            throw new DomainProblem(409, 'CUSTOMER_PART_INACTIVE', 'Customer part no longer valid', "The customer part mapping of line {$stale->line_no} is no longer active. Replace the line in the draft.");
        }
        $before = $this->state($db, 'sales_order_headers', (string) $so->id);
        $actor = $this->actor($db);
        if ($db->update("update sales_order_headers set status = 'CONFIRMED', confirmed_at = now(), confirmed_by = ? where id = ? and version = ?", [$actor, $so->id, $expected]) === 0) {
            throw Concurrency::conflict();
        }
        $db->update("update delivery_schedule_lines s set status = 'FIRM' from sales_order_lines l where l.id = s.so_line_id and l.sales_order_id = ?", [$so->id]);
        $this->audit->record($db, 'sales_order.confirmed', 'sales_order', (string) $so->id, $before, $this->state($db, 'sales_order_headers', (string) $so->id), $this->summary($db, (string) $so->id), (string) $so->plant_id);
        $this->outbox->publish($db, 'demand.changed', 'sales_order', (string) $so->id, ['plantId' => $so->plant_id, 'change' => 'CONFIRMED']);

        return $this->respondOrder($db, (string) $so->id);
    }

    /** DRAFT → CANCELLED with a reason (If-Match). The order stays in the list; its customer PO lines are free again. */
    public function cancel(Request $request, string $order): JsonResponse
    {
        $data = $request->validate(['reason' => ['required', 'string', 'min:3', 'max:500']]);
        $expected = Concurrency::expectedVersion($request);
        $db = $this->db();
        $so = $this->writable($db, $order, ['DRAFT']);
        $this->authorizer->authorize($db, self::CREATE, $this->plantScope($db, (string) $so->plant_id));
        $reason = trim($data['reason']);
        $before = $this->state($db, 'sales_order_headers', (string) $so->id);
        $actor = $this->actor($db);
        if ($db->update("update sales_order_headers set status = 'CANCELLED', cancelled_at = now(), cancelled_by = ?, cancel_reason = ? where id = ? and version = ?", [$actor, $reason, $so->id, $expected]) === 0) {
            throw Concurrency::conflict();
        }
        $db->update(
            "update delivery_schedule_lines s set status = 'CANCELLED', cancelled_at = now(), cancelled_by = ?, cancel_reason = ? from sales_order_lines l where l.id = s.so_line_id and l.sales_order_id = ?",
            [$actor, $reason, $so->id],
        );
        $db->update("update sales_order_lines set status = 'CANCELLED' where sales_order_id = ?", [$so->id]);
        $this->audit->record($db, 'sales_order.cancelled', 'sales_order', (string) $so->id, $before, $this->state($db, 'sales_order_headers', (string) $so->id), [], (string) $so->plant_id);

        return $this->respondOrder($db, (string) $so->id);
    }

    /** Add a line to a CONFIRMED order (If-Match): its schedule lines are FIRM at once. */
    public function addLine(Request $request, string $order): JsonResponse
    {
        $data = $request->validate([
            'customerItemId' => ['required', 'uuid'],
            'customerPoNo' => ['required', 'string', 'max:40'],
            'customerPoLine' => ['required', 'string', 'max:20'],
            'schedules' => ['required', 'array', 'min:1', 'max:100'],
            'schedules.*.requirementDate' => ['required', 'date_format:Y-m-d'],
            'schedules.*.qty' => ['required', 'string', 'regex:'.SalesOrderController::QTY],
        ]);
        $expected = Concurrency::expectedVersion($request);
        $db = $this->db();
        $so = $this->writable($db, $order, ['CONFIRMED']);
        $this->authorizer->authorize($db, self::POST, $this->plantScope($db, (string) $so->plant_id));
        $line = $this->checkedLine($db, $so, $data, '', $this->today($db, (string) $so->plant_id), false);
        $this->bump($db, $so, $expected);
        $next = (int) $db->selectOne('select coalesce(max(line_no), 0) + 1 as n from sales_order_lines where sales_order_id = ?', [$so->id])->n;
        $lineId = $this->insertLine($db, $so, $next, $line, 'FIRM');
        $this->audit->record($db, 'sales_order.line_added', 'sales_order', (string) $so->id, null, $this->summary($db, (string) $so->id), ['lineId' => $lineId, 'lineNo' => $next], (string) $so->plant_id);
        $this->outbox->publish($db, 'demand.changed', 'sales_order', (string) $so->id, ['plantId' => $so->plant_id, 'change' => 'LINE_ADDED', 'lineId' => $lineId]);

        return $this->respondOrder($db, (string) $so->id);
    }

    /** Add a FIRM schedule line to an active line of a CONFIRMED order (If-Match). */
    public function addSchedule(Request $request, string $order): JsonResponse
    {
        $data = $request->validate([
            'lineId' => ['required', 'uuid'],
            'requirementDate' => ['required', 'date_format:Y-m-d'],
            'qty' => ['required', 'string', 'regex:'.SalesOrderController::QTY],
        ]);
        $expected = Concurrency::expectedVersion($request);
        $db = $this->db();
        $so = $this->writable($db, $order, ['CONFIRMED']);
        $this->authorizer->authorize($db, self::POST, $this->plantScope($db, (string) $so->plant_id));
        $line = $db->selectOne(
            'select l.id, l.line_no, l.status, l.uom_id, u.code as uom, u.decimals from sales_order_lines l join uoms u on u.id = l.uom_id where l.id = ? and l.sales_order_id = ? for update of l',
            [strtolower($data['lineId']), $so->id],
        ) ?? throw ValidationException::withMessages(['lineId' => 'Unknown line of this order.']);
        if ($line->status !== 'ACTIVE') {
            throw ValidationException::withMessages(['lineId' => 'This line is cancelled.']);
        }
        $schedule = $this->checkedSchedule($data, $line, $this->today($db, (string) $so->plant_id), '');
        $this->bump($db, $so, $expected);
        $no = (int) $db->selectOne('select coalesce(max(schedule_no), 0) + 1 as n from delivery_schedule_lines where so_line_id = ?', [$line->id])->n;
        $scheduleId = $this->insertSchedule($db, $so, (string) $line->id, (int) $line->line_no, (string) $line->uom_id, $no, $schedule, 'FIRM');
        $this->audit->record($db, 'sales_order.schedule_added', 'sales_order', (string) $so->id, null, $this->summary($db, (string) $so->id), ['scheduleLineId' => $scheduleId], (string) $so->plant_id);
        $this->outbox->publish($db, 'demand.schedule_changed', 'sales_order', (string) $so->id, ['plantId' => $so->plant_id, 'change' => 'ADDED', 'scheduleLineId' => $scheduleId]);

        return $this->respondOrder($db, (string) $so->id);
    }

    /**
     * Cancel a FIRM schedule line of a CONFIRMED order with a reason (If-Match): refused once FG is allocated to it or
     * anything was delivered. A line whose schedule lines are all cancelled is cancelled too (its customer PO line is
     * free again).
     */
    public function cancelSchedule(Request $request, string $order, string $schedule): JsonResponse
    {
        $data = $request->validate(['reason' => ['required', 'string', 'min:3', 'max:500']]);
        $expected = Concurrency::expectedVersion($request);
        $db = $this->db();
        $so = $this->writable($db, $order, ['CONFIRMED']);
        $this->authorizer->authorize($db, self::POST, $this->plantScope($db, (string) $so->plant_id));
        $row = $db->selectOne(
            'select s.id, s.status, s.so_line_id from delivery_schedule_lines s join sales_order_lines l on l.id = s.so_line_id where s.id = ? and l.sales_order_id = ? for update of s',
            [strtolower($schedule), $so->id],
        ) ?? throw new NotFoundHttpException;
        if ($row->status !== 'FIRM') {
            throw new DomainProblem(409, 'INVALID_STATE_TRANSITION', 'Schedule line not cancellable', 'Only a firm schedule line without deliveries can be cancelled.');
        }
        if ($db->selectOne("select 1 as ok from stock_allocations where demand_type = 'SCHEDULE_LINE' and demand_id = ? and status = 'ACTIVE'", [$row->id]) !== null) {
            throw new DomainProblem(409, 'SCHEDULE_LINE_ALLOCATED', 'Finished goods allocated', 'Release the finished goods allocated to this schedule line first.');
        }
        $this->bump($db, $so, $expected);
        $before = $this->state($db, 'delivery_schedule_lines', (string) $row->id);
        $db->update("update delivery_schedule_lines set status = 'CANCELLED', cancelled_at = now(), cancelled_by = ?, cancel_reason = ? where id = ?", [$this->actor($db), trim($data['reason']), $row->id]);
        $db->update(
            "update sales_order_lines l set status = 'CANCELLED' where l.id = ? and l.status = 'ACTIVE'
                and not exists (select 1 from delivery_schedule_lines s where s.so_line_id = l.id and s.status <> 'CANCELLED')",
            [$row->so_line_id],
        );
        $this->audit->record($db, 'sales_order.schedule_cancelled', 'delivery_schedule_line', (string) $row->id, $before, $this->state($db, 'delivery_schedule_lines', (string) $row->id), ['salesOrderId' => $so->id], (string) $so->plant_id);
        $this->outbox->publish($db, 'demand.schedule_changed', 'sales_order', (string) $so->id, ['plantId' => $so->plant_id, 'change' => 'CANCELLED', 'scheduleLineId' => $row->id]);

        return $this->respondOrder($db, (string) $so->id);
    }

    /**
     * A line of the request, checked: the mapping (customer, active on the plant's today, finished item), the PO
     * reference and its schedule lines.
     *
     * @param  array<string, mixed>  $line
     * @return array{mapping: stdClass, poNo: string, poLine: string, schedules: list<array{date: string, qty: string}>}
     */
    private function checkedLine(EoraPostgresConnection $db, stdClass $so, array $line, string $path, string $today, bool $replacing): array
    {
        $at = static fn (string $field) => $path === '' ? $field : "{$path}.{$field}";
        $mapping = $db->selectOne(
            'select ci.id, ci.item_id, ci.customer_id, ci.status, ci.effective_from::text, ci.effective_to::text, i.item_type, i.status as item_status, i.base_uom_id, u.code as uom, u.decimals
               from customer_items ci join items i on i.id = ci.item_id join uoms u on u.id = i.base_uom_id where ci.id = ?',
            [strtolower((string) $line['customerItemId'])],
        );
        if ($mapping === null || (string) $mapping->customer_id !== (string) $so->customer_id) {
            throw ValidationException::withMessages([$at('customerItemId') => 'Choose a customer part of this order\'s customer.']);
        }
        if ($mapping->status !== 'ACTIVE' || $mapping->effective_from > $today || ($mapping->effective_to !== null && $mapping->effective_to < $today)) {
            throw ValidationException::withMessages([$at('customerItemId') => 'This customer part mapping is not active today.']);
        }
        if ($mapping->item_type !== 'FINISHED' || $mapping->item_status !== 'ACTIVE') {
            throw ValidationException::withMessages([$at('customerItemId') => 'Sales orders take active finished items only.']);
        }
        $poNo = trim((string) $line['customerPoNo']);
        $poLine = trim((string) $line['customerPoLine']);
        if ($poNo === '') {
            throw ValidationException::withMessages([$at('customerPoNo') => 'Enter the customer PO number.']);
        }
        if ($poLine === '') {
            throw ValidationException::withMessages([$at('customerPoLine') => 'Enter the customer PO line.']);
        }
        // BR-DEM-05 holds across the tenant, without case; the other order is named only to a reader of its plant. A draft's
        // own lines are being replaced, so they do not count; a line added to a confirmed order meets its siblings too.
        $taken = $db->selectOne(
            "select h.id, h.so_no, h.plant_id, p.company_id from sales_order_lines l join sales_order_headers h on h.id = l.sales_order_id join plants p on p.id = h.plant_id
              where l.customer_id = ? and upper(l.customer_po_no) = upper(?) and upper(l.customer_po_line) = upper(?) and l.status = 'ACTIVE'
                and (not ?::boolean or l.sales_order_id <> ?)",
            [$so->customer_id, $poNo, $poLine, $replacing, $so->id],
        );
        if ($taken !== null && (string) $taken->id === (string) $so->id) {
            throw ValidationException::withMessages([$at('customerPoLine') => 'This customer PO line is already on this order.']);
        }
        if ($taken !== null) {
            $readable = array_filter($this->authorizer->scopes($db, self::VIEW), static fn (Scope $s) => $s->covers(Scope::plant((string) $taken->plant_id, (string) $taken->company_id)));
            throw ValidationException::withMessages([$at('customerPoLine') => $readable !== []
                ? "Customer PO {$poNo} line {$poLine} is already on {$taken->so_no}."
                : "Customer PO {$poNo} line {$poLine} is already on another order."]);
        }
        $unit = (object) ['uom' => $mapping->uom, 'decimals' => $mapping->decimals];
        $schedules = [];
        foreach ($line['schedules'] as $j => $schedule) {
            $schedules[] = $this->checkedSchedule($schedule, $unit, $today, $at("schedules.{$j}"));
        }

        return ['mapping' => $mapping, 'poNo' => $poNo, 'poLine' => $poLine, 'schedules' => $schedules];
    }

    /**
     * @param  array<string, mixed>  $schedule
     * @return array{date: string, qty: string}
     */
    private function checkedSchedule(array $schedule, stdClass $unit, string $today, string $path): array
    {
        $at = static fn (string $field) => $path === '' ? $field : "{$path}.{$field}";
        $date = (string) $schedule['requirementDate'];
        if ($date < $today) {
            throw ValidationException::withMessages([$at('requirementDate') => "Choose today ({$today}) or a later day."]);
        }
        $qty = (string) $schedule['qty'];
        if (! is_numeric($qty) || bccomp($qty, '0', 6) <= 0) {
            throw ValidationException::withMessages([$at('qty') => 'Enter a quantity above zero.']);
        }
        $dot = strpos($qty, '.');
        $decimals = $dot === false ? 0 : strlen(rtrim(substr($qty, $dot + 1), '0'));
        if ($decimals > (int) $unit->decimals) {
            throw ValidationException::withMessages([$at('qty') => (int) $unit->decimals === 0
                ? "Enter a whole number of {$unit->uom}."
                : "Enter {$unit->uom} with at most {$unit->decimals} decimals."]);
        }

        return ['date' => $date, 'qty' => $qty];
    }

    /** @param  array{mapping: stdClass, poNo: string, poLine: string, schedules: list<array{date: string, qty: string}>}  $line */
    private function insertLine(EoraPostgresConnection $db, stdClass $so, int $lineNo, array $line, string $status): string
    {
        try {
            $id = (string) $db->selectOne(
                'insert into sales_order_lines (tenant_id, sales_order_id, customer_id, plant_id, line_no, customer_item_id, item_id, uom_id, customer_po_no, customer_po_line)
                 values ((select c.tenant from ctx_api.context() c), ?, ?, ?, ?, ?, ?, ?, ?, ?) returning id',
                [$so->id, $so->customer_id, $so->plant_id, $lineNo, $line['mapping']->id, $line['mapping']->item_id, $line['mapping']->base_uom_id, $line['poNo'], $line['poLine']],
            )->id;
        } catch (QueryException $e) {
            if ($e->getCode() === '23505') {
                throw ValidationException::withMessages(['customerPoLine' => "Customer PO {$line['poNo']} line {$line['poLine']} is already on another order."]);
            }
            throw $e;
        }
        foreach ($line['schedules'] as $n => $schedule) {
            $this->insertSchedule($db, $so, $id, $lineNo, (string) $line['mapping']->base_uom_id, $n + 1, $schedule, $status);
        }

        return $id;
    }

    /** @param  array{date: string, qty: string}  $schedule */
    private function insertSchedule(EoraPostgresConnection $db, stdClass $so, string $lineId, int $lineNo, string $uom, int $no, array $schedule, string $status): string
    {
        return (string) $db->selectOne(
            "insert into delivery_schedule_lines (tenant_id, so_line_id, plant_id, schedule_no, schedule_type, source_document_type, source_document_no, source_line_ref,
                                                  requirement_date, required_qty, uom_id, status)
             values ((select c.tenant from ctx_api.context() c), ?, ?, ?, 'MANUAL', 'SALES_ORDER', ?, ?, ?, ?, ?, ?) returning id",
            [$lineId, $so->plant_id, $no, $so->so_no, "{$lineNo}/{$no}", $schedule['date'], $schedule['qty'], $uom, $status],
        )->id;
    }

    /** The order's version moves with every change of its lines, so If-Match on the order covers them. */
    private function bump(EoraPostgresConnection $db, stdClass $so, int $expected): void
    {
        if ($db->update('update sales_order_headers set note = note where id = ? and version = ?', [$so->id, $expected]) === 0) {
            throw Concurrency::conflict();
        }
    }

    /** The order row visible to a reader in its plant; 404 otherwise. */
    private function visible(EoraPostgresConnection $db, string $order, bool $lock = false): stdClass
    {
        $scopes = $this->authorizer->requireAnywhere($db, self::VIEW);
        $id = $this->existing($db, 'sales_order_headers', $order);
        $so = $db->selectOne('select h.*, p.company_id from sales_order_headers h join plants p on p.id = h.plant_id where h.id = ?'.($lock ? ' for update of h' : ''), [$id])
            ?? throw new NotFoundHttpException;
        if (! array_filter($scopes, static fn (Scope $s) => $s->covers(Scope::plant((string) $so->plant_id, (string) $so->company_id)))) {
            throw new NotFoundHttpException;
        }

        return $so;
    }

    /**
     * The locked order, visible to the reader and in one of $statuses (409 otherwise).
     *
     * @param  list<string>  $statuses
     */
    private function writable(EoraPostgresConnection $db, string $order, array $statuses): stdClass
    {
        $so = $this->visible($db, $order, true);
        if (! in_array($so->status, $statuses, true)) {
            $what = ['DRAFT' => 'a draft', 'CONFIRMED' => 'a confirmed order'];
            throw new DomainProblem(409, 'INVALID_STATE_TRANSITION', 'Order status does not allow this', 'This is possible on '.implode(' or ', array_map(static fn ($s) => $what[$s], $statuses)).' only.');
        }

        return $so;
    }

    private function today(EoraPostgresConnection $db, string $plant): string
    {
        return $this->calendar->resolve($db, $plant, new DateTimeImmutable)->calendarDate;
    }

    private function actor(EoraPostgresConnection $db): string
    {
        return (string) $db->selectOne('select c.actor from ctx_api.context() c')->actor;
    }

    /** @return array<string, mixed> the lines and schedule lines of an order, for the audit trail */
    private function summary(EoraPostgresConnection $db, string $id): array
    {
        return ['lines' => array_map(static fn (stdClass $r) => (array) $r, $db->select(
            "select l.line_no, l.customer_po_no, l.customer_po_line, l.item_id, l.status,
                    coalesce(json_agg(json_build_object('no', s.schedule_no, 'date', s.requirement_date, 'qty', trim_scale(s.required_qty)::text, 'status', s.status) order by s.schedule_no)
                             filter (where s.id is not null), '[]')::text as schedules
               from sales_order_lines l left join delivery_schedule_lines s on s.so_line_id = l.id
              where l.sales_order_id = ? group by l.id order by l.line_no",
            [$id],
        ))];
    }

    /** The order (list representation) with its lines and schedule lines; ETag = order version. */
    private function respondOrder(EoraPostgresConnection $db, string $id, int $status = 200): JsonResponse
    {
        $row = $this->rows->find($db, new SalesOrdersQuery, $id) ?? throw new LogicException('Written row not visible.');
        $lines = $db->select(
            "select l.id, l.line_no, l.demand_id, l.customer_item_id, ci.customer_part_no, ci.customer_drawing_rev, l.item_id, i.code as item_code, i.name as item_name,
                    u.code as uom_code, l.customer_po_no, l.customer_po_line, l.status,
                    (select trim_scale(coalesce(sum(s.required_qty), 0))::text from delivery_schedule_lines s
                      where s.so_line_id = l.id and s.status not in ('CANCELLED', 'SUPERSEDED')) as qty
               from sales_order_lines l join customer_items ci on ci.id = l.customer_item_id join items i on i.id = l.item_id join uoms u on u.id = l.uom_id
              where l.sales_order_id = ? order by l.line_no",
            [$id],
        );
        $schedules = $db->select(
            'select s.id, s.so_line_id, s.schedule_no, s.requirement_date::text as requirement_date, trim_scale(s.required_qty)::text as qty, s.status,
                    s.cancel_reason, s.cancelled_at, cu.display_name as cancelled_by_name
               from delivery_schedule_lines s join sales_order_lines l on l.id = s.so_line_id left join users cu on cu.id = s.cancelled_by
              where l.sales_order_id = ? order by s.requirement_date, s.schedule_no',
            [$id],
        );
        $byLine = [];
        foreach ($schedules as $s) {
            // delivered and outstanding follow posted shipments (BR-DEM-10, M4 step 7); none exist yet
            $byLine[(string) $s->so_line_id][] = [
                'id' => (string) $s->id,
                'scheduleNo' => (int) $s->schedule_no,
                'requirementDate' => (string) $s->requirement_date,
                'qty' => (string) $s->qty,
                'deliveredQty' => '0',
                'outstandingQty' => in_array($s->status, ['CANCELLED', 'SUPERSEDED', 'DRAFT'], true) ? '0' : (string) $s->qty,
                'status' => (string) $s->status,
                'cancelReason' => $s->cancel_reason,
                'cancelledAt' => $s->cancelled_at === null ? null : (new DateTimeImmutable((string) $s->cancelled_at))->format(DATE_ATOM),
                'cancelledByName' => $s->cancelled_by_name,
            ];
        }
        $row['lines'] = array_map(static function (stdClass $l) use ($byLine): array {
            $list = $byLine[(string) $l->id] ?? [];

            return [
                'id' => (string) $l->id,
                'lineNo' => (int) $l->line_no,
                'demandId' => (string) $l->demand_id,
                'customerItemId' => (string) $l->customer_item_id,
                'customerPartNo' => (string) $l->customer_part_no,
                'customerDrawingRev' => $l->customer_drawing_rev,
                'itemId' => (string) $l->item_id,
                'itemCode' => (string) $l->item_code,
                'itemName' => (string) $l->item_name,
                'uomCode' => (string) $l->uom_code,
                'customerPoNo' => (string) $l->customer_po_no,
                'customerPoLine' => (string) $l->customer_po_line,
                'status' => (string) $l->status,
                'qty' => (string) $l->qty,
                'schedules' => $list,
            ];
        }, $lines);

        // the first day a delivery may be planned: today at the order's plant (the server checks the same date)
        $row['plantToday'] = $this->today($db, (string) $row['plantId']);

        return Concurrency::withVersion(response()->json(['data' => $row], $status), (int) $row['version']);
    }
}
