<?php

declare(strict_types=1);

namespace App\Modules\Planning\Http;

use App\Modules\Planning\Queries\WorkOrdersQuery;
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
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use LogicException;
use stdClass;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * PPIC → Work Orders (doc 33 §1.2, D-107, D-109, G-MPS-6, BR-PLN-01, doc 16 §1): manual WOs outside MPS. Owner rules of
 * 2026-11-12: PPC drafts a WO for a finished item with a fixed planning-exception reason, optionally pegged to a firm
 * schedule line of the same plant and item (the WO quantity is free; demand never changes, D-034), and asks for release;
 * a second person with `planning.wo.approve` releases it or returns it with a reason, never the requester. Release
 * snapshots the approved BOM version and the approved version of the primary routing at the plant in force on the
 * plant's today (both required), the validated net weight and, per material line, the validated conversion profile
 * version when they exist (not blocking). Every write takes If-Match on the WO and is audited.
 */
final class WorkOrderController
{
    use MasterWrites;

    private const string VIEW = 'planning.wo.view';

    private const string CREATE = 'planning.wo.create';

    private const string SUBMIT = 'planning.wo.submit';

    private const string POST = 'planning.wo.post';

    private const string APPROVE = 'planning.wo.approve';

    /** G-MPS-6 planning-exception reasons (D-109; tenant configuration later); OTHER needs a note. */
    public const array REASONS = ['URGENT_ORDER', 'NO_MPS_YET', 'SAMPLE_TRIAL', 'NG_REPLACEMENT', 'OTHER'];

    public const string QTY = '/^\d{1,14}(\.\d{1,6})?$/';

    public function __construct(
        private readonly Authorizer $authorizer,
        private readonly AuditRecorder $audit,
        private readonly QueryRunner $rows,
        private readonly BusinessCalendar $calendar,
        private readonly OutboxPublisher $outbox,
    ) {}

