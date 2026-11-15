<?php

declare(strict_types=1);

namespace App\Modules\Demand\Http;

use App\Modules\Demand\Queries\ForecastLinesQuery;
use App\Modules\Demand\Queries\ForecastVersionsQuery;
use App\Platform\Access\Authorizer;
use App\Platform\Audit\AuditRecorder;
use App\Platform\Calendar\BusinessCalendar;
use App\Platform\Database\EoraPostgresConnection;
use App\Platform\Exceptions\DomainProblem;
use App\Platform\Http\Concurrency;
use App\Platform\Http\MasterWrites;
use App\Platform\Query\QueryRunner;
use DateTimeImmutable;
use Illuminate\Database\QueryException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use LogicException;
use stdClass;

/**
 * Demand → Forecasts (doc 34 §1.2, D-122, D-040, BR-DEM-08): a forecast version per customer + plant with a quantity per
 * customer part per ISO week. A planner drafts it (`demand.forecast.create`) and activates it (`demand.forecast.post`,
 * no approval); the newly active version supersedes the active one of that customer and plant. Firm demand of the same
 * customer, item, plant and week consumes it on read (ForecastLinesQuery); nothing is counted twice.
 */
final class ForecastController
{
    use MasterWrites;

    private const string VIEW = 'demand.forecast.view';

    private const string CREATE = 'demand.forecast.create';

    private const string POST = 'demand.forecast.post';

    public function __construct(
        private readonly Authorizer $authorizer,
        private readonly AuditRecorder $audit,
        private readonly QueryRunner $rows,
        private readonly BusinessCalendar $calendar,
    ) {}

