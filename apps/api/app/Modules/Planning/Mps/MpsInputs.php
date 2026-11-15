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
 *   the remaining output (qty − good posted) of RELEASED / IN_PROGRESS / ON_HOLD work orders on their due date;
 *   class C: Pending QC, shown only;
 * - per item + plant planning parameters (safety stock, production lead time; NULL = not configured), working days.
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

    public function load(EoraPostgresConnection $db, string $plant): self
    {
        $this->today = $this->calendar->resolve($db, $plant, new DateTimeImmutable)->calendarDate;
        $monday = (new DateTimeImmutable($this->today))->modify('monday this week');
        $this->horizonStart = $monday->format('Y-m-d');
        $this->horizonEnd = $monday->modify('+'.(7 * self::WEEKS - 1).' days')->format('Y-m-d');
        $this->days = new WorkingDays($this->exceptions($db, $plant));
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
              where w.plant_id = ? and w.item_id in ({$in}) and w.status in ('RELEASED', 'IN_PROGRESS', 'ON_HOLD') and w.qty > g.good
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

    /** @return array<string, string> the effective business calendar's exceptions (plant override, else tenant default) */
    private function exceptions(EoraPostgresConnection $db, string $plant): array
    {
        $calendar = $db->selectOne(
            'select id from business_calendars where (plant_id = ? or plant_id is null) and effective_from <= ?::date order by (plant_id is null), effective_from desc limit 1',
            [$plant, $this->today],
        );
        if ($calendar === null) {
            return [];
        }
        $out = [];
        foreach ($db->select(
            'select exception_date::text as d, kind from calendar_exceptions where calendar_id = ? and exception_date between ?::date - 366 and ?::date order by exception_date',
            [$calendar->id, $this->horizonStart, $this->horizonEnd],
        ) as $r) {
            $out[(string) $r->d] = (string) $r->kind;
        }

        return $out;
    }
}
