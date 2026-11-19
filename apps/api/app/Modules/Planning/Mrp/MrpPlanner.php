<?php

declare(strict_types=1);

namespace App\Modules\Planning\Mrp;

use App\Modules\Inventory\Application\MaterialOwnership;
use App\Modules\Planning\Mps\MpsEngine;
use App\Modules\Planning\Mps\MpsInputs;
use App\Modules\Planning\Mps\MpsItemInput;
use App\Modules\Planning\Mps\WorkingDays;
use App\Platform\Database\EoraPostgresConnection;
use App\Platform\Exceptions\DomainProblem;
use DateTimeImmutable;
use stdClass;

/**
 * Basic MRP of one run (doc 34 §1.4, D-124, D-059, BR-PLN-06), written into the run's rows as it goes:
 * - level 0: the planned orders of the approved MPS (finished goods, already netted by the MPS);
 * - each order is exploded through the BOM version in force on its start date: per line, order qty × gross quantity per
 *   piece (scrap allowance already in it, applied once, #68), converted to the component's base unit, due on the order's
 *   start date;
 * - components are planned by low-level code (deepest use), each with the MPS netting (MpsEngine: day by day, lot-for-lot
 *   per week, lead time in working days): semi-finished (MAKE) → planned child work orders, exploded in turn; raw
 *   material / consumable (BUY) → the shortage list, need date and order-by date = need − purchase lead time;
 * - supply: on hand UNRESTRICTED at the plant (not at production, staging, in transit or virtual) minus what released
 *   work orders still need of it (committed; a WO released from the plan and still Draft through the BOM in force), plus
 *   supplier inbound (open, on its expected date) and the remaining output of released work orders and of WOs from the
 *   plan (on their due date, D-125); safety stock per item + plant.
 * An item the MPS plans keeps its MPS orders; dependent demand on it is netted against the MPS's own position.
 */
final class MrpPlanner
{
    private MpsInputs $live;

    public function __construct(private readonly MpsInputs $inputs) {}

    private const array OPEN_WO = ['RELEASED', 'IN_PROGRESS', 'ON_HOLD'];

    private const int MAX_LEVEL = 20;

    private string $runId;

    private string $plant;

    private string $today;

    private string $horizonStart;

    private int $weeks;

    private WorkingDays $days;

    /** @var array<string, string> item id => its mrp_items row id (written when the item is planned) */
    private array $rows = [];

    /** @var array<string, list<array{parent: string, line: string, date: string, qty: numeric-string}>> item id => dependent demand not yet written */
    private array $pending = [];

    /** @var array<string, stdClass> Both item IDs point to the same effective paired routing. */
    private array $pairs = [];

    /** @var array<string, array<string, true>> item id => warnings found while reading its inputs */
    private array $itemWarnings = [];

