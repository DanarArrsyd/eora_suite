<?php

declare(strict_types=1);

namespace App\Modules\Planning\Recovery;

use App\Modules\Inventory\Application\MaterialOwnership;
use App\Modules\Planning\Mps\MpsItemInput;
use App\Platform\Calendar\BusinessCalendar;
use App\Platform\Database\EoraPostgresConnection;
use DateTimeImmutable;
use stdClass;

/** Live daily recovery. Allocate across the entire plant before customer/search filters and paging. */
final class DailyRecoveryPlanner
{
    public function __construct(private readonly BusinessCalendar $calendar, private readonly MaterialCapacity $materials) {}

    /**
     * @param  array<string, numeric-string>|null  $safetyStock  Generate SPK (D-139): item => safety stock. Each part's buffer is
     *                                                           raised to it before supply is allocated (G-8), and the material pool reserves what Generate makes
     *                                                           (the proposal, else the shortfall rounded up), so later parts see what is really left (G-9).
     * @return array<string,mixed>
     */
    public function read(EoraPostgresConnection $db, string $plant, string $target, ?array $safetyStock = null): array
    {
        MaterialOwnership::requireAggregatePlanning($db, $plant);
        $today = $this->calendar->resolve($db, $plant, new DateTimeImmutable)->productionDate;
        $parts = $db->select("select c.id as customer_id, c.code as customer_code, c.name as customer_name,
              i.id as item_id, i.code as item_code, i.name as item_name, min(ci.customer_part_no) as part_no
            from customer_items ci join customers c on c.id = ci.customer_id and c.status = 'ACTIVE'
            join items i on i.id = ci.item_id and i.status = 'ACTIVE' and i.item_type = 'FINISHED'
            join uoms u on u.id = i.base_uom_id and u.code = 'PCS'
            where ci.status = 'ACTIVE' and ci.effective_from <= ?::date and (ci.effective_to is null or ci.effective_to >= ?::date)
            group by c.id,c.code,c.name,i.id,i.code,i.name order by c.code,i.code", [$target, $target]);
        $saved = $db->select('select p.*, w.wo_no, w.status as wo_status from daily_recovery_plans p left join work_orders w on w.id = p.work_order_id
            where p.plant_id = ? and p.target_date = ?::date order by p.customer_id,p.item_id,p.revision', [$plant, $target]);
        $params = [];
        foreach ($saved as $plan) {
            $params[$plan->customer_id.'|'.$plan->item_id] = $plan;
        }
        $requirements = $db->select("select s.id, ol.item_id, h.customer_id, s.requirement_date::text as due, s.required_qty::text as required,
               greatest(0,s.required_qty - coalesce(delivered.qty,0))::text as outstanding,
               coalesce(delivered.today_qty,0)::text as shipped_today,
               coalesce(protected.qty,0)::text as protected_qty
            from delivery_schedule_lines s join sales_order_lines ol on ol.id = s.so_line_id
            join sales_order_headers h on h.id = ol.sales_order_id
            left join lateral (select sum(m.qty_signed) as qty,
                sum(m.qty_signed) filter (where d.production_date = ?::date) as today_qty
                from inventory_movements m join locations l on l.id = m.location_id and l.location_type = 'V_CUSTOMER'
                join inventory_documents d on d.id = m.document_id where m.schedule_line_id = s.id) delivered on true
            left join lateral (select sum(x.qty) as qty from (
                select a.qty from stock_allocations a join stock_balances b on b.id = a.balance_id
                 join locations l on l.id = b.location_id and l.location_type = 'FG_WAREHOUSE'
                 where a.demand_type = 'SCHEDULE_LINE' and a.demand_id = s.id and a.status = 'ACTIVE'
                   and b.plant_id = s.plant_id and b.stock_status = 'UNRESTRICTED'
                union all select m.qty_signed from inventory_movements m join locations l on l.id = m.location_id and l.location_type = 'STAGING'
                 where m.schedule_line_id = s.id and m.stock_status = 'UNRESTRICTED'
            ) x) protected on true
            where s.plant_id = ? and s.status in ('FIRM','PARTIALLY_FULFILLED','FULFILLED') and s.requirement_date <= ?::date
            order by s.requirement_date,s.id", [$today, $plant, $target]);
        $stocks = $db->select("select b.id, b.item_id, i.code as item_code, i.name as item_name, i.item_type,
              b.lot_id, lt.lot_no, b.stock_status, b.qty::text as qty, l.code as location_code, l.location_type,
              u.code as uom, coalesce(a.qty,0)::text as allocated
            from stock_balances b join locations l on l.id = b.location_id join items i on i.id = b.item_id
            join uoms u on u.id = b.uom_id left join lots lt on lt.id = b.lot_id
            left join lateral (select sum(qty) as qty from stock_allocations where balance_id = b.id and status = 'ACTIVE') a on true
            where b.plant_id = ? and b.qty > 0 and l.location_type not like 'V\\_%'
            order by b.item_id,b.id", [$plant]);
        $workOrders = $db->select("select w.id, w.wo_no, w.item_id, i.item_type, w.status, w.due_date::text as due,
               greatest(0,w.qty - coalesce(g.qty,0))::text as remaining, coalesce(h.customer_id,p.customer_id) as customer_id
            from work_orders w join items i on i.id = w.item_id left join delivery_schedule_lines s on s.id = w.schedule_line_id
            left join sales_order_lines ol on ol.id = s.so_line_id left join sales_order_headers h on h.id = ol.sales_order_id
            left join daily_recovery_plans p on p.work_order_id = w.id
            left join lateral (select sum(m.qty_signed) as qty from inventory_movements m
                join inventory_documents d on d.id = m.document_id and d.doc_type = 'PRODUCTION_POSTING' and d.status = 'POSTED'
                join production_runs r on r.id = d.source_id and r.work_order_id = w.id
                where m.item_id = w.item_id and m.movement_code = 'OUTPUT_GOOD' and m.qty_signed > 0) g on true
            where w.plant_id = ? and (w.status in ('RELEASED','IN_PROGRESS','ON_HOLD')
                 or (w.status in ('DRAFT','SUBMITTED') and (w.mrp_order_id is not null or p.id is not null)))
            order by w.due_date,w.id", [$plant]);
        $production = $db->select("select m.item_id, r.work_order_id, w.wo_no, lt.lot_no, sh.code as shift_code,
               d.production_date::text as date, d.doc_no, m.qty_signed::text as qty
            from inventory_movements m join inventory_documents d on d.id = m.document_id and d.doc_type = 'PRODUCTION_POSTING' and d.status = 'POSTED'
            join production_runs r on r.id = d.source_id join work_orders w on w.id = r.work_order_id
            left join lots lt on lt.id = m.lot_id left join shifts sh on sh.id = d.shift_id
            where m.plant_id = ? and d.production_date = ?::date and m.movement_code = 'OUTPUT_GOOD' and m.qty_signed > 0
            order by d.posted_at,m.id", [$plant, $today]);
        $commitments = $db->select("select wm.component_item_id as item_id,
              greatest(0,wm.required_qty - coalesce(issued.qty,0))::text as qty
            from work_order_materials wm join work_orders w on w.id = wm.work_order_id
            left join lateral (select sum(m.qty_signed) as qty from inventory_movements m join locations l on l.id = m.location_id
                where m.wo_id = w.id and m.item_id = wm.component_item_id and l.location_type = 'MAT_AT_PROD'
                  and m.movement_code in ('ISSUE_TO_PROD','RETURN_FROM_PROD')) issued on true
            where w.plant_id = ? and w.status in ('RELEASED','IN_PROGRESS','ON_HOLD') order by wm.id", [$plant]);
        $pool = [];
        $materialPool = [];
        foreach ($stocks as $stock) {
            $item = (string) $stock->item_id;
            $pool[$item] ??= ['fg' => '0', 'beforeCheck' => '0', 'accepted' => '0', 'wo' => '0'];
            if ($stock->stock_status === 'UNRESTRICTED' && $stock->location_type === 'FG_WAREHOUSE') {
                $pool[$item]['fg'] = bcadd($pool[$item]['fg'], bcsub(MpsItemInput::num($stock->qty), MpsItemInput::num($stock->allocated), 6), 6);
            } elseif ($stock->stock_status === 'PENDING_QC') {
                $pool[$item]['beforeCheck'] = bcadd($pool[$item]['beforeCheck'], MpsItemInput::num($stock->qty), 6);
            } elseif ($stock->stock_status === 'QC_RELEASED') {
                $pool[$item]['accepted'] = bcadd($pool[$item]['accepted'], MpsItemInput::num($stock->qty), 6);
            }
            if ($stock->location_type === 'RM_WAREHOUSE' && $stock->stock_status === 'UNRESTRICTED') {
                $materialPool[$item] = bcadd($materialPool[$item] ?? '0', bcsub(MpsItemInput::num($stock->qty), MpsItemInput::num($stock->allocated), 6), 6);
            }
        }
        foreach ($commitments as $commitment) {
            $item = (string) $commitment->item_id;
            $remaining = bcsub($materialPool[$item] ?? '0', MpsItemInput::num($commitment->qty), 6);
            $materialPool[$item] = bccomp($remaining, '0', 6) > 0 ? $remaining : '0';
        }
        // Finished draft WOs already cover demand. Keep their component forecast committed across plan dates, too.
        // Explode finished roots only: their intermediate MRP drafts represent the same material demand.
        foreach ($workOrders as $order) {
            if ($order->item_type === 'FINISHED' && in_array($order->status, ['DRAFT', 'SUBMITTED'], true)) {
                $this->materials->consume($db, $plant, (string) $order->item_id, (string) $order->remaining, $target, $materialPool, [], true);
            }
        }
        $boundWo = [];
        foreach ($workOrders as $order) {
            if ($order->due > $target) {
                continue;
            }
            if ($order->customer_id !== null) {
                $key = $order->customer_id.'|'.$order->item_id;
                $boundWo[$key] = bcadd($boundWo[$key] ?? '0', MpsItemInput::num($order->remaining), 6);
            } else {
                $item = (string) $order->item_id;
                $pool[$item] ??= ['fg' => '0', 'beforeCheck' => '0', 'accepted' => '0', 'wo' => '0'];
                $pool[$item]['wo'] = bcadd($pool[$item]['wo'], MpsItemInput::num($order->remaining), 6);
            }
        }
        $byPart = [];
        foreach ($requirements as $requirement) {
            $key = $requirement->customer_id.'|'.$requirement->item_id;
            $byPart[$key][] = $requirement;
        }
        usort($parts, static function (stdClass $a, stdClass $b) use ($byPart, $target): int {
            return [$byPart[$a->customer_id.'|'.$a->item_id][0]->due ?? $target, $a->customer_code, $a->item_code]
                <=> [$byPart[$b->customer_id.'|'.$b->item_id][0]->due ?? $target, $b->customer_code, $b->item_code];
        });
        $parameterFingerprint = array_map(static fn (stdClass $p) => [$p->customer_id, $p->item_id, $p->minimum_qty, $p->batch_qty,
            $p->buffer_qty, $p->shift_1_qty, $p->shift_2_qty, $p->shift_3_qty, $p->work_order_id], $saved);
        $bomInputs = $db->select("select v.id,v.item_id,l.component_item_id,l.gross_qty_per::text,l.uom_id from bom_versions v
            join bom_lines l on l.bom_version_id = v.id where v.status in ('APPROVED','SUPERSEDED') and v.effective_from <= ?::date
             and (v.effective_to is null or v.effective_to >= ?::date) order by v.id,l.id", [$target, $target]);
        $conversions = $db->select('select id,item_id,from_uom_id,to_uom_id,factor::text from uom_conversions
            where effective_from <= ?::date and (effective_to is null or effective_to >= ?::date) order by id', [$target, $target]);
        $paired = $db->select("select r.item_id,v.paired_item_id,v.pairs_per_sheet,v.id from routings r join routing_versions v on v.routing_id = r.id
            where r.plant_id = ? and r.status = 'ACTIVE' and r.routing_kind = 'PRIMARY' and v.paired_item_id is not null
             and v.status in ('APPROVED','SUPERSEDED') and v.effective_from <= ?::date and (v.effective_to is null or v.effective_to >= ?::date) order by v.id", [$plant, $target, $target]);
        $fingerprint = hash('sha256', json_encode([$plant, $today, $target, $requirements, $stocks, $workOrders, $production, $commitments, $parameterFingerprint, $bomInputs, $conversions, $paired], JSON_THROW_ON_ERROR));
        $rows = [];
        $floorTaken = [];
        // the day a work order release snapshots its masters (WorkOrderController::today)
        $releaseDay = $this->calendar->resolve($db, $plant, new DateTimeImmutable)->calendarDate;
        foreach ($parts as $part) {
            $key = $part->customer_id.'|'.$part->item_id;
            $p = $params[$key] ?? null;
            $demands = $byPart[$key] ?? [];
            $required = $this->sum($demands, 'outstanding');
            $protected = $this->sum($demands, 'protected_qty');
            $buffer = MpsItemInput::num($p->buffer_qty ?? 0);
            // safety stock is the item's: it raises the buffer of the item's first part only (earliest due), never once per customer
            $floor = isset($floorTaken[(string) $part->item_id]) ? null : ($safetyStock[(string) $part->item_id] ?? null);
            $floorTaken[(string) $part->item_id] = true;
            if ($floor !== null && bccomp($floor, $buffer, 6) > 0) {
                $buffer = $floor;
            }
            $remaining = bcadd($required, $buffer, 6);
            $own = $boundWo[$key] ?? '0';
            $allocated = ['fg' => $protected, 'beforeCheck' => '0', 'accepted' => '0', 'wo' => $own];
            $remaining = bcsub(bcsub($remaining, $protected, 6), $own, 6);
            foreach (['fg', 'accepted', 'beforeCheck', 'wo'] as $stage) {
                $available = $pool[$part->item_id][$stage] ?? '0';
                $take = bccomp($remaining, '0', 6) <= 0 ? '0' : (bccomp($available, $remaining, 6) < 0 ? $available : $remaining);
                $allocated[$stage] = bcadd($allocated[$stage], $take, 6);
                $pool[$part->item_id][$stage] = bcsub($available, $take, 6);
                $remaining = bcsub($remaining, $take, 6);
            }
            $planQty = bcadd(bcadd(MpsItemInput::num($p->shift_1_qty ?? 0), MpsItemInput::num($p->shift_2_qty ?? 0), 0), MpsItemInput::num($p->shift_3_qty ?? 0), 0);
            $supply = array_reduce($allocated, static fn (string $total, string $qty): string => bcadd(MpsItemInput::num($total), MpsItemInput::num($qty), 6), '0');
            $calc = RecoveryCalculation::calculate($required, $supply, $buffer, (string) ($p->minimum_qty ?? 500),
                $p?->batch_qty === null ? null : (string) $p->batch_qty, $p?->work_order_id === null ? $planQty : '0');
            // Once linked, the shifts are history: their WO already contributes projected supply.
            $calc['planQty'] = $planQty;
            // Generate releases its WOs today, so it checks material against the BOM and conversions in force today (the snapshot)
            $materialDate = $safetyStock === null ? $target : $releaseDay;
            $capacity = $this->materials->estimate($db, $plant, (string) $part->item_id, $materialDate, $materialPool);
            // a linked plan's shifts are history; in Generate mode its residual need is still made, so it reserves material too
            $reserve = $p?->work_order_id === null && bccomp($planQty, '0', 0) > 0 ? $planQty
                : ($calc['proposedQty'] ?? ($safetyStock === null ? '0' : RecoveryCalculation::ceil($calc['netQty'])));
            if ($capacity !== null && ($p?->work_order_id === null || $safetyStock !== null) && bccomp($reserve, '0', 0) > 0) {
                $reserve = bccomp($reserve, $capacity, 0) < 0 ? $reserve : $capacity;
                $this->materials->consume($db, $plant, (string) $part->item_id, $reserve, $materialDate, $materialPool, []);
            }
            $stockRows = array_values(array_filter($stocks, static fn (stdClass $s): bool => $s->item_id === $part->item_id));
            $before = $this->sum(array_filter($stockRows, static fn (stdClass $s): bool => $s->stock_status === 'PENDING_QC'), 'qty');
            $accepted = $this->sum(array_filter($stockRows, static fn (stdClass $s): bool => $s->stock_status === 'QC_RELEASED'), 'qty');
            $fg = $this->sum(array_filter($stockRows, static fn (stdClass $s): bool => $s->location_type === 'FG_WAREHOUSE' && $s->stock_status === 'UNRESTRICTED'), 'qty');
            $status = $p?->work_order_id !== null ? 'WO_CREATED' : (bccomp($calc['netQty'], '0', 6) === 0
                ? (bccomp($allocated['beforeCheck'], '0', 6) > 0 ? 'WAIT_QC' : 'COVERED')
                : ($calc['proposedQty'] === null ? 'BATCH_MISSING' : ($capacity === null ? 'BOM_MISSING' : (bccomp($capacity, $calc['proposedQty'], 0) < 0 ? 'MATERIAL_SHORT' : 'PLAN_REQUIRED'))));
            $rows[] = ['id' => $key, 'planId' => $p?->id, 'revision' => (int) ($p->revision ?? 0), 'version' => (int) ($p->version ?? 0), 'customerId' => $part->customer_id,
                'customerCode' => $part->customer_code, 'customerName' => $part->customer_name, 'itemId' => $part->item_id,
                'itemCode' => $part->item_code, 'itemName' => $part->item_name, 'partNo' => $part->part_no,
                'requiredQty' => RecoveryCalculation::plain($required), 'backlogQty' => RecoveryCalculation::plain($this->sum(array_filter($demands, static fn (stdClass $d): bool => $d->due < $today), 'outstanding')),
                'shippedTodayQty' => RecoveryCalculation::plain($this->sum($demands, 'shipped_today')),
                'fgQty' => RecoveryCalculation::plain($fg), 'beforeCheckQty' => RecoveryCalculation::plain($before), 'acceptedQty' => RecoveryCalculation::plain($accepted),
                'allocated' => array_map(RecoveryCalculation::plain(...), $allocated), 'materialCapacityQty' => $capacity,
                'minimumQty' => (string) ($p->minimum_qty ?? 500), 'batchQty' => $p?->batch_qty === null ? null : (string) $p->batch_qty,
                'bufferQty' => $buffer, 'shift1Qty' => MpsItemInput::num($p->shift_1_qty ?? 0),
                'shift2Qty' => MpsItemInput::num($p->shift_2_qty ?? 0), 'shift3Qty' => MpsItemInput::num($p->shift_3_qty ?? 0),
                'reason' => $p?->reason, 'workOrderId' => $p?->work_order_id, 'workOrderCode' => $p?->wo_no,
                'workOrderStatus' => $p?->wo_status, 'status' => $status, 'inputsChanged' => $p !== null && $p->input_fingerprint !== $fingerprint,
                'lots' => $stockRows, 'demands' => $demands,
                'workOrders' => array_values(array_filter($workOrders, static fn (stdClass $w): bool => $w->item_id === $part->item_id && ($w->customer_id === null || $w->customer_id === $part->customer_id))),
                'production' => array_values(array_filter($production, static fn (stdClass $r): bool => $r->item_id === $part->item_id)), ...$calc];
        }

        return ['rows' => $rows, 'today' => $today, 'targetDate' => $target, 'fingerprint' => $fingerprint,
            'asOf' => $db->selectOne('select now()::text as t')->t, 'formulaCode' => 'DAILY_RECOVERY_V1'];
    }

    /**
     * @param  array<array-key,stdClass>  $rows
     * @return numeric-string
     */
    private function sum(array $rows, string $field): string
    {
        return array_reduce($rows, static fn (string $total, stdClass $row): string => bcadd(MpsItemInput::num($total), MpsItemInput::num($row->{$field}), 6), '0');
    }
}
