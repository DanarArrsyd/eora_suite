<?php

declare(strict_types=1);

namespace App\Modules\Planning\Mrp;

use App\Modules\Planning\Mps\MpsEngine;
use App\Modules\Planning\Mps\MpsInputs;
use App\Modules\Planning\Mps\MpsItemInput;
use App\Modules\Planning\Mps\WorkingDays;
use App\Platform\Database\EoraPostgresConnection;
use DateTimeImmutable;

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
 *   work orders still need of it (committed), plus supplier inbound (open, on its expected date) and the remaining output
 *   of released work orders (on their due date); safety stock per item + plant.
 * An item the MPS plans (independent demand) is not netted again here.
 */
final class MrpPlanner
{
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

    /**
     * Plans the run from the planned orders of its MPS.
     */
    public function run(EoraPostgresConnection $db, string $runId, string $plant, string $mpsVersionId, string $today, string $horizonStart, int $weeks): void
    {
        [$this->runId, $this->plant, $this->today, $this->horizonStart, $this->weeks] = [$runId, $plant, $today, $horizonStart, $weeks];
        $end = (new DateTimeImmutable($horizonStart))->modify('+'.(7 * $weeks - 1).' days')->format('Y-m-d');
        $this->days = MpsInputs::workingDays($db, $plant, $horizonStart, $end);
        $this->reset();

        $orders = [];
        foreach ($db->select(
            'select o.id, mi.item_id, trim_scale(o.qty)::text as qty, o.due_date::text as due, o.start_date::text as start, o.week_start::text as week, o.warnings::text as warnings
               from mps_planned_orders o join mps_items mi on mi.id = o.mps_item_id where o.mps_version_id = ? order by mi.item_id, o.week_start',
            [$mpsVersionId],
        ) as $o) {
            $orders[(string) $o->item_id][] = ['mps' => (string) $o->id, 'qty' => MpsItemInput::num($o->qty), 'due' => (string) $o->due,
                'start' => (string) $o->start, 'week' => (string) $o->week, 'warnings' => self::list((string) $o->warnings)];
        }
        $levels = $this->levels($db, array_map('strval', array_keys($orders)));
        // level 0: the MPS's planned orders, as planned there (an item the MPS plans is not netted again here)
        foreach ($orders as $item => $list) {
            $item = (string) $item;
            $this->insertItem($db, $item, 'MAKE', 0, $this->input($db, $item, 'MAKE'));
            foreach ($list as $o) {
                $this->makeOrder($db, $item, 'MPS', $o['mps'], $o['week'], $o['qty'], $o['due'], $o['start'], $o['warnings']);
            }
        }
        // components deepest-use last: all their parents are exploded before they are netted
        $byLevel = [];
        foreach ($levels as $item => $level) {
            if ($level > 0 && ! isset($orders[$item])) {
                $byLevel[$level][] = (string) $item;
            }
        }
        ksort($byLevel);
        foreach ($byLevel as $level => $items) {
            sort($items);
            foreach ($items as $item) {
                if (isset($this->pending[$item])) {
                    $this->plan($db, $item, $level);
                }
            }
        }
        // a component reached outside the levels (a BOM in force only later) and items with a safety stock are planned too
        foreach (array_keys($this->pending) as $item) {
            if (! isset($this->rows[$item])) {
                $this->plan($db, $item, self::MAX_LEVEL);
            }
        }
        foreach ($db->select(
            "select p.item_id from item_plan_parameters p join items i on i.id = p.item_id
              where p.plant_id = ? and p.safety_stock > 0 and i.status = 'ACTIVE' and i.item_type in ('SEMI_FINISHED', 'RAW', 'CONSUMABLE') order by p.item_id",
            [$plant],
        ) as $r) {
            if (! isset($this->rows[(string) $r->item_id]) && ! isset($orders[(string) $r->item_id])) {
                $this->plan($db, (string) $r->item_id, $levels[(string) $r->item_id] ?? 1);
            }
        }
    }