    /**
     * Plans the run from the planned orders of its MPS.
     */
    public function run(EoraPostgresConnection $db, string $runId, string $plant, string $mpsVersionId, string $today, string $horizonStart, int $weeks): void
    {
        MaterialOwnership::requireAggregatePlanning($db, $plant);
        [$this->runId, $this->plant, $this->today, $this->horizonStart, $this->weeks] = [$runId, $plant, $today, $horizonStart, $weeks];
        $end = (new DateTimeImmutable($horizonStart))->modify('+'.(7 * $weeks - 1).' days')->format('Y-m-d');
        $this->days = MpsInputs::workingDays($db, $plant, $horizonStart, $end);
        $this->live = $this->inputs->load($db, $plant, $horizonStart, $weeks);
        $this->reset();
        $this->pairs = [];
        foreach ($db->select(
            "select v.id, r.item_id, v.paired_item_id, v.pairs_per_sheet
               from routings r join routing_versions v on v.routing_id = r.id
              where r.plant_id = ? and r.routing_kind = 'PRIMARY' and r.status = 'ACTIVE' and v.paired_item_id is not null
                and v.status in ('APPROVED', 'SUPERSEDED') and v.effective_from <= ?::date and (v.effective_to is null or v.effective_to >= ?::date)
              order by v.effective_from",
            [$plant, $end, $today],
        ) as $pair) {
            $existing = $this->pairs[$pair->item_id] ?? $this->pairs[$pair->paired_item_id] ?? null;
            if ($existing !== null && ($existing->item_id !== $pair->item_id || $existing->paired_item_id !== $pair->paired_item_id)) {
                throw new DomainProblem(409, 'INVALID_STATE_TRANSITION', 'Overlapping paired routings', 'Each item must belong to only one output pair at a plant in this horizon.');
            }
            $this->pairs[$pair->item_id] = $this->pairs[$pair->paired_item_id] = $pair;
        }

        // the MPS's items (with or without planned orders) and its planned orders
        $mps = [];
        foreach ($db->select('select id, item_id from mps_items where mps_version_id = ? order by item_id', [$mpsVersionId]) as $r) {
            $mps[(string) $r->item_id] = (string) $r->id;
        }
        $orders = [];
        foreach ($db->select(
            'select o.id, mi.item_id, trim_scale(o.qty)::text as qty, o.due_date::text as due, o.start_date::text as start, o.week_start::text as week, o.warnings::text as warnings
               from mps_planned_orders o join mps_items mi on mi.id = o.mps_item_id where o.mps_version_id = ? order by mi.item_id, o.week_start',
            [$mpsVersionId],
        ) as $o) {
            $orders[(string) $o->item_id][] = ['mps' => (string) $o->id, 'qty' => MpsItemInput::num($o->qty), 'due' => (string) $o->due,
                'start' => (string) $o->start, 'week' => (string) $o->week, 'warnings' => self::list((string) $o->warnings)];
        }
        // materials with a safety stock are planned even without requirement (and, below, components of live work orders)
        $safety = array_map(static fn (stdClass $r) => (string) $r->item_id, $db->select(
            "select p.item_id from item_plan_parameters p join items i on i.id = p.item_id
              where p.plant_id = ? and p.safety_stock > 0 and i.status = 'ACTIVE' and i.item_type in ('SEMI_FINISHED', 'RAW', 'CONSUMABLE') order by p.item_id",
            [$plant],
        ));
        // components live work orders still need (material lines of released ones, the BOM of Drafts from the plan) are
        // planned even without new requirement: their need is committed against stock
        foreach ($db->select(
            "select distinct m.component_item_id as item_id from work_order_materials m join work_orders w on w.id = m.work_order_id
              where w.plant_id = ? and w.status in ('".implode("', '", self::OPEN_WO)."')
             union
             select distinct l.component_item_id from work_orders w
               join lateral (select v.id from bom_versions v where v.item_id = w.item_id and v.status in ('APPROVED', 'SUPERSEDED')
                               and v.effective_from <= ?::date and (v.effective_to is null or v.effective_to >= ?::date) order by v.effective_from desc limit 1) b on true
               join bom_lines l on l.bom_version_id = b.id join items i on i.id = l.component_item_id
              where w.plant_id = ? and w.status in ('DRAFT', 'SUBMITTED') and (w.mrp_order_id is not null or exists (select 1 from daily_recovery_plans dp where dp.work_order_id = w.id)) and i.item_type <> 'SCRAP'",
            [$plant, $today, $today, $plant],
        ) as $r) {
            $safety[] = (string) $r->item_id;
        }
        $safety = array_values(array_unique($safety));
        // every item once, by low-level code: all its parents are exploded before it is netted
        $levels = $this->levels($db, array_values(array_unique([...array_keys($mps), ...$safety])));
        foreach ($safety as $item) {
            $levels[$item] ??= 1;
        }
        foreach ($this->pairs as $pair) {
            $main = (string) $pair->item_id;
            $other = (string) $pair->paired_item_id;
            if (isset($levels[$main]) || isset($levels[$other])) {
                $levels[$main] = $levels[$other] = max($levels[$main] ?? 0, $levels[$other] ?? 0);
            }
        }
        $byLevel = [];
        foreach ($levels as $item => $level) {
            $byLevel[$level][] = (string) $item;
        }
        ksort($byLevel);
        $safetySet = array_flip($safety);
        foreach ($byLevel as $level => $items) {
            sort($items);
            foreach ($items as $item) {
                if (isset($this->pairs[$item])) {
                    $pair = $this->pairs[$item];
                    $primary = (string) $pair->item_id;
                    $other = (string) $pair->paired_item_id;
                    if ($item === $primary && (isset($this->pending[$primary]) || isset($this->pending[$other])
                        || isset($safetySet[$primary]) || isset($safetySet[$other]))) {
                        $this->planPair($db, $pair, $level);
                    }
                } elseif (isset($mps[$item])) {
                    $this->planMpsItem($db, $item, $level, $orders[$item] ?? []);
                } elseif (isset($this->pending[$item]) || isset($safetySet[$item])) {
                    $this->plan($db, $item, $level);
                }
            }
        }
        // a component outside the BOM graph (not expected: the graph is a superset of the BOMs used), and its descendants
        for ($guard = 0; $this->pending !== [] && $guard < 1000; $guard++) {
            $item = (string) array_key_first($this->pending);
            $this->plan($db, $item, self::MAX_LEVEL, isset($this->rows[$item]));
        }
    }