    /** A new draft version `FC-<plant>-<year>-NNNN` (201); with `copyFromActive` it starts with the active version's lines. */
    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'plantId' => ['required', 'uuid'],
            'customerId' => ['required', 'uuid'],
            'copyFromActive' => ['sometimes', 'boolean'],
            'note' => ['nullable', 'string', 'max:500'],
        ]);
        $db = $this->db();
        $this->authorizer->requireAnywhere($db, self::CREATE);
        $plant = $this->existing($db, 'plants', $data['plantId']);
        $this->authorizer->authorize($db, self::CREATE, $this->plantScope($db, $plant));
        $customer = $db->selectOne("select id from customers where id = ? and status = 'ACTIVE'", [strtolower($data['customerId'])])
            ?? throw ValidationException::withMessages(['customerId' => 'Choose an active customer.']);
        // one draft at a time per customer and plant
        if ($db->selectOne("select 1 as ok from forecast_versions where plant_id = ? and customer_id = ? and status = 'DRAFT'", [$plant, $customer->id]) !== null) {
            throw new DomainProblem(409, 'INVALID_STATE_TRANSITION', 'Draft exists', 'This customer has a draft forecast at this plant; finish it first.');
        }
        $year = (int) substr($this->calendar->resolve($db, $plant, new DateTimeImmutable)->calendarDate, 0, 4);
        $number = (int) $db->selectOne(
            "insert into document_counters (tenant_id, plant_id, doc_type, year, last_no) values ((select c.tenant from ctx_api.context() c), ?, 'FORECAST', ?, 1)
             on conflict (tenant_id, plant_id, doc_type, year) do update set last_no = document_counters.last_no + 1 returning last_no",
            [$plant, $year],
        )->last_no;
        $code = (string) $db->selectOne('select code from plants where id = ?', [$plant])->code;
        try {
            $id = (string) $db->selectOne(
                'insert into forecast_versions (tenant_id, plant_id, customer_id, forecast_no, note) values ((select c.tenant from ctx_api.context() c), ?, ?, ?, ?) returning id',
                [$plant, $customer->id, sprintf('FC-%s-%d-%04d', $code, $year, $number), self::optionalText($data['note'] ?? null)],
            )->id;
        } catch (QueryException $e) {
            // another planner drafted one at the same moment (forecast_versions_draft_uq)
            if ($e->getCode() === '23505') {
                throw new DomainProblem(409, 'INVALID_STATE_TRANSITION', 'Draft exists', 'This customer has a draft forecast at this plant; finish it first.');
            }
            throw $e;
        }
        if ((bool) ($data['copyFromActive'] ?? false)) {
            $db->insert(
                "insert into forecast_lines (tenant_id, forecast_version_id, customer_id, plant_id, customer_item_id, item_id, week_start, qty)
                 select l.tenant_id, ?, l.customer_id, l.plant_id, l.customer_item_id, l.item_id, l.week_start, l.qty
                   from forecast_lines l join forecast_versions f on f.id = l.forecast_version_id
                  where f.plant_id = ? and f.customer_id = ? and f.status = 'ACTIVE'",
                [$id, $plant, $customer->id],
            );
        }
        $this->audit->record($db, 'forecast.created', 'forecast_version', $id, null, $this->state($db, 'forecast_versions', $id), [], $plant);

        return $this->respondVersion($db, $id, 201);
    }

    /** The version with its lines and what firm demand consumed of each. */
    public function show(string $version): JsonResponse
    {
        $db = $this->db();
        $this->authorizer->requireAnywhere($db, self::VIEW);
        $id = $this->existing($db, 'forecast_versions', $version);
        $plant = (string) $db->selectOne('select plant_id from forecast_versions where id = ?', [$id])->plant_id;
        $this->authorizer->authorize($db, self::VIEW, $this->plantScope($db, $plant));

        return $this->respondVersion($db, $id);
    }

    /**
     * Replace the lines of a draft (If-Match on the version): a quantity per customer part of the version's customer
     * per ISO week; any day names its week (stored as its Monday).
     */
    public function putLines(Request $request, string $version): JsonResponse
    {
        $data = $request->validate([
            'lines' => ['present', 'array', 'max:2000'],
            'lines.*.customerItemId' => ['required', 'uuid'],
            'lines.*.weekStart' => ['required', 'date_format:Y-m-d'],
            'lines.*.qty' => ['required', 'string', 'regex:/^(?!0+(\.0+)?$)\d{1,14}(\.\d{1,6})?$/'],
        ]);
        $expected = Concurrency::expectedVersion($request);
        $db = $this->db();
        $f = $this->draft($db, $version);
        $parts = [];
        $seen = [];
        foreach ($data['lines'] as $n => $line) {
            $part = strtolower($line['customerItemId']);
            $parts[$part] ??= $db->selectOne(
                "select ci.id, ci.item_id, u.code as uom, u.decimals from customer_items ci join items i on i.id = ci.item_id join uoms u on u.id = i.base_uom_id
                  where ci.id = ? and ci.customer_id = ? and ci.status = 'ACTIVE' and i.item_type in ('FINISHED', 'SEMI_FINISHED')",
                [$part, $f->customer_id],
            ) ?? throw ValidationException::withMessages(["lines.{$n}.customerItemId" => 'Choose an active part of this customer.']);
            $p = $parts[$part];
            if (strlen(explode('.', $line['qty'].'.')[1]) > (int) $p->decimals) {
                throw ValidationException::withMessages(["lines.{$n}.qty" => "{$p->uom} takes ".((int) $p->decimals === 0 ? 'whole numbers' : "at most {$p->decimals} decimals").'.']);
            }
            $monday = (new DateTimeImmutable($line['weekStart']))->modify('monday this week')->format('Y-m-d');
            if (isset($seen["{$part}|{$monday}"])) {
                throw ValidationException::withMessages(["lines.{$n}.weekStart" => "This part has the week of {$monday} twice."]);
            }
            $seen["{$part}|{$monday}"] = [$p, $monday, $line['qty']];
        }
        $before = $this->lines($db, (string) $f->id);
        if ($db->update('update forecast_versions set note = note where id = ? and version = ?', [$f->id, $expected]) === 0) {
            throw Concurrency::conflict();
        }
        $db->delete('delete from forecast_lines where forecast_version_id = ?', [$f->id]);
        foreach ($seen as [$p, $monday, $qty]) {
            $db->insert(
                'insert into forecast_lines (tenant_id, forecast_version_id, customer_id, plant_id, customer_item_id, item_id, week_start, qty)
                 values ((select c.tenant from ctx_api.context() c), ?, ?, ?, ?, ?, ?, ?)',
                [$f->id, $f->customer_id, $f->plant_id, $p->id, $p->item_id, $monday, $qty],
            );
        }
        $this->audit->record($db, 'forecast.lines_replaced', 'forecast_version', (string) $f->id, ['lines' => $before], ['lines' => $this->lines($db, (string) $f->id)], [], (string) $f->plant_id);

        return $this->respondVersion($db, (string) $f->id);
    }

    /** Activate a draft (If-Match): the active version of that customer and plant becomes superseded. */
    public function activate(Request $request, string $version): JsonResponse
    {
        $expected = Concurrency::expectedVersion($request);
        $db = $this->db();
        $f = $this->draft($db, $version, self::POST);
        if ($db->selectOne('select 1 as ok from forecast_lines where forecast_version_id = ? limit 1', [$f->id]) === null) {
            throw new DomainProblem(409, 'INVALID_STATE_TRANSITION', 'No lines', 'Add at least one forecast line before activating.');
        }
        $active = $db->selectOne("select id from forecast_versions where plant_id = ? and customer_id = ? and status = 'ACTIVE' for update", [$f->plant_id, $f->customer_id]);
        if ($active !== null) {
            $before = $this->state($db, 'forecast_versions', (string) $active->id);
            $db->update("update forecast_versions set status = 'SUPERSEDED', superseded_at = now() where id = ?", [$active->id]);
            $this->audit->record($db, 'forecast.superseded', 'forecast_version', (string) $active->id, $before, $this->state($db, 'forecast_versions', (string) $active->id), [], (string) $f->plant_id);
        }
        $before = $this->state($db, 'forecast_versions', (string) $f->id);
        if ($db->update("update forecast_versions set status = 'ACTIVE', activated_at = now(), activated_by = (select c.actor from ctx_api.context() c)
                          where id = ? and version = ?", [$f->id, $expected]) === 0) {
            throw Concurrency::conflict();
        }
        $this->audit->record($db, 'forecast.activated', 'forecast_version', (string) $f->id, $before, $this->state($db, 'forecast_versions', (string) $f->id), [], (string) $f->plant_id);

        return $this->respondVersion($db, (string) $f->id);
    }

    /** The locked draft version, when the actor holds $permission in its plant (404 otherwise, 409 when not a draft). */
    private function draft(EoraPostgresConnection $db, string $version, string $permission = self::CREATE): stdClass
    {
        $this->authorizer->requireAnywhere($db, $permission);
        $id = $this->existing($db, 'forecast_versions', $version);
        $f = $db->selectOne('select id, plant_id, customer_id, status, forecast_no from forecast_versions where id = ? for update', [$id]);
        $this->authorizer->authorize($db, $permission, $this->plantScope($db, (string) $f->plant_id));
        if ($f->status !== 'DRAFT') {
            throw new DomainProblem(409, 'INVALID_STATE_TRANSITION', 'Not a draft', "{$f->forecast_no} is ".strtolower((string) $f->status).'; start a new version to change it.');
        }

        return $f;
    }

    /** @return list<array<string, string>> the version's lines, for the audit trail */
    private function lines(EoraPostgresConnection $db, string $id): array
    {
        return array_values(array_map(static fn (stdClass $r) => (array) $r, $db->select(
            'select customer_item_id, week_start::text as week_start, trim_scale(qty)::text as qty from forecast_lines where forecast_version_id = ? order by week_start, customer_item_id',
            [$id],
        )));
    }

    private function respondVersion(EoraPostgresConnection $db, string $id, int $status = 200): JsonResponse
    {
        $row = $this->rows->find($db, new ForecastVersionsQuery, $id) ?? throw new LogicException('Written row not visible.');
        $lines = $db->select(
            'select l.id, l.customer_item_id, ci.customer_part_no, i.code as item_code, i.name as item_name, u.code as uom, u.decimals,
                    l.week_start::text as week_start, trim_scale(l.qty)::text as qty, trim_scale(fm.firm)::text as firm, trim_scale(greatest(l.qty - fm.firm, 0))::text as net
               from forecast_lines l join customer_items ci on ci.id = l.customer_item_id join items i on i.id = l.item_id join uoms u on u.id = i.base_uom_id
               cross join lateral (select '.ForecastLinesQuery::FIRM.' as firm) fm
              where l.forecast_version_id = ? order by l.week_start, ci.customer_part_no',
            [$id],
        );
        $row['lines'] = array_map(static fn (stdClass $l) => [
            'id' => (string) $l->id, 'customerItemId' => (string) $l->customer_item_id, 'customerPartNo' => (string) $l->customer_part_no,
            'itemCode' => (string) $l->item_code, 'itemName' => (string) $l->item_name, 'uomCode' => (string) $l->uom, 'uomDecimals' => (int) $l->decimals,
            'weekStart' => (string) $l->week_start, 'qty' => (string) $l->qty, 'firmQty' => (string) $l->firm, 'netQty' => (string) $l->net,
        ], $lines);

        return Concurrency::withVersion(response()->json(['data' => $row], $status), (int) $row['version']);
    }
}