    /** Nets an item's dependent demand against its supply and writes its rows (MAKE orders exploded in turn). */
    private function plan(EoraPostgresConnection $db, string $item, int $level): void
    {
        $kind = $this->kindOf($db, $item);
        $input = $this->input($db, $item, $kind);
        $row = $this->insertItem($db, $item, $kind, $level, $input);
        foreach ($this->pending[$item] ?? [] as $d) {
            $req = (string) $db->selectOne(
                'insert into mrp_requirements (tenant_id, mrp_run_id, mrp_item_id, parent_order_id, bom_line_id, due_date, qty)
                 values ((select c.tenant from ctx_api.context() c), ?, ?, ?, ?, ?, ?) returning id',
                [$this->runId, $row, $d['parent'], $d['line'], $d['date'], $d['qty']],
            )->id;
            $input->demands[] = ['key' => $req, 'kind' => 'FIRM', 'date' => $d['date'], 'qty' => $d['qty']];
        }
        unset($this->pending[$item]);
        $plan = (new MpsEngine($this->days))->plan($input->forEngine(), $this->horizonStart, $this->weeks, $this->today);
        foreach ($plan['supplies'] as $s) {
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
            foreach ($o['pegs'] as $p) {
                $db->insert(
                    'insert into mrp_pegs (tenant_id, mrp_run_id, order_id, requirement_id, qty) values ((select c.tenant from ctx_api.context() c), ?, ?, ?, ?)',
                    [$this->runId, $id, $p['demand'], $p['qty']],
                );
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
    private function makeOrder(EoraPostgresConnection $db, string $item, string $source, ?string $mps, string $week, string $qty, string $due, string $start, array $warnings): string
    {
        $bom = $db->selectOne(
            "select id from bom_versions where item_id = ? and status in ('APPROVED', 'SUPERSEDED')
                and effective_from <= ?::date and (effective_to is null or effective_to >= ?::date) order by effective_from desc limit 1",
            [$item, $start, $start],
        );
        $lines = [];
        if ($bom === null) {
            $warnings[] = 'NO_BOM';
        } else {
            foreach ($db->select(
                "select l.id, l.component_item_id, l.uom_id, trim_scale(l.gross_qty_per)::text as per, i.base_uom_id
                   from bom_lines l join items i on i.id = l.component_item_id where l.bom_version_id = ? and i.item_type <> 'SCRAP' order by l.line_no",
                [$bom->id],
            ) as $l) {
                $need = $this->toBase($db, (string) $l->component_item_id, (string) $l->uom_id, (string) $l->base_uom_id, bcmul($qty, MpsItemInput::num($l->per), 6), $start);
                if ($need === null) {
                    $warnings[] = 'UOM_CONVERSION_MISSING';
                } elseif (bccomp($need, '0', 6) > 0) {
                    $lines[] = [(string) $l->component_item_id, (string) $l->id, $need];
                }
            }
        }
        $id = $this->insertOrder($db, $item, 'MAKE', $source, $mps, $week, $qty, $due, $start, $bom === null ? null : (string) $bom->id, array_values(array_unique($warnings)));
        foreach ($lines as [$component, $line, $need]) {
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
        foreach ($db->select(
            "select m.work_order_id, m.uom_id, trim_scale(m.required_qty)::text as required, i.base_uom_id,
                    coalesce((select sum(g.qty_signed) from inventory_movements g join locations gl on gl.id = g.location_id and gl.location_type = 'MAT_AT_PROD'
                               where g.wo_id = m.work_order_id and g.item_id = m.component_item_id and g.movement_code = 'ISSUE_TO_PROD'), 0) as issued
               from work_order_materials m join work_orders w on w.id = m.work_order_id join items i on i.id = m.component_item_id
              where w.plant_id = ? and m.component_item_id = ? and w.status in ('".implode("', '", self::OPEN_WO)."')",
            [$this->plant, $item],
        ) as $m) {
            $required = $this->toBase($db, $item, (string) $m->uom_id, (string) $m->base_uom_id, MpsItemInput::num($m->required), $this->today) ?? '0';
            $left = bcsub($required, MpsItemInput::num($m->issued), 6);
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
               cross join lateral (select coalesce(sum(m.qty_signed), 0) as good from inventory_movements m
                                     join inventory_documents d on d.id = m.document_id and d.doc_type = 'PRODUCTION_POSTING' and d.status = 'POSTED'
                                     join production_runs r on r.id = d.source_id
                                    where r.work_order_id = w.id and m.movement_code = 'OUTPUT_GOOD' and m.qty_signed > 0) g
              where w.plant_id = ? and w.item_id = ? and w.status in ('".implode("', '", self::OPEN_WO)."') and w.qty > g.good order by w.id",
            [$this->plant, $item],
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
     * The quantity in the item's base unit: as is, or through the item's unit conversion in force on $date.
     *
     * @param  numeric-string  $qty
     * @return ?numeric-string null when no conversion exists
     */
    private function toBase(EoraPostgresConnection $db, string $item, string $uom, string $base, string $qty, string $date): ?string
    {
        if ($uom === $base) {
            return $qty;
        }
        $c = $db->selectOne(
            'select trim_scale(factor)::text as f, from_uom_id = ? as forward from uom_conversions
              where item_id = ? and ((from_uom_id = ? and to_uom_id = ?) or (from_uom_id = ? and to_uom_id = ?))
                and effective_from <= ?::date and (effective_to is null or effective_to >= ?::date) order by effective_from desc limit 1',
            [$uom, $item, $uom, $base, $base, $uom, $date, $date],
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
              where v.status in ('APPROVED', 'SUPERSEDED') and (v.effective_to is null or v.effective_to >= ?::date) and i.item_type <> 'SCRAP'",
            [$this->horizonStart],
        ) as $e) {
            $children[(string) $e->item_id][] = (string) $e->component_item_id;
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
    }

    private function kindOf(EoraPostgresConnection $db, string $item): string
    {
        return in_array($db->selectOne('select item_type from items where id = ?', [$item])->item_type, ['FINISHED', 'SEMI_FINISHED'], true) ? 'MAKE' : 'BUY';
    }

    /** Writes the item's row with the values it was planned from. */
    private function insertItem(EoraPostgresConnection $db, string $item, string $kind, int $level, MpsItemInput $in): string
    {
        return $this->rows[$item] = (string) $db->selectOne(
            'insert into mrp_items (tenant_id, mrp_run_id, item_id, uom_id, kind, level, on_hand, committed, safety_stock, lead_time_days)
             values ((select c.tenant from ctx_api.context() c), ?, ?, ?, ?, ?, ?, ?, ?, ?) returning id',
            [$this->runId, $item, $in->uomId, $kind, min($level, self::MAX_LEVEL), $in->onHand, $in->outOfScope, $in->safetyStock, $in->leadTimeDays],
        )->id;
    }

    /** @param  list<string>  $warnings */
    private function insertOrder(EoraPostgresConnection $db, string $item, string $kind, string $source, ?string $mps, string $week, string $qty, string $due, string $start, ?string $bom, array $warnings): string
    {
        return (string) $db->selectOne(
            'insert into mrp_orders (tenant_id, mrp_run_id, mrp_item_id, kind, source, mps_planned_order_id, week_start, qty, due_date, start_date, bom_version_id, warnings)
             values ((select c.tenant from ctx_api.context() c), ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?::text[]) returning id',
            [$this->runId, $this->rows[$item], $kind, $source, $mps, $week, $qty, $due, $start, $bom, '{'.implode(',', $warnings).'}'],
        )->id;
    }

    /** @return list<string> */
    private static function list(string $literal): array
    {
        $inner = trim($literal, '{}');

        return $inner === '' ? [] : explode(',', $inner);
    }
}