    /**
     * An item of the MPS: its MPS planned orders are written (and exploded) as approved; dependent demand on it is netted
     * together with its independent demand against its live position and the MPS orders not yet released (as supply), so
     * stock its independent demand uses is never counted twice; what is still short becomes MRP orders on top.
     *
     * @param  list<array{mps: string, qty: numeric-string, due: string, start: string, week: string, warnings: list<string>}>  $orders
     */
    private function planMpsItem(EoraPostgresConnection $db, string $item, int $level, array $orders): void
    {
        $kind = $this->kindOf($db, $item);
        // the item's live position as the MPS reads it: stock, allocations beyond the horizon, safety stock, its independent
        // demand (firm + net forecast) and supply (QC released, every live work order incl. Drafts from the plan)
        $live = $this->live->items[$item] ?? null;
        $own = $this->input($db, $item, $kind);
        $in = new MpsItemInput($item, $live->uomId ?? $own->uomId);
        $in->onHand = $live->onHand ?? $own->onHand;
        $in->safetyStock = $live === null ? $own->safetyStock : $live->safetyStock;
        $in->leadTimeDays = $live === null ? $own->leadTimeDays : $live->leadTimeDays;
        // what released work orders still need of it, on top of the allocations beyond the horizon
        $in->outOfScope = bcadd($live->outOfScope ?? '0', $own->outOfScope, 6);
        $drafts = $own->demands; // what Drafts from the plan need of it (dated)
        $in->demands = $drafts;
        $row = $this->insertItem($db, $item, $kind, $level, $in);
        $unreleased = [];
        foreach ($orders as $o) {
            // an MPS order already released to a live work order (from any run of this MPS) is that work order now: its
            // components count through the work order, it is not exploded again, and it supplies as the work order (D-125)
            $converted = $db->selectOne(
                "select 1 as ok from work_orders w join mrp_orders x on x.id = w.mrp_order_id where w.status <> 'CANCELLED' and x.mps_planned_order_id = ?",
                [$o['mps']],
            ) !== null;
            $this->makeOrder($db, $item, 'MPS', $o['mps'], $o['week'], $o['qty'], $o['due'], $o['start'], $o['warnings'], ! $converted);
            if (! $converted) {
                $unreleased[] = ['key' => 'mps:'.$o['mps'], 'kind' => 'MPS', 'date' => $o['due'], 'qty' => $o['qty']];
            }
        }
        if (! isset($this->pending[$item]) && $drafts === []) {
            return;
        }
        foreach ($live === null ? [] : $live->demands as $d) {
            $in->demands[] = [...$d, 'key' => 'mps:'.$d['key']];
        }
        $in->supplies = [...($live === null ? $own->supplies : $live->supplies), ...$unreleased];
        $this->netDependent($db, $item, $kind, $row, $in, false);
    }

    /**
     * Nets an item's dependent demand against its supply and writes its rows (MAKE orders exploded in turn). With
     * $dependentOnly (an item already in the run, e.g. planned by the MPS) its stock and supply already serve that plan:
     * the dependent demand is planned on top, from nothing.
     */
    private function plan(EoraPostgresConnection $db, string $item, int $level, bool $dependentOnly = false): void
    {
        $kind = $this->kindOf($db, $item);
        if ($dependentOnly && isset($this->rows[$item])) {
            $input = new MpsItemInput($item, (string) $db->selectOne('select base_uom_id from items where id = ?', [$item])->base_uom_id);
            $p = $db->selectOne('select production_lead_time_days as made, purchase_lead_time_days as bought from item_plan_parameters where plant_id = ? and item_id = ?', [$this->plant, $item]);
            $lead = $p === null ? null : ($kind === 'MAKE' ? $p->made : $p->bought);
            $input->leadTimeDays = $lead === null ? null : (int) $lead;
            $row = $this->rows[$item];
        } else {
            $input = $this->input($db, $item, $kind);
            $row = $this->insertItem($db, $item, $kind, $level, $input);
        }
        $this->netDependent($db, $item, $kind, $row, $input, true);
    }