    /** A new DRAFT WO; its number `WO-<plant>-<year>-NNNN` is taken now and never reused (D-049). */
    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'plantId' => ['required', 'uuid'],
            'itemId' => ['required', 'uuid'],
            'qty' => ['required', 'string', 'regex:'.WorkOrderController::QTY],
            'dueDate' => ['required', 'date_format:Y-m-d'],
            'reasonCode' => ['required', Rule::in(WorkOrderController::REASONS)],
            'note' => ['nullable', 'string', 'max:500'],
            'scheduleLineId' => ['nullable', 'uuid'],
        ]);
        $db = $this->db();
        $this->authorizer->requireAnywhere($db, self::CREATE);
        $plant = $db->selectOne('select id, code, company_id from plants where id = ?', [strtolower($data['plantId'])])
            ?? throw ValidationException::withMessages(['plantId' => 'Unknown plant.']);
        $this->authorizer->authorize($db, self::CREATE, Scope::plant((string) $plant->id, (string) $plant->company_id));
        $fields = $this->checked($db, (string) $plant->id, $data);

        $year = (int) substr($this->today($db, (string) $plant->id), 0, 4);
        $number = (int) $db->selectOne(
            "insert into document_counters (tenant_id, plant_id, doc_type, year, last_no) values ((select c.tenant from ctx_api.context() c), ?, 'WORK_ORDER', ?, 1)
             on conflict (tenant_id, plant_id, doc_type, year) do update set last_no = document_counters.last_no + 1 returning last_no",
            [$plant->id, $year],
        )->last_no;
        $woNo = sprintf('WO-%s-%d-%04d', $plant->code, $year, $number);
        $id = (string) $db->selectOne(
            'insert into work_orders (tenant_id, plant_id, wo_no, item_id, uom_id, qty, due_date, reason_code, note, schedule_line_id)
             values ((select c.tenant from ctx_api.context() c), ?, ?, ?, ?, ?, ?, ?, ?, ?) returning id',
            [$plant->id, $woNo, $fields['item_id'], $fields['uom_id'], $fields['qty'], $fields['due_date'], $fields['reason_code'], $fields['note'], $fields['schedule_line_id']],
        )->id;
        $this->audit->record($db, 'work_order.created', 'work_order', $id, null, $this->state($db, 'work_orders', $id), ['woNo' => $woNo], (string) $plant->id);

        return $this->respondOrder($db, $id, 201);
    }

    /** One WO with its pegged schedule line, release snapshot and material lines (404 outside the reader's plants). */
    public function show(string $order): JsonResponse
    {
        $db = $this->db();

        return $this->respondOrder($db, (string) $this->visible($db, $order)->id);
    }

    /** Edit a DRAFT WO (If-Match); omitted fields stay. */
    public function updateDraft(Request $request, string $order): JsonResponse
    {
        $data = $request->validate([
            'itemId' => ['sometimes', 'uuid'],
            'qty' => ['sometimes', 'string', 'regex:'.WorkOrderController::QTY],
            'dueDate' => ['sometimes', 'date_format:Y-m-d'],
            'reasonCode' => ['sometimes', Rule::in(WorkOrderController::REASONS)],
            'note' => ['sometimes', 'nullable', 'string', 'max:500'],
            'scheduleLineId' => ['sometimes', 'nullable', 'uuid'],
        ]);
        $expected = Concurrency::expectedVersion($request);
        $db = $this->db();
        $wo = $this->writable($db, $order, ['DRAFT']);
        $this->authorizer->authorize($db, self::CREATE, $this->plantScope($db, (string) $wo->plant_id));
        $merged = [
            'itemId' => $data['itemId'] ?? $wo->item_id,
            'qty' => $data['qty'] ?? self::plain((string) $wo->qty),
            'dueDate' => $data['dueDate'] ?? $wo->due_date,
            'reasonCode' => $data['reasonCode'] ?? $wo->reason_code,
            'note' => array_key_exists('note', $data) ? $data['note'] : $wo->note,
            'scheduleLineId' => array_key_exists('scheduleLineId', $data) ? $data['scheduleLineId'] : $wo->schedule_line_id,
        ];
        $fields = $this->checked($db, (string) $wo->plant_id, $merged);
        $this->update($db, 'work_orders', 'work_order', (string) $wo->id, $expected, $fields, 'work_order.updated', (string) $wo->plant_id);

        return $this->respondOrder($db, (string) $wo->id);
    }

    /** DRAFT → SUBMITTED (If-Match): the requester asks a second person to release it. */
    public function submit(Request $request, string $order): JsonResponse
    {
        $expected = Concurrency::expectedVersion($request);
        $db = $this->db();
        $wo = $this->writable($db, $order, ['DRAFT']);
        $this->authorizer->authorize($db, self::SUBMIT, $this->plantScope($db, (string) $wo->plant_id));
        $this->checked($db, (string) $wo->plant_id, ['itemId' => $wo->item_id, 'qty' => self::plain((string) $wo->qty), 'dueDate' => $wo->due_date,
            'reasonCode' => $wo->reason_code, 'note' => $wo->note, 'scheduleLineId' => $wo->schedule_line_id]);
        $this->transition($db, $wo, $expected, "status = 'SUBMITTED', submitted_by = ?, submitted_at = now(), return_reason = null", [$this->actor($db)], 'work_order.submitted');
        $this->outbox->publish($db, 'wo.release_requested', 'work_order', (string) $wo->id, ['plantId' => $wo->plant_id]);

        return $this->respondOrder($db, (string) $wo->id);
    }

    /** SUBMITTED → DRAFT by its requester (If-Match). */
    public function withdraw(Request $request, string $order): JsonResponse
    {
        $expected = Concurrency::expectedVersion($request);
        $db = $this->db();
        $wo = $this->writable($db, $order, ['SUBMITTED']);
        $this->authorizer->authorize($db, self::SUBMIT, $this->plantScope($db, (string) $wo->plant_id));
        if ((string) $wo->submitted_by !== $this->actor($db)) {
            throw new DomainProblem(409, 'INVALID_STATE_TRANSITION', 'Not your request', 'Only the person who asked for the release withdraws it.');
        }
        $this->transition($db, $wo, $expected, "status = 'DRAFT', submitted_by = null, submitted_at = null", [], 'work_order.withdrawn');

        return $this->respondOrder($db, (string) $wo->id);
    }

    /** SUBMITTED → DRAFT with a reason, by an approver who is not the requester (If-Match). */
    public function returnToDraft(Request $request, string $order): JsonResponse
    {
        $data = $request->validate(['reason' => ['required', 'string', 'min:3', 'max:500']]);
        $expected = Concurrency::expectedVersion($request);
        $db = $this->db();
        $wo = $this->writable($db, $order, ['SUBMITTED']);
        $this->authorizer->authorize($db, self::APPROVE, $this->plantScope($db, (string) $wo->plant_id));
        $this->authorizer->refuseSelf($db, (string) $wo->submitted_by);
        $this->transition($db, $wo, $expected, "status = 'DRAFT', submitted_by = null, submitted_at = null, return_reason = ?", [trim($data['reason'])], 'work_order.returned');
        $this->outbox->publish($db, 'wo.release_returned', 'work_order', (string) $wo->id, ['plantId' => $wo->plant_id]);

        return $this->respondOrder($db, (string) $wo->id);
    }

    /**
     * SUBMITTED → RELEASED by a second person (If-Match): snapshots the approved BOM and primary routing versions in
     * force today at the plant (409 WO_MASTER_MISSING without them), the validated net weight and conversion profile
     * versions when there are, and writes the material lines (quantity × BOM gross quantity per piece).
     */
    public function release(Request $request, string $order): JsonResponse
    {
        $expected = Concurrency::expectedVersion($request);
        $db = $this->db();
        $wo = $this->writable($db, $order, ['SUBMITTED']);
        $this->authorizer->authorize($db, self::APPROVE, $this->plantScope($db, (string) $wo->plant_id));
        $this->authorizer->refuseSelf($db, (string) $wo->submitted_by);
        $today = $this->today($db, (string) $wo->plant_id);
        $bom = $db->selectOne(
            "select id, version_no from bom_versions where item_id = ? and status in ('APPROVED', 'SUPERSEDED')
                and effective_from <= ?::date and (effective_to is null or effective_to >= ?::date) order by effective_from desc limit 1",
            [$wo->item_id, $today, $today],
        );
        $routing = $db->selectOne(
            "select v.id, v.version_no, r.routing_code from routings r join routing_versions v on v.routing_id = r.id
              where r.plant_id = ? and r.item_id = ? and r.routing_kind = 'PRIMARY' and r.status = 'ACTIVE' and v.status in ('APPROVED', 'SUPERSEDED')
                and v.effective_from <= ?::date and (v.effective_to is null or v.effective_to >= ?::date) order by v.effective_from desc limit 1",
            [$wo->plant_id, $wo->item_id, $today, $today],
        );
        if ($bom === null || $routing === null) {
            $missing = implode(' and no ', array_filter([$bom === null ? 'approved BOM version' : null, $routing === null ? 'approved primary routing version at this plant' : null]));
            throw new DomainProblem(409, 'WO_MASTER_MISSING', 'Engineering master missing', "The item has no {$missing} in force today ({$today}). Approve it in Master Data first.");
        }
        $weight = $db->selectOne(
            "select id from item_weights where item_id = ? and status = 'VALIDATED' and effective_from <= ?::date and (effective_to is null or effective_to >= ?::date)
              order by effective_from desc limit 1",
            [$wo->item_id, $today, $today],
        );
        $this->transition(
            $db, $wo, $expected,
            "status = 'RELEASED', released_by = ?, released_at = now(), bom_version_id = ?, routing_version_id = ?, item_weight_id = ?",
            [$this->actor($db), $bom->id, $routing->id, $weight?->id],
            'work_order.released',
        );
        $db->insert(
            "insert into work_order_materials (tenant_id, work_order_id, line_no, bom_line_id, component_item_id, uom_id, gross_qty_per, scrap_allowance, required_qty, conversion_profile_version_id)
             select l.tenant_id, ?, l.line_no, l.id, l.component_item_id, l.uom_id, l.gross_qty_per, l.scrap_allowance, round(?::numeric * l.gross_qty_per, 6),
                    (select v.id from conversion_profile_versions v where v.profile_id = l.conversion_profile_id and v.status = 'VALIDATED'
                        and v.effective_from <= ?::date and (v.effective_to is null or v.effective_to >= ?::date) order by v.effective_from desc limit 1)
               from bom_lines l where l.bom_version_id = ? order by l.line_no",
            [$wo->id, (string) $wo->qty, $today, $today, $bom->id],
        );
        $this->outbox->publish($db, 'wo.released', 'work_order', (string) $wo->id, ['plantId' => $wo->plant_id, 'itemId' => $wo->item_id]);

        return $this->respondOrder($db, (string) $wo->id);
    }

    /**
     * Cancel with a reason (If-Match): a draft or a request with `planning.wo.create`, a released WO with
     * `planning.wo.post` while nothing was issued or run against it (doc 16 §1; issues and runs arrive in steps 3–4).
     */
    public function cancel(Request $request, string $order): JsonResponse
    {
        $data = $request->validate(['reason' => ['required', 'string', 'min:3', 'max:500']]);
        $expected = Concurrency::expectedVersion($request);
        $db = $this->db();
        $wo = $this->writable($db, $order, ['DRAFT', 'SUBMITTED', 'RELEASED']);
        $this->authorizer->authorize($db, $wo->status === 'RELEASED' ? self::POST : self::CREATE, $this->plantScope($db, (string) $wo->plant_id));
        $this->transition($db, $wo, $expected, "status = 'CANCELLED', cancelled_by = ?, cancelled_at = now(), cancel_reason = ?", [$this->actor($db), trim($data['reason'])], 'work_order.cancelled');
        $this->outbox->publish($db, 'wo.cancelled', 'work_order', (string) $wo->id, ['plantId' => $wo->plant_id]);

        return $this->respondOrder($db, (string) $wo->id);
    }

    /**
     * The checked columns of a draft: an active finished item with its base unit, a quantity in that unit's decimals, a
     * due date today or later at the plant, a reason (OTHER with a note) and an optional firm schedule line of the same
     * plant and item.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function checked(EoraPostgresConnection $db, string $plant, array $data): array
    {
        $item = $db->selectOne(
            'select i.id, i.item_type, i.status, i.base_uom_id, u.code as uom, u.decimals from items i join uoms u on u.id = i.base_uom_id where i.id = ?',
            [strtolower((string) $data['itemId'])],
        );
        if ($item === null || $item->item_type !== 'FINISHED' || $item->status !== 'ACTIVE') {
            throw ValidationException::withMessages(['itemId' => 'Choose an active finished item (Semi-Finished work orders come with M5).']);
        }
        $qty = (string) $data['qty'];
        if (! is_numeric($qty) || bccomp($qty, '0', 6) <= 0) {
            throw ValidationException::withMessages(['qty' => 'Enter a quantity above zero.']);
        }
        $dot = strpos($qty, '.');
        if (($dot === false ? 0 : strlen(rtrim(substr($qty, $dot + 1), '0'))) > (int) $item->decimals) {
            throw ValidationException::withMessages(['qty' => (int) $item->decimals === 0 ? "Enter a whole number of {$item->uom}." : "Enter {$item->uom} with at most {$item->decimals} decimals."]);
        }
        $today = $this->today($db, $plant);
        if ((string) $data['dueDate'] < $today) {
            throw ValidationException::withMessages(['dueDate' => "Choose today ({$today}) or a later day."]);
        }
        $note = self::optionalText($data['note'] ?? null);
        if ($data['reasonCode'] === 'OTHER' && mb_strlen((string) $note) < 3) {
            throw ValidationException::withMessages(['note' => 'Explain the work order when the reason is Other.']);
        }
        $schedule = null;
        if (($data['scheduleLineId'] ?? null) !== null) {
            $line = $db->selectOne(
                'select s.id, s.status, s.plant_id, l.item_id from delivery_schedule_lines s join sales_order_lines l on l.id = s.so_line_id where s.id = ?',
                [strtolower((string) $data['scheduleLineId'])],
            );
            if ($line === null || (string) $line->plant_id !== $plant || (string) $line->item_id !== (string) $item->id
                || ! in_array($line->status, ['FIRM', 'PARTIALLY_FULFILLED'], true)) {
                throw ValidationException::withMessages(['scheduleLineId' => 'Choose a firm delivery of this item at this plant.']);
            }
            $schedule = (string) $line->id;
        }

        return ['item_id' => (string) $item->id, 'uom_id' => (string) $item->base_uom_id, 'qty' => $qty, 'due_date' => (string) $data['dueDate'],
            'reason_code' => (string) $data['reasonCode'], 'note' => $note, 'schedule_line_id' => $schedule];
    }

    /** @param  list<mixed>  $bindings */
    private function transition(EoraPostgresConnection $db, stdClass $wo, int $expected, string $set, array $bindings, string $action): void
    {
        $before = $this->state($db, 'work_orders', (string) $wo->id);
        if ($db->update("update work_orders set {$set} where id = ? and version = ?", [...$bindings, $wo->id, $expected]) === 0) {
            throw Concurrency::conflict();
        }
        $this->audit->record($db, $action, 'work_order', (string) $wo->id, $before, $this->state($db, 'work_orders', (string) $wo->id), [], (string) $wo->plant_id);
    }

    private function visible(EoraPostgresConnection $db, string $order, bool $lock = false): stdClass
    {
        $scopes = $this->authorizer->requireAnyOf($db, [self::VIEW, self::APPROVE]);
        $id = $this->existing($db, 'work_orders', $order);
        $wo = $db->selectOne('select w.*, w.due_date::text as due_date, p.company_id from work_orders w join plants p on p.id = w.plant_id where w.id = ?'.($lock ? ' for update of w' : ''), [$id])
            ?? throw new NotFoundHttpException;
        if (! array_filter($scopes, static fn (Scope $s) => $s->covers(Scope::plant((string) $wo->plant_id, (string) $wo->company_id)))) {
            throw new NotFoundHttpException;
        }

        return $wo;
    }

    /** @param  list<string>  $statuses */
    private function writable(EoraPostgresConnection $db, string $order, array $statuses): stdClass
    {
        $wo = $this->visible($db, $order, true);
        if (! in_array($wo->status, $statuses, true)) {
            throw new DomainProblem(409, 'INVALID_STATE_TRANSITION', 'Work order status does not allow this', 'This is possible on a '.strtolower(implode(' or ', $statuses)).' work order only.');
        }

        return $wo;
    }

    private function today(EoraPostgresConnection $db, string $plant): string
    {
        return $this->calendar->resolve($db, $plant, new DateTimeImmutable)->calendarDate;
    }

    private function actor(EoraPostgresConnection $db): string
    {
        return (string) $db->selectOne('select c.actor from ctx_api.context() c')->actor;
    }

    private static function plain(string $number): string
    {
        return str_contains($number, '.') ? rtrim(rtrim($number, '0'), '.') : $number;
    }

    /** The WO (list representation) with its material lines; ETag = WO version. */
    private function respondOrder(EoraPostgresConnection $db, string $id, int $status = 200): JsonResponse
    {
        $row = $this->rows->find($db, new WorkOrdersQuery, $id) ?? throw new LogicException('Written row not visible.');
        $row['materials'] = array_map(static fn (stdClass $m) => [
            'lineNo' => (int) $m->line_no,
            'itemId' => (string) $m->component_item_id,
            'itemCode' => (string) $m->item_code,
            'itemName' => (string) $m->item_name,
            'uomCode' => (string) $m->uom_code,
            'grossQtyPer' => (string) $m->gross_qty_per,
            'requiredQty' => (string) $m->required_qty,
            'conversionProfileVersion' => $m->cp_version === null ? null : (int) $m->cp_version,
        ], $db->select(
            'select m.line_no, m.component_item_id, i.code as item_code, i.name as item_name, u.code as uom_code, trim_scale(m.gross_qty_per)::text as gross_qty_per,
                    trim_scale(m.required_qty)::text as required_qty, v.version_no as cp_version
               from work_order_materials m join items i on i.id = m.component_item_id join uoms u on u.id = m.uom_id
               left join conversion_profile_versions v on v.id = m.conversion_profile_version_id
              where m.work_order_id = ? order by m.line_no',
            [$id],
        ));
        $row['plantToday'] = $this->today($db, (string) $row['plantId']);

        return Concurrency::withVersion(response()->json(['data' => $row], $status), (int) $row['version']);
    }
}
