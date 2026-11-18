<?php

declare(strict_types=1);

namespace App\Modules\Planning\Mps;

use App\Modules\Demand\Queries\ForecastLinesQuery;
use App\Platform\Calendar\BusinessCalendar;
use App\Platform\Database\EoraPostgresConnection;
use DateTimeImmutable;
use stdClass;

/**
 * What an MPS of one plant is calculated from (doc 23 §4, D-043, D-046, D-123), read inside the tenant transaction:
 * - demand: firm outstanding (required − delivered) of FIRM / PARTIALLY_FULFILLED schedule lines due up to the horizon
 *   end, and net forecast (forecast − firm, ForecastLinesQuery::FIRM) of the plant's ACTIVE forecasts for horizon weeks,
 *   due on the first working day of its week;
 * - class A: on hand UNRESTRICTED in FG warehouse and staging locations; minus what is allocated or staged for demand
 *   outside the horizon (`outOfScope`); class B dated: QC released stock (today: no expected receipt date is kept) and
 *   the remaining output (qty − good posted) of RELEASED / IN_PROGRESS / ON_HOLD work orders, and of work orders released
 *   from the plan from their Draft on (D-125), on their due date;
 *   Before Check/Pending QC is provisional supply dated today (D-134), while delivery availability follows QC release and FG receipt;
 * - per item + plant planning parameters (safety stock, production lead time; NULL = not configured), working days;
 *   an FG / semi-finished item with a safety stock is planned even without demand in the horizon.
 * The fingerprint hashes all of it (and the plan date): approval compares it with a fresh read (D-123 (2)).
 */
final class MpsInputs
{
    public const int WEEKS = 12;

    public string $today;

    public string $horizonStart;

    public string $horizonEnd;

    public WorkingDays $days;

    /** @var array<string, MpsItemInput> */
    public array $items = [];

    public function __construct(private readonly BusinessCalendar $calendar) {}