    /**
     * Writes the item's pending requirements, nets them (with $input's own demand and supply) and writes the resulting
     * orders and pegs; MAKE orders are exploded in turn. $writeSupply: the input's supply is the run's own (not the MPS's).
     */
    private function netDependent(EoraPostgresConnection $db, string $item, string $kind, string $row, MpsItemInput $input, bool $writeSupply): void
    {
        $this->recordRequirements($db, $item, $row, $input);
        // a work order counts in its unit's decimals: MAKE shortages are covered in whole units (the rest carries on)
        $plan = (new MpsEngine($this->days))->plan($input->forEngine(), $this->horizonStart, $this->weeks, $this->today, $kind === 'MAKE' ? $this->decimals($db, $item) : null);
        foreach ($writeSupply ? $plan['supplies'] : [] as $s) {
            $db->insert(
                'insert into mrp_supplies (tenant_id, mrp_run_id, mrp_item_id, kind, inbound_line_id, work_order_id, supply_date, qty)
                 values ((select c.tenant from ctx_api.context() c), ?, ?, ?, ?, ?, ?, ?)',
                [$this->runId, $row, $s['kind'], $s['kind'] === 'INBOUND' ? $s['key'] : null, $s['kind'] === 'WORK_ORDER' ? $s['key'] : null, $s['date'], $s['qty']],
            );
        }
        foreach ($plan['plannedOrders'] as $o) {
            $warnings = array_values(array_filter([$o['startDate'] < $this->today ? 'LATE_START' : null, $input->leadTimeDays === null ? 'LEAD_TIME_NOT_CONFIGURED' : null]));
            $id = $kind === 'MAKE'
                ? $this->makeOrder($db, $item, 'MRP', null, $o['weekStart'], $o['qty'], $o['dueDate'], $o['startDate'], $warnings)
                : $this->insertOrder($db, $item, 'BUY', 'MRP', null, $o['weekStart'], $o['qty'], $o['dueDate'], $o['startDate'], null, $warnings);
            $this->recordPegs($db, $id, $o['pegs']);
        }
    }

    private function recordRequirements(EoraPostgresConnection $db, string $item, string $row, MpsItemInput $input): void
    {
        foreach ($this->pending[$item] ?? [] as $d) {
            $req = (string) $db->selectOne(
                'insert into mrp_requirements (tenant_id, mrp_run_id, mrp_item_id, parent_order_id, bom_line_id, due_date, qty)
                 values ((select c.tenant from ctx_api.context() c), ?, ?, ?, ?, ?, ?) returning id',
                [$this->runId, $row, $d['parent'], $d['line'], $d['date'], $d['qty']],
            )->id;
            $input->demands[] = ['key' => $req, 'kind' => 'FIRM', 'date' => $d['date'], 'qty' => $d['qty']];
        }
        unset($this->pending[$item]);
    }

    /** @param list<array{demand: ?string, qty: numeric-string}> $pegs */
    private function recordPegs(EoraPostgresConnection $db, string $id, array $pegs): void
    {
        foreach ($pegs as $p) {
            if ($p['demand'] !== null && (str_starts_with($p['demand'], 'mps:') || str_starts_with($p['demand'], 'wo:'))) {
                continue; // the MPS's own demand, or a work order's need: pegged there, not to a requirement of this run
            }
            $db->insert(
                'insert into mrp_pegs (tenant_id, mrp_run_id, order_id, requirement_id, qty) values ((select c.tenant from ctx_api.context() c), ?, ?, ?, ?)',
                [$this->runId, $id, $p['demand'], $p['qty']],
            );
        }
    }

    /** Two independently netted items share each planned blanking order and its whole sheets. */
    private function planPair(EoraPostgresConnection $db, stdClass $pair, int $level): void
    {
        $primary = (string) $pair->item_id;
        $sides = [];
        $engine = new MpsEngine($this->days);
        foreach ([$primary, (string) $pair->paired_item_id] as $item) {
            $input = $this->input($db, $item, 'MAKE');
            $row = $this->insertItem($db, $item, 'MAKE', $level, $input);
            $this->recordRequirements($db, $item, $row, $input);
            foreach ($input->supplies as $s) {
                $db->insert(
                    'insert into mrp_supplies (tenant_id, mrp_run_id, mrp_item_id, kind, inbound_line_id, work_order_id, supply_date, qty)
                     values ((select c.tenant from ctx_api.context() c), ?, ?, ?, ?, ?, ?, ?)',
                    [$this->runId, $row, $s['kind'], $s['kind'] === 'INBOUND' ? $s['key'] : null, $s['kind'] === 'WORK_ORDER' ? $s['key'] : null, $s['date'], $s['qty']],
                );
            }
            $sides[$item] = $input;
        }
        $lead = $sides[$primary]->leadTimeDays;
        for ($week = 0; $week < $this->weeks; $week++) {
            $weekStart = (new DateTimeImmutable($this->horizonStart))->modify("+{$week} weeks")->format('Y-m-d');
            $due = null;
            $need = '0';
            $pegs = [];
            $safety = '0';
            foreach ($sides as $input) {
                $plan = $engine->plan($input->forEngine(), $this->horizonStart, $this->weeks, $this->today, 0);
                foreach ($plan['plannedOrders'] as $o) {
                    if ($o['weekStart'] === $weekStart) {
                        $due = $due === null ? $o['dueDate'] : min($due, $o['dueDate']);
                        $need = bccomp($need, $o['qty'], 6) < 0 ? $o['qty'] : $need;
                        foreach ($o['pegs'] as $peg) {
                            if ($peg['demand'] === null) {
                                $safety = bccomp($safety, $peg['qty'], 6) < 0 ? $peg['qty'] : $safety;
                            } else {
                                $pegs[] = $peg;
                            }
                        }
                    }
                }
            }
            if ($due === null) {
                continue;
            }
            $start = $this->days->back($due, $lead ?? 0);
            $effective = max($start, $this->today);
            $recipe = $db->selectOne(
                "select v.id, v.pairs_per_sheet from routings r join routing_versions v on v.routing_id = r.id
                  where r.plant_id = ? and r.item_id = ? and r.routing_kind = 'PRIMARY' and r.status = 'ACTIVE'
                    and v.paired_item_id = ? and v.status in ('APPROVED', 'SUPERSEDED')
                    and v.effective_from <= ?::date and (v.effective_to is null or v.effective_to >= ?::date)",
                [$this->plant, $primary, $pair->paired_item_id, $effective, $effective],
            );
            if ($recipe === null) {
                throw new DomainProblem(409, 'INVALID_STATE_TRANSITION', 'Paired routing is not in force', "Approve the paired routing in force on {$effective} before planning its output.");
            }
            $pieces = MpsItemInput::num((string) $recipe->pairs_per_sheet);
            $rounded = bcmul(bcdiv(bcadd($need, bcsub($pieces, '1', 0), 0), $pieces, 0), $pieces, 0);
            $warnings = array_values(array_filter([$start < $this->today ? 'LATE_START' : null, $lead === null ? 'LEAD_TIME_NOT_CONFIGURED' : null]));
            $id = $this->makeOrder($db, $primary, 'MRP', null, $weekStart, $rounded, $due, $start, $warnings, true, $recipe);
            if (bccomp($safety, '0', 6) > 0) {
                $pegs[] = ['demand' => null, 'qty' => $safety];
            }
            $this->recordPegs($db, $id, $pegs);
            foreach ($sides as $input) {
                $input->supplies[] = ['key' => $id, 'kind' => 'WORK_ORDER', 'date' => $due, 'qty' => $rounded];
            }
        }
    }

    /**
     * Writes a MAKE order with the BOM version in force on its start date and keeps its lines' requirements (order qty ×
     * gross quantity per piece, in the component's base unit, due on its start date) for the components' planning.
     *
     * @param  numeric-string  $qty
     * @param  list<string>  $warnings
     */
    private function makeOrder(EoraPostgresConnection $db, string $item, string $source, ?string $mps, string $week, string $qty, string $due, string $start, array $warnings, bool $explode = true, ?stdClass $pair = null): string
    {
        // an order whose start has passed starts today: the BOM in force today
        $on = max($start, $this->today);
        $bom = $db->selectOne(
            "select id from bom_versions where item_id = ? and status in ('APPROVED', 'SUPERSEDED')
                and effective_from <= ?::date and (effective_to is null or effective_to >= ?::date) order by effective_from desc limit 1",
            [$item, $on, $on],
        );
        $lines = [];
        if ($bom === null) {
            if ($pair !== null) {
                throw new DomainProblem(409, 'INVALID_STATE_TRANSITION', 'Paired BOM missing', 'Approve the blanking BOM before running MRP.');
            }
            $warnings[] = 'NO_BOM';
        } else {
            $components = $db->select(
                "select l.id, l.component_item_id, l.uom_id, trim_scale(l.gross_qty_per)::text as per, i.base_uom_id, i.item_type, u.code as unit
                   from bom_lines l join items i on i.id = l.component_item_id join uoms u on u.id = l.uom_id
                  where l.bom_version_id = ? and i.item_type <> 'SCRAP' order by l.line_no",
                [$bom->id],
            );
            if ($pair !== null && (count($components) !== 1 || $components[0]->unit !== 'SHEET'
                || $components[0]->item_type !== 'RAW' || $components[0]->uom_id !== $components[0]->base_uom_id)) {
                throw new DomainProblem(409, 'INVALID_STATE_TRANSITION', 'Invalid paired BOM', 'Paired blanking needs one raw material in its base unit SHEET.');
            }
            foreach ($components as $l) {
                $required = $pair === null ? bcmul($qty, MpsItemInput::num($l->per), 6) : bcdiv($qty, (string) $pair->pairs_per_sheet, 0);
                $need = $this->toBase($db, (string) $l->component_item_id, (string) $l->uom_id, (string) $l->base_uom_id, $required, $on);
                if ($need === null) {
                    $warnings[] = 'UOM_CONVERSION_MISSING';
                } elseif (bccomp($need, '0', 6) > 0) {
                    $lines[] = [(string) $l->component_item_id, (string) $l->id, $need];
                }
            }
        }
        $id = $this->insertOrder($db, $item, 'MAKE', $source, $mps, $week, $qty, $due, $start, $bom === null ? null : (string) $bom->id, array_values(array_unique($warnings)), $pair?->id);
        foreach ($explode ? $lines : [] as [$component, $line, $need]) {
            $this->pending[$component][] = ['parent' => $id, 'line' => $line, 'date' => $start, 'qty' => $need];
        }

        return $id;
    }