    /**
     * Reads the plant's inputs for the horizon from the plant's this week (an MPS), or for a given horizon (an MRP run of
     * an approved MPS keeps that MPS's horizon, so demand and allocations beyond it are judged against the same end).
     */
    public function load(EoraPostgresConnection $db, string $plant, ?string $horizonStart = null, ?int $weeks = null): self
    {
        $this->today = $this->calendar->resolve($db, $plant, new DateTimeImmutable)->calendarDate;
        $monday = new DateTimeImmutable($horizonStart ?? $this->today);
        $monday = $horizonStart === null ? $monday->modify('monday this week') : $monday;
        $this->horizonStart = $monday->format('Y-m-d');
        $this->horizonEnd = $monday->modify('+'.(7 * ($weeks ?? self::WEEKS) - 1).' days')->format('Y-m-d');
        $this->days = self::workingDays($db, $plant, $this->horizonStart, $this->horizonEnd);
        $this->reset();

        $delivered = "coalesce((select sum(m.qty_signed) from inventory_movements m join locations ml on ml.tenant_id = m.tenant_id and ml.id = m.location_id and ml.location_type = 'V_CUSTOMER'
                                 where m.tenant_id = s.tenant_id and m.schedule_line_id = s.id), 0)";
        foreach ($db->select(
            "select s.id, ol.item_id, s.uom_id, s.requirement_date::text as date, trim_scale(s.required_qty - {$delivered})::text as qty
               from delivery_schedule_lines s join sales_order_lines ol on ol.tenant_id = s.tenant_id and ol.id = s.so_line_id
              where s.plant_id = ? and s.status in ('FIRM', 'PARTIALLY_FULFILLED') and s.requirement_date <= ?::date and s.required_qty > {$delivered}
              order by s.id",
            [$plant, $this->horizonEnd],
        ) as $r) {
            $this->item($r)->demands[] = ['key' => (string) $r->id, 'kind' => 'FIRM', 'date' => (string) $r->date, 'qty' => MpsItemInput::num($r->qty)];
        }
        foreach ($db->select(
            'select l.id, l.item_id, i.base_uom_id as uom_id, l.week_start::text as week_start, trim_scale(l.qty - fm.firm)::text as qty
               from forecast_lines l join forecast_versions f on f.tenant_id = l.tenant_id and f.id = l.forecast_version_id and f.status = \'ACTIVE\'
               join items i on i.tenant_id = l.tenant_id and i.id = l.item_id
               cross join lateral (select '.ForecastLinesQuery::FIRM.' as firm) fm
              where l.plant_id = ? and l.week_start between ?::date and ?::date and l.qty > fm.firm
              order by l.id',
            [$plant, $this->horizonStart, $this->horizonEnd],
        ) as $r) {
            $this->item($r)->demands[] = ['key' => (string) $r->id, 'kind' => 'FORECAST', 'date' => $this->firstWorkingDay((string) $r->week_start), 'qty' => MpsItemInput::num($r->qty)];
        }
        // items with a safety stock at the plant are planned even without demand: stock below it is replenished
        foreach ($db->select(
            "select p.item_id, i.base_uom_id as uom_id from item_plan_parameters p join items i on i.tenant_id = p.tenant_id and i.id = p.item_id
              where p.plant_id = ? and p.safety_stock > 0 and i.status = 'ACTIVE' and i.item_type in ('FINISHED', 'SEMI_FINISHED')
                and not exists (select 1 from routings r join routing_versions v on v.routing_id = r.id
                                 where r.plant_id = p.plant_id and r.status = 'ACTIVE' and r.routing_kind = 'PRIMARY'
                                   and v.paired_item_id is not null and p.item_id in (r.item_id, v.paired_item_id)
                                   and v.status in ('APPROVED', 'SUPERSEDED') and v.effective_from <= ?::date
                                   and (v.effective_to is null or v.effective_to >= ?::date))
              order by p.item_id",
            [$plant, $this->horizonEnd, $this->today],
        ) as $r) {
            $this->item($r);
        }
        if ($this->items === []) {
            return $this;
        }
        $ids = array_keys($this->items);
        $in = implode(',', array_fill(0, count($ids), '?'));

        foreach ($db->select(
            "select b.item_id,
                    trim_scale(sum(b.qty) filter (where b.stock_status = 'UNRESTRICTED' and l.location_type in ('FG_WAREHOUSE', 'STAGING')))::text as on_hand,
                    trim_scale(sum(b.qty) filter (where b.stock_status = 'PENDING_QC'))::text as pending_qc,
                    trim_scale(sum(b.qty) filter (where b.stock_status = 'QC_RELEASED'))::text as qc_released
               from stock_balances b join locations l on l.tenant_id = b.tenant_id and l.id = b.location_id
              where b.plant_id = ? and b.item_id in ({$in}) and b.qty > 0 group by b.item_id",
            [$plant, ...$ids],
        ) as $r) {
            $item = $this->items[(string) $r->item_id];
            $item->onHand = MpsItemInput::num($r->on_hand ?? '0');
            $item->pendingQc = MpsItemInput::num($r->pending_qc ?? '0');
            if (bccomp($item->pendingQc, '0', 6) > 0) {
                $item->supplies[] = ['key' => 'PENDING_QC', 'kind' => 'PENDING_QC', 'date' => $this->today, 'qty' => $item->pendingQc];
            }
            if ($r->qc_released !== null) {
                $item->supplies[] = ['key' => 'QC_RELEASED', 'kind' => 'QC_RELEASED', 'date' => $this->today, 'qty' => MpsItemInput::num($r->qc_released)];
            }
        }
        // allocated or staged for demand outside the horizon: never supply for this plan (F-2)
        foreach ($db->select(
            "select item_id, trim_scale(sum(qty))::text as qty from (
               select b.item_id, a.qty from stock_allocations a
                 join stock_balances b on b.tenant_id = a.tenant_id and b.id = a.balance_id and b.stock_status = 'UNRESTRICTED'
                 join locations l on l.tenant_id = b.tenant_id and l.id = b.location_id and l.location_type in ('FG_WAREHOUSE', 'STAGING')
                 left join delivery_schedule_lines s on a.demand_type = 'SCHEDULE_LINE' and s.tenant_id = a.tenant_id and s.id = a.demand_id
                where a.status = 'ACTIVE' and b.plant_id = ? and b.item_id in ({$in})
                  and not (s.status is not null and s.status in ('FIRM', 'PARTIALLY_FULFILLED') and s.requirement_date <= ?::date)
               union all
               select ol.item_id, m.qty_signed from inventory_movements m
                 join locations l on l.tenant_id = m.tenant_id and l.id = m.location_id and l.location_type = 'STAGING'
                 join delivery_schedule_lines s on s.tenant_id = m.tenant_id and s.id = m.schedule_line_id
                join sales_order_lines ol on ol.tenant_id = s.tenant_id and ol.id = s.so_line_id
                where s.plant_id = ? and ol.item_id in ({$in}) and s.status in ('FIRM', 'PARTIALLY_FULFILLED') and s.requirement_date > ?::date
             ) x group by item_id having sum(qty) > 0",
            [$plant, ...$ids, $this->horizonEnd, $plant, ...$ids, $this->horizonEnd],
        ) as $r) {
            $this->items[(string) $r->item_id]->outOfScope = MpsItemInput::num($r->qty);
        }
        // remaining output of released work orders: good posted has left it and sits in its stage class (F-6)
        foreach ($db->select(
            "select w.id, w.item_id, w.due_date::text as due, trim_scale(w.qty - g.good)::text as qty
               from work_orders w
               cross join lateral (select coalesce(sum(m.qty_signed), 0) as good from inventory_movements m
                                     join inventory_documents d on d.tenant_id = m.tenant_id and d.id = m.document_id and d.doc_type = 'PRODUCTION_POSTING' and d.status = 'POSTED'
                                     join production_runs r on r.tenant_id = d.tenant_id and r.id = d.source_id
                                    where r.tenant_id = w.tenant_id and r.work_order_id = w.id and m.movement_code = 'OUTPUT_GOOD' and m.qty_signed > 0) g
              where w.plant_id = ? and w.item_id in ({$in}) and (w.status in ('RELEASED', 'IN_PROGRESS', 'ON_HOLD')
                     or (w.status in ('DRAFT', 'SUBMITTED') and (w.mrp_order_id is not null or exists (select 1 from daily_recovery_plans dp where dp.work_order_id = w.id)))) and w.qty > g.good
              order by w.id",
            [$plant, ...$ids],
        ) as $r) {
            $this->items[(string) $r->item_id]->supplies[] = ['key' => (string) $r->id, 'kind' => 'WORK_ORDER', 'date' => (string) $r->due, 'qty' => MpsItemInput::num($r->qty)];
        }
        foreach ($db->select(
            "select item_id, trim_scale(safety_stock)::text as safety_stock, production_lead_time_days from item_plan_parameters where plant_id = ? and item_id in ({$in})",
            [$plant, ...$ids],
        ) as $r) {
            $item = $this->items[(string) $r->item_id];
            $item->safetyStock = $r->safety_stock === null ? null : MpsItemInput::num($r->safety_stock);
            $item->leadTimeDays = $r->production_lead_time_days === null ? null : (int) $r->production_lead_time_days;
        }
        ksort($this->items);

        return $this;
    }

    private function reset(): void
    {
        $this->items = [];
    }

    /** sha256 of everything the plan was calculated from, in a stable order. */
    public function fingerprint(): string
    {
        $items = array_map(static fn (MpsItemInput $i) => $i->snapshot(), $this->items);

        return hash('sha256', (string) json_encode([
            'today' => $this->today, 'horizonStart' => $this->horizonStart, 'weeks' => self::WEEKS,
            'exceptions' => $this->days->exceptions(), 'items' => $items,
        ], JSON_THROW_ON_ERROR));
    }

    /** The item's inputs, started at its first demand. */
    private function item(stdClass $r): MpsItemInput
    {
        return $this->items[(string) $r->item_id] ??= new MpsItemInput((string) $r->item_id, (string) $r->uom_id);
    }

    /**
     * Forecast of a week is due on its first working day (from today in the current week); a week with no working day
     * left (today is its Sunday, or all remaining days are holidays) keeps it on its first remaining day, never the next week.
     */
    private function firstWorkingDay(string $monday): string
    {
        $first = max($monday, $this->today);
        $sunday = (new DateTimeImmutable($monday))->modify('+6 days')->format('Y-m-d');
        for ($d = new DateTimeImmutable($first); $d->format('Y-m-d') <= $sunday; $d = $d->modify('+1 day')) {
            if ($this->days->isWorking($d->format('Y-m-d'))) {
                return $d->format('Y-m-d');
            }
        }

        return $first;
    }

    /**
     * The plant's working days with the exceptions of each date from the start of the lead-time look-back to the horizon
     * end, each from the business calendar version in force on that date (latest plant override, else latest tenant
     * default, as BusinessCalendar): a version taking effect inside the horizon brings its own exceptions from its date on.
     */
    public static function workingDays(EoraPostgresConnection $db, string $plant, string $horizonStart, string $horizonEnd): WorkingDays
    {
        // a lead time of up to 365 working days (Mon–Sat, plus holidays) reaches back at most ~ 1.25 years: load 2
        $from = (new DateTimeImmutable($horizonStart))->modify('-730 days')->format('Y-m-d');
        $versions = $db->select(
            'select id, plant_id is not null as override, effective_from::text as since from business_calendars
              where (plant_id = ? or plant_id is null) and effective_from <= ?::date order by effective_from desc',
            [$plant, $horizonEnd],
        );
        if ($versions === []) {
            return new WorkingDays;
        }
        $byCalendar = [];
        foreach ($db->select(
            'select calendar_id, exception_date::text as d, kind from calendar_exceptions where calendar_id in ('.implode(',', array_fill(0, count($versions), '?')).')
               and exception_date between ?::date and ?::date',
            [...array_map(static fn (stdClass $v) => $v->id, $versions), $from, $horizonEnd],
        ) as $r) {
            $byCalendar[(string) $r->calendar_id][(string) $r->d] = (string) $r->kind;
        }
        $out = [];
        for ($d = new DateTimeImmutable($from); $d->format('Y-m-d') <= $horizonEnd; $d = $d->modify('+1 day')) {
            $day = $d->format('Y-m-d');
            $calendar = self::inForce($versions, $day);
            if ($calendar !== null && isset($byCalendar[$calendar][$day])) {
                $out[$day] = $byCalendar[$calendar][$day];
            }
        }

        return new WorkingDays($out);
    }

    /**
     * The id of the version in force on $day among versions ordered by effective date descending: plant override first.
     *
     * @param  array<int, stdClass>  $versions  rows with id, override, since
     */
    public static function inForce(array $versions, string $day): ?string
    {
        foreach ([true, false] as $override) {
            foreach ($versions as $v) {
                if ((bool) $v->override === $override && (string) $v->since <= $day) {
                    return (string) $v->id;
                }
            }
        }

        return null;
    }
}