    /** The item's inputs: on hand, committed to released work orders, inbound / work order supply, parameters, demand. */
    private function input(EoraPostgresConnection $db, string $item, string $kind): MpsItemInput
    {
        $in = new MpsItemInput($item, (string) $db->selectOne('select base_uom_id from items where id = ?', [$item])->base_uom_id);
        $in->onHand = MpsItemInput::num($db->selectOne(
            "select coalesce(sum(b.qty), 0) as q from stock_balances b join locations l on l.id = b.location_id
              where b.plant_id = ? and b.item_id = ? and b.stock_status = 'UNRESTRICTED'
                and l.location_type not in ('MAT_AT_PROD', 'STAGING', 'CUSTODY_TRANSIT') and l.location_type not like 'V\\_%'",
            [$this->plant, $item],
        )->q);
        // what released work orders still need of it: required (in the base unit) − issued to them
        $committed = '0';
        $byOrder = [];
        foreach ($db->select(
            "select m.work_order_id, m.uom_id, trim_scale(m.required_qty)::text as required, i.base_uom_id, coalesce(w.released_at::date, w.created_at::date)::text as released,
                    coalesce((select sum(g.qty_signed) from inventory_movements g join locations gl on gl.id = g.location_id and gl.location_type = 'MAT_AT_PROD'
                               where g.wo_id = m.work_order_id and g.item_id = m.component_item_id and g.movement_code in ('ISSUE_TO_PROD', 'RETURN_FROM_PROD')), 0) as issued
               from work_order_materials m join work_orders w on w.id = m.work_order_id join items i on i.id = m.component_item_id
              where w.plant_id = ? and m.component_item_id = ? and w.status in ('".implode("', '", self::OPEN_WO)."')",
            [$this->plant, $item],
        ) as $m) {
            // a component on several lines of one work order: its lines add up, what was issued to it (net of returns) once
            $wo = (string) $m->work_order_id;
            // the conversion the work order was released with (else the latest one); none: not netted, said on the item
            $required = $this->toBase($db, $item, (string) $m->uom_id, (string) $m->base_uom_id, MpsItemInput::num($m->required), (string) $m->released)
                ?? $this->toBase($db, $item, (string) $m->uom_id, (string) $m->base_uom_id, MpsItemInput::num($m->required), null);
            if ($required === null) {
                $this->itemWarnings[$item]['UOM_CONVERSION_MISSING'] = true;

                continue;
            }
            $byOrder[$wo] = [bcadd($byOrder[$wo][0] ?? '0', $required, 6), MpsItemInput::num($m->issued)];
        }
        // work orders released from the plan, still Draft / Waiting for release, have no material lines yet: they need their
        // components through the BOM in force (the one their release will take), on their current start (D-125)
        foreach ($db->select(
            "select w.id, trim_scale(case when rv.pairs_per_sheet is null then w.qty * l.gross_qty_per else w.qty / rv.pairs_per_sheet end)::text as need, l.uom_id, i.base_uom_id, w.due_date::text as due,
                    (select p.production_lead_time_days from item_plan_parameters p where p.plant_id = w.plant_id and p.item_id = w.item_id) as lead
               from work_orders w
               join lateral (select v.id from bom_versions v where v.item_id = w.item_id and v.status in ('APPROVED', 'SUPERSEDED')
                               and v.effective_from <= ?::date and (v.effective_to is null or v.effective_to >= ?::date) order by v.effective_from desc limit 1) b on true
               join bom_lines l on l.bom_version_id = b.id and l.component_item_id = ?
               join items i on i.id = l.component_item_id
               left join mrp_orders mo on mo.id = w.mrp_order_id left join routing_versions rv on rv.id = mo.paired_routing_version_id
              where w.plant_id = ? and w.status in ('DRAFT', 'SUBMITTED')
                and (w.mrp_order_id is not null or exists (select 1 from daily_recovery_plans dp where dp.work_order_id = w.id))",
            [$this->today, $this->today, $item, $this->plant],
        ) as $d) {
            $need = $this->toBase($db, $item, (string) $d->uom_id, (string) $d->base_uom_id, MpsItemInput::num($d->need), $this->today);
            if ($need === null) {
                $this->itemWarnings[$item]['UOM_CONVERSION_MISSING'] = true;

                continue;
            }
            // needed on the Draft's start as it stands now (its due date − the item's production lead time), so dated supply
            // (a child work order, inbound) by then covers it and a rescheduled Draft moves its needs
            $start = $this->days->back((string) $d->due, $d->lead === null ? 0 : (int) $d->lead);
            $in->demands[] = ['key' => 'wo:'.$d->id.':'.count($in->demands), 'kind' => 'FIRM', 'date' => $start, 'qty' => $need];
        }
        foreach ($byOrder as [$required, $issued]) {
            $left = bcsub($required, $issued, 6);
            if (bccomp($left, '0', 6) > 0) {
                $committed = bcadd($committed, $left, 6);
            }
        }
        $in->outOfScope = $committed;
        foreach ($db->select(
            "select l.id, l.expected_date::text as d, trim_scale(l.qty - coalesce((select sum(m.qty_signed) from inbound_receipts r
                      join inventory_documents d on d.id = r.document_id and d.status = 'POSTED'
                      join inventory_movements m on m.document_id = d.id and m.qty_signed > 0 where r.inbound_line_id = l.id), 0))::text as q
               from supplier_inbound_lines l where l.plant_id = ? and l.item_id = ? and l.cancelled_at is null order by l.id",
            [$this->plant, $item],
        ) as $r) {
            if (bccomp(MpsItemInput::num($r->q), '0', 6) > 0) {
                $in->supplies[] = ['key' => (string) $r->id, 'kind' => 'INBOUND', 'date' => (string) $r->d, 'qty' => MpsItemInput::num($r->q)];
            }
        }
        foreach ($db->select(
            "select w.id, w.due_date::text as due, trim_scale(w.qty - g.good)::text as q
               from work_orders w
               left join mrp_orders mo on mo.id = w.mrp_order_id
               left join routing_versions rv on rv.id = coalesce(w.routing_version_id, mo.paired_routing_version_id)
               cross join lateral (select coalesce(sum(m.qty_signed), 0) as good from inventory_movements m
                                     join inventory_documents d on d.id = m.document_id and d.doc_type = 'PRODUCTION_POSTING' and d.status = 'POSTED'
                                     join production_runs r on r.id = d.source_id
                                    where r.work_order_id = w.id and m.item_id = ? and m.movement_code = 'OUTPUT_GOOD' and m.qty_signed > 0) g
              where w.plant_id = ? and (w.item_id = ? or rv.paired_item_id = ?) and (w.status in ('".implode("', '", self::OPEN_WO)."') or (w.status in ('DRAFT', 'SUBMITTED') and (w.mrp_order_id is not null or exists (select 1 from daily_recovery_plans dp where dp.work_order_id = w.id))))
                and w.qty > g.good order by w.id",
            [$item, $this->plant, $item, $item],
        ) as $w) {
            $in->supplies[] = ['key' => (string) $w->id, 'kind' => 'WORK_ORDER', 'date' => (string) $w->due, 'qty' => MpsItemInput::num($w->q)];
        }
        $p = $db->selectOne('select trim_scale(safety_stock)::text as ss, production_lead_time_days as made, purchase_lead_time_days as bought from item_plan_parameters where plant_id = ? and item_id = ?',
            [$this->plant, $item]);
        if ($p !== null) {
            $in->safetyStock = $p->ss === null ? null : MpsItemInput::num($p->ss);
            $lead = $kind === 'MAKE' ? $p->made : $p->bought;
            $in->leadTimeDays = $lead === null ? null : (int) $lead;
        }

        return $in;
    }

    /**
     * The quantity in the item's base unit: as is, or through the item's unit conversion in force on $date (null: the latest).
     *
     * @param  numeric-string  $qty
     * @return ?numeric-string null when no conversion exists
     */
    public function toBase(EoraPostgresConnection $db, string $item, string $uom, string $base, string $qty, ?string $date): ?string
    {
        if ($uom === $base) {
            return $qty;
        }
        $c = $db->selectOne(
            'select trim_scale(factor)::text as f, from_uom_id = ? as forward from uom_conversions
              where item_id = ? and ((from_uom_id = ? and to_uom_id = ?) or (from_uom_id = ? and to_uom_id = ?))
                and (?::date is null or (effective_from <= ?::date and (effective_to is null or effective_to >= ?::date))) order by effective_from desc limit 1',
            [$uom, $item, $uom, $base, $base, $uom, $date, $date, $date],
        );
        if ($c === null) {
            return null;
        }

        return (bool) $c->forward ? bcmul($qty, MpsItemInput::num($c->f), 6) : bcdiv($qty, MpsItemInput::num($c->f), 6);
    }

    /**
     * Low-level codes: 0 for the MPS items, then the deepest use of each component through the BOM versions that can be in
     * force in the horizon (approved or superseded, not ended before it).
     *
     * @param  list<string>  $roots
     * @return array<string, int>
     */
    private function levels(EoraPostgresConnection $db, array $roots): array
    {
        $children = [];
        foreach ($db->select(
            "select distinct v.item_id, l.component_item_id from bom_versions v join bom_lines l on l.bom_version_id = v.id join items i on i.id = l.component_item_id
              where v.status in ('APPROVED', 'SUPERSEDED') and (v.effective_to is null or v.effective_to >= ?::date - 730) and i.item_type <> 'SCRAP'",
            [$this->horizonStart],
        ) as $e) {
            $children[(string) $e->item_id][] = (string) $e->component_item_id;
        }
        foreach ($this->pairs as $pair) {
            $main = (string) $pair->item_id;
            $other = (string) $pair->paired_item_id;
            $children[$main] = $children[$other] = array_values(array_unique([...($children[$main] ?? []), ...($children[$other] ?? [])]));
        }
        $level = array_fill_keys($roots, 0);
        $frontier = $roots;
        for ($depth = 1; $frontier !== [] && $depth <= self::MAX_LEVEL; $depth++) {
            $next = [];
            foreach ($frontier as $parent) {
                foreach ($children[$parent] ?? [] as $child) {
                    if (($level[$child] ?? -1) < $depth) {
                        $level[$child] = $depth;
                        $next[$child] = true;
                    }
                }
            }
            $frontier = array_keys($next);
        }

        return $level;
    }

    private function reset(): void
    {
        $this->rows = [];
        $this->pending = [];
        $this->itemWarnings = [];
    }

    private function decimals(EoraPostgresConnection $db, string $item): int
    {
        return (int) $db->selectOne('select u.decimals from items i join uoms u on u.id = i.base_uom_id where i.id = ?', [$item])->decimals;
    }

    /** @return numeric-string what work orders from the plan still Draft need of the item (its `wo:` demand) */
    private function woNeed(MpsItemInput $in): string
    {
        $sum = '0';
        foreach ($in->demands as $d) {
            if (str_starts_with($d['key'], 'wo:')) {
                $sum = bcadd($sum, $d['qty'], 6);
            }
        }

        return $sum;
    }

    private function kindOf(EoraPostgresConnection $db, string $item): string
    {
        return in_array($db->selectOne('select item_type from items where id = ?', [$item])->item_type, ['FINISHED', 'SEMI_FINISHED'], true) ? 'MAKE' : 'BUY';
    }

    /** Writes the item's row with the values it was planned from. */
    private function insertItem(EoraPostgresConnection $db, string $item, string $kind, int $level, MpsItemInput $in): string
    {
        return $this->rows[$item] = (string) $db->selectOne(
            'insert into mrp_items (tenant_id, mrp_run_id, item_id, uom_id, kind, level, on_hand, committed, wo_need, safety_stock, lead_time_days, warnings)
             values ((select c.tenant from ctx_api.context() c), ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?::text[]) returning id',
            [$this->runId, $item, $in->uomId, $kind, min($level, self::MAX_LEVEL), $in->onHand, $in->outOfScope,
                $this->woNeed($in),
                $in->safetyStock, $in->leadTimeDays,
                '{'.implode(',', array_keys($this->itemWarnings[$item] ?? [])).'}'],
        )->id;
    }

    /** @param  list<string>  $warnings */
    private function insertOrder(EoraPostgresConnection $db, string $item, string $kind, string $source, ?string $mps, string $week, string $qty, string $due, string $start, ?string $bom, array $warnings, ?string $pairedRouting = null): string
    {
        return (string) $db->selectOne(
            'insert into mrp_orders (tenant_id, mrp_run_id, mrp_item_id, kind, source, mps_planned_order_id, week_start, qty, due_date, start_date, bom_version_id, warnings, paired_routing_version_id)
             values ((select c.tenant from ctx_api.context() c), ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?::text[], ?) returning id',
            [$this->runId, $this->rows[$item], $kind, $source, $mps, $week, $qty, $due, $start, $bom, '{'.implode(',', $warnings).'}', $pairedRouting],
        )->id;
    }

    /** @return list<string> */
    private static function list(string $literal): array
    {
        $inner = trim($literal, '{}');

        return $inner === '' ? [] : explode(',', $inner);
    }
}
