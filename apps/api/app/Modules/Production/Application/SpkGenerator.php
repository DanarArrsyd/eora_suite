<?php

declare(strict_types=1);

namespace App\Modules\Production\Application;

use App\Modules\Planning\Http\WorkOrderController;
use App\Modules\Planning\Recovery\DailyRecoveryPlanner;
use App\Modules\Planning\Recovery\RecoveryCalculation;
use App\Platform\Audit\AuditRecorder;
use App\Platform\Calendar\BusinessCalendar;
use App\Platform\Calendar\ShiftCalendar;
use App\Platform\Database\EoraPostgresConnection;
use App\Platform\Exceptions\DomainProblem;
use DateTimeImmutable;
use Illuminate\Validation\ValidationException;
use LogicException;
use stdClass;

/**
 * Generate SPK (doc 38 §12, D-139) for one plant and production date, inside the caller's transaction.
 *
 * 1. Need per finished item and customer = the Daily Recovery shortfall of the date with the buffer raised to the item's
 *    safety stock (the larger one, never both, G-1/G-8), min / batch as Daily Recovery rounds it, capped at what the
 *    material allows (G-9). A saved Daily Recovery shift plan without a WO is taken as the planner's quantity.
 * 2. Each need becomes a work order with reason SPK, released at once by the generating planner (G-2/G-6).
 * 3. Every released / in-production finished WO due by the date is planned for what no SPK plans yet, every operation on
 *    the same day after the one before it (G-3), in due-date order (G-4): preferred machine, then its other shifts, then
 *    the operation's alternative machines; one setup per item and operation on a machine; what still does not fit stays
 *    on the preferred machine's last shift and is proposed as overtime (G-5), flagged beyond the plant's limit.
 *
 * Lines go to the line's draft SPK of that date (created when missing, never to a released SPK). Nothing is released:
 * the planner reviews and releases each SPK.
 */
final class SpkGenerator
{
    private const string QC_MINUTES = '5';

    public function __construct(
        private readonly DailyRecoveryPlanner $recovery,
        private readonly WorkOrderController $workOrders,
        private readonly AuditRecorder $audit,
        private readonly BusinessCalendar $calendar,
        private readonly ShiftCalendar $shifts,
    ) {}

    /** @return array<string, list<array<string, mixed>>> spks, workOrders, overtime, materialShort, skipped */
    public function generate(EoraPostgresConnection $db, string $plant, string $date): array
    {
        $out = ['spks' => [], 'workOrders' => [], 'overtime' => [], 'materialShort' => [], 'skipped' => []];
        $this->createWorkOrders($db, $plant, $date, $out);

        $sheets = [];
        // the shifts of the day in the order they run (D-142: weekday times), each with its work hours
        /** @var list<array{id: ?string, code: ?string, hours: ?string}> $shifts */
        $shifts = array_map(static fn (array $s) => ['id' => $s['id'], 'code' => $s['code'], 'hours' => $s['workHours']], $this->shifts->shiftsOn($db, $plant, $date));
        if ($shifts === []) {
            $shifts = [['id' => null, 'code' => null, 'hours' => null]];
        }
        $today = $this->calendar->resolve($db, $plant, new DateTimeImmutable)->productionDate;
        $orders = $db->select(
            "select w.id, w.wo_no, w.item_id, i.code as item_code, trim_scale(w.qty)::text as qty, w.routing_version_id, v.paired_item_id, c.code as customer_code,
                    -- good output posted for the WO before today (runs without an SPK too); today's output is still inside today's plans
                    coalesce(g.qty, 0)::text as posted
               from work_orders w join items i on i.id = w.item_id and i.item_type = 'FINISHED'
               left join routing_versions v on v.id = w.routing_version_id left join customers c on c.id = w.production_customer_id
               left join lateral (select sum(m.qty_signed) as qty from inventory_movements m
                   join inventory_documents d on d.id = m.document_id and d.doc_type = 'PRODUCTION_POSTING' and d.status = 'POSTED'
                   join production_runs r on r.id = d.source_id and r.work_order_id = w.id
                  where m.item_id = w.item_id and m.movement_code = 'OUTPUT_GOOD' and m.qty_signed > 0 and d.production_date < ?::date) g on true
              where w.plant_id = ? and w.status in ('RELEASED', 'IN_PROGRESS') and w.due_date <= ?::date
              order by w.due_date, w.wo_no",
            [$today, $plant, $date],
        );
        foreach ($orders as $rank => $wo) {
            $ops = $db->select(
                "select o.id, o.op_seq, o.work_center_id, wc.code as work_center_code, wc.status as work_center_status, trim_scale(wc.available_hours_per_shift)::text as hours_per_shift,
                        trim_scale(o.std_setup_minutes)::text as setup_minutes, trim_scale(o.std_good_output_per_hour)::text as sph,
                        trim_scale(o.std_cycle_seconds)::text as cycle_seconds, o.cycle_basis, o.parts_per_cycle,
                        coalesce((select sum(l.planned_qty) from spk_lines l join spk_headers h on h.id = l.spk_id
                                   where l.work_order_id = ? and l.routing_operation_id = o.id
                                     -- a draft revision stands in for the released SPK it revises (it holds that plan and Generate adds to it)
                                     and (h.status = 'DRAFT' or (h.status in ('RELEASED', 'CLOSED')
                                          and not exists (select 1 from spk_headers r where r.previous_id = h.id and r.status = 'DRAFT')))), 0)::text as planned,
                        -- what earlier days planned is ready for the next operation today; later days' plans are not
                        coalesce((select sum(l.planned_qty) from spk_lines l join spk_headers h on h.id = l.spk_id
                                   where l.work_order_id = ? and l.routing_operation_id = o.id and h.production_date < ?::date
                                     and (h.status = 'DRAFT' or (h.status in ('RELEASED', 'CLOSED')
                                          and not exists (select 1 from spk_headers r where r.previous_id = h.id and r.status = 'DRAFT')))), 0)::text as planned_before,
                        -- plans from today on are work still to do: posted output before today does not overlap them
                        coalesce((select sum(l.planned_qty) from spk_lines l join spk_headers h on h.id = l.spk_id
                                   where l.work_order_id = ? and l.routing_operation_id = o.id and h.production_date >= ?::date
                                     and (h.status = 'DRAFT' or (h.status in ('RELEASED', 'CLOSED')
                                          and not exists (select 1 from spk_headers r where r.previous_id = h.id and r.status = 'DRAFT')))), 0)::text as planned_ahead
                   from routing_operations o join work_centers wc on wc.id = o.work_center_id
                  where o.routing_version_id = ? order by o.op_seq",
                [$wo->id, $wo->id, $date, $wo->id, $today, $wo->routing_version_id],
            );
            $after = 0; // G-3: a later operation never starts in an earlier shift than the one before it
            $cap = null; // G-3: nor plans more than the operation before it has planned
            $supply = null; // G-3: nor more by the end of any shift than the operation before it has made by then
            foreach ($ops as $op) {
                // this operation's lines on SPKs of the day, per shift; its lines on earlier days count as ready from the first shift,
                // its lines on later days cover the operation but are no input for the next one today
                $mine = $this->plannedByShift($db, (string) $wo->id, (string) $op->id, $date, $shifts);
                // output posted before today went through every operation; with the plans from today on it covers the operation
                // (its older SPK lines are the same pieces, so they do not add), and what exceeds the SPK lines is ready input
                $spk = SpkHours::num($op->planned);
                $done = bcadd(SpkHours::num($wo->posted), SpkHours::num($op->planned_ahead), 6);
                $has = bccomp($done, $spk, 6) > 0 ? $done : $spk;
                $mine[0] = bcadd(bcadd(SpkHours::num($mine[0] ?? '0'), SpkHours::num($op->planned_before), 6), bcsub($has, $spk, 6), 6);
                ksort($mine); // shift order: earlier days' output sits in the first shift
                $planned = array_keys(array_filter($mine, static fn (string $q): bool => bccomp(SpkHours::num($q), '0', 6) > 0))[0] ?? null;
                $whole = SpkHours::num($wo->qty);
                $target = $cap === null || bccomp($cap, $whole, 6) > 0 ? $whole : $cap;
                $qty = bcsub($target, $has, 6);
                $reach = bccomp($has, $target, 6) > 0 ? $has : $target; // what this operation will have once placed
                if (bccomp($reach, $whole, 6) < 0) {
                    $out['skipped'][] = ['workOrderCode' => $wo->wo_no, 'itemCode' => $wo->item_code, 'opSeq' => (int) $op->op_seq,
                        'reason' => SpkHours::plain(bcsub($whole, $reach, 6)).' not planned: the operation before it is not planned for them.'];
                }
                $cap = $has; // until it is placed below
                $placed = [];
                $skip = bccomp($qty, '0', 6) <= 0;
                if (! $skip && $op->work_center_status !== 'ACTIVE') {
                    // an inactive line takes no SPK: the operation waits, and so does what follows it
                    $out['skipped'][] = ['workOrderCode' => $wo->wo_no, 'itemCode' => $wo->item_code, 'opSeq' => (int) $op->op_seq,
                        'reason' => "Line {$op->work_center_code} is inactive."];
                    $skip = true;
                }
                if (! $skip) {
                    $sheet = $this->sheet($db, $plant, $date, $op, $shifts, $sheets);
                    $machines = $sheet['blocked'] === null ? $this->machines($db, $op) : [];
                    if ($sheet['blocked'] !== null || $machines === []) {
                        $out['skipped'][] = ['workOrderCode' => $wo->wo_no, 'itemCode' => $wo->item_code, 'opSeq' => (int) $op->op_seq,
                            'reason' => $sheet['blocked'] ?? "No active machine on {$op->work_center_code}."];
                        $skip = true;
                    } else {
                        $first = $this->place($sheets[$op->work_center_id], $wo, $op, $machines, SpkHours::plain($qty), min(99, $rank + 1), $after, $supply, $mine, $placed, $out);
                        $planned = $planned === null ? $first : min($first, $planned);
                    }
                }
                $after = max($after, $planned ?? $after);
                $total = $mine;
                foreach ($placed as $k => $q) {
                    $total[$k] = bcadd(SpkHours::num($total[$k] ?? '0'), SpkHours::num($q), 6);
                }
                $supply = $total;
                $cap = array_reduce($total, static fn (string $t, string $q): string => bcadd(SpkHours::num($t), SpkHours::num($q), 6), '0');
            }
        }
        $this->write($db, $plant, $date, $sheets, $out);
        $this->flagOvertime($db, $plant, $sheets, $out);

        return $out;
    }

    /** @param array<string, list<array<string, mixed>>> $out */
    private function createWorkOrders(EoraPostgresConnection $db, string $plant, string $date, array &$out): void
    {
        $safety = [];
        foreach ($db->select('select item_id, trim_scale(safety_stock)::text as qty from item_plan_parameters where plant_id = ? and safety_stock is not null', [$plant]) as $p) {
            $safety[(string) $p->item_id] = SpkHours::num($p->qty);
        }
        // the buffer is raised to the safety stock before supply is allocated, and each part reserves what Generate makes (G-8, G-9);
        // a part whose WO cannot be made gives its material back: Daily Recovery is read again without it, the parts already done skipped
        $done = [];
        $unmade = [];
        do {
            $again = false;
            $recovery = $this->recovery->read($db, $plant, $date, $safety, $unmade);
            foreach ($recovery['rows'] as $row) {
                $key = $row['customerId'].'|'.$row['itemId'];
                if (isset($done[$key]) || isset($unmade[$key])) {
                    continue;
                }
                $done[$key] = true;
                if (! $this->makeWorkOrder($db, $plant, $date, $row, $out)) {
                    $unmade[$key] = true;
                    $again = true;

                    break;
                }
            }
        } while ($again);
    }

    /**
     * One part's WO: created with reason SPK and released by the generating planner; false when it could not be made.
     *
     * @param  array<string, mixed>  $row
     * @param  array<string, list<array<string, mixed>>>  $out
     */
    private function makeWorkOrder(EoraPostgresConnection $db, string $plant, string $date, array $row, array &$out): bool
    {
        // a part whose plan already has a WO counts that WO as supply; what is still short is made like any other need
        $plan = $row['workOrderId'] === null ? SpkHours::num($row['planQty']) : '0';
        $qty = bccomp($plan, '0', 6) > 0 ? $plan : SpkHours::num($row['proposedQty'] ?? RecoveryCalculation::ceil((string) $row['netQty']));
        if (bccomp($qty, '0', 6) <= 0) {
            return true;
        }
        $who = ['itemCode' => $row['itemCode'], 'itemName' => $row['itemName'], 'customerCode' => $row['customerCode']];
        if ($row['materialCapacityQty'] === null) {
            $out['skipped'][] = $who + ['qty' => SpkHours::plain($qty), 'reason' => 'No approved BOM in force: material cannot be checked.'];

            return true;
        }
        // paired blanking (D-132) releases whole sheets only: the quantity goes up to whole sheets, a material cap down to them
        $pairs = $this->pairsPerSheet($db, $plant, (string) $row['itemId']);
        if ($pairs !== null) {
            $qty = bcmul(RecoveryCalculation::ceil(bcdiv($qty, $pairs, 6)), $pairs, 0);
        }
        $capacity = self::floor(SpkHours::num($row['materialCapacityQty']));
        if ($pairs !== null) {
            $capacity = bcmul(self::floor(bcdiv($capacity, $pairs, 6)), $pairs, 0);
        }
        if (bccomp($capacity, $qty, 6) < 0) {
            $out['materialShort'][] = $who + ['needQty' => SpkHours::plain($qty), 'plannedQty' => $capacity, 'shortQty' => SpkHours::plain(bcsub($qty, $capacity, 6))];
            $qty = $capacity;
        }
        if (bccomp($qty, '0', 6) <= 0) {
            return true;
        }
        // a WO pegs only to a delivery still open (a fulfilled one is history); replenishment for stock pegs to none
        $demands = array_values(array_filter($row['demands'], static fn (stdClass $d): bool => bccomp(SpkHours::num($d->outstanding), '0', 6) > 0));
        try {
            $wo = $db->transaction(function () use ($db, $plant, $date, $row, $qty, $demands): array {
                $wo = $this->workOrders->createDraft(['plantId' => $plant, 'itemId' => $row['itemId'], 'qty' => SpkHours::plain($qty), 'dueDate' => $date,
                    'reasonCode' => 'SPK', 'note' => "Generate SPK {$date} / {$row['customerCode']}",
                    'scheduleLineId' => count($demands) === 1 ? $demands[0]->id : null, 'productionCustomerId' => $row['customerId']])->getData(true)['data'];
                $db->insert(
                    'insert into spk_generated_orders (tenant_id, plant_id, work_order_id, production_date, generated_by)
                         values ((select c.tenant from ctx_api.context() c), ?, ?, ?, (select c.actor from ctx_api.context() c))',
                    [$plant, $wo['id'], $date],
                );
                $this->workOrders->releaseGenerated($db, (string) $wo['id']);
                // the release checked every deferred rule now; the next generated WO needs its record checked at commit again
                $db->statement('set constraints all deferred');
                if ($row['planId'] !== null) {
                    // the saved Daily Recovery plan is now covered by this WO, as with Create work order
                    $db->update('update daily_recovery_plans set work_order_id = ? where id = ? and work_order_id is null', [$wo['id'], $row['planId']]);
                }

                return $wo;
            });
        } catch (DomainProblem|ValidationException $e) {
            $out['skipped'][] = $who + ['qty' => SpkHours::plain($qty), 'reason' => $e instanceof DomainProblem ? $e->getMessage() : implode(' ', array_merge(...array_values($e->errors())))];

            return false;
        }
        $out['workOrders'][] = ['id' => (string) $wo['id'], 'code' => (string) $wo['code']] + $who + ['qty' => SpkHours::plain($qty)];

        return true;
    }

    /**
     * Pairs per sheet of the item's paired primary routing in force today at the plant (the one its WO release snapshots), or null.
     *
     * @return numeric-string|null
     */
    private function pairsPerSheet(EoraPostgresConnection $db, string $plant, string $item): ?string
    {
        $today = $this->calendar->resolve($db, $plant, new DateTimeImmutable)->calendarDate;
        $pairs = $db->selectOne(
            "select v.pairs_per_sheet from routings r join routing_versions v on v.routing_id = r.id
              where r.plant_id = ? and r.item_id = ? and r.routing_kind = 'PRIMARY' and r.status = 'ACTIVE' and v.status in ('APPROVED', 'SUPERSEDED')
                and v.effective_from <= ?::date and (v.effective_to is null or v.effective_to >= ?::date) order by v.effective_from desc limit 1",
            [$plant, $item, $today, $today],
        )?->pairs_per_sheet;

        return $pairs === null ? null : SpkHours::num($pairs);
    }

    /**
     * The draft SPK of the operation's line for the date, with its shifts, hours per shift and current load.
     *
     * @param  list<array{id: ?string, code: ?string}>  $shifts
     * @param  array<string, array<string, mixed>>  $sheets
     * @return array<string, mixed>
     */
    private function sheet(EoraPostgresConnection $db, string $plant, string $date, stdClass $op, array $shifts, array &$sheets): array
    {
        $wc = (string) $op->work_center_id;
        if (isset($sheets[$wc])) {
            return $sheets[$wc];
        }
        $draft = $db->selectOne("select id from spk_headers where plant_id = ? and work_center_id = ? and production_date = ? and status = 'DRAFT' for update", [$plant, $wc, $date]);
        $released = $draft === null ? $db->selectOne("select spk_no from spk_headers where plant_id = ? and work_center_id = ? and production_date = ? and status = 'RELEASED'", [$plant, $wc, $date]) : null;
        $sheets[$wc] = [
            'id' => $draft?->id, 'created' => false, 'code' => (string) $op->work_center_code, 'workCenterId' => $wc,
            'blocked' => $released === null ? null : "SPK {$released->spk_no} is released for {$op->work_center_code} on {$date}: revise it to add lines.",
            'capacity' => $op->hours_per_shift === null ? null : SpkHours::num($op->hours_per_shift),
            'shifts' => $shifts,
            'load' => [], 'setup' => [], 'existing' => [], 'new' => [],
        ];
        if ($draft !== null) {
            foreach ($db->select(
                'select l.id, l.work_order_id, l.routing_operation_id, l.machine_id, l.shift_id, trim_scale(l.planned_qty)::text as planned_qty, l.priority,
                        trim_scale(l.setup_minutes)::text as setup_minutes, trim_scale(l.qc_sample_minutes)::text as qc_sample_minutes, l.note, w.item_id,
                        trim_scale(o.std_good_output_per_hour)::text as sph, trim_scale(o.std_cycle_seconds)::text as cycle_seconds, o.cycle_basis, o.parts_per_cycle, v.paired_item_id
                   from spk_lines l join work_orders w on w.id = l.work_order_id join routing_operations o on o.id = l.routing_operation_id
                   left join routing_versions v on v.id = w.routing_version_id where l.spk_id = ? order by l.line_no',
                [$draft->id],
            ) as $l) {
                $shift = $this->shiftIndex($sheets[$wc]['shifts'], $l->shift_id);
                $hours = SpkHours::hours(SpkHours::sph($l), self::runQty($l->planned_qty, $l->paired_item_id), (string) $l->setup_minutes, (string) $l->qc_sample_minutes);
                $key = $l->machine_id.'|'.$shift;
                $sheets[$wc]['load'][$key] = bcadd(SpkHours::num($sheets[$wc]['load'][$key] ?? '0'), $hours ?? '0', 6);
                $sheets[$wc]['setup'][$l->machine_id.'|'.$l->item_id.'|'.$l->routing_operation_id] = true;
                $sheets[$wc]['existing'][] = $l;
            }
        }

        return $sheets[$wc];
    }

    /**
     * Machines for an operation: the routing's active machines on the line (preferred first); every active machine of the line
     * only when the routing names none.
     *
     * @return list<stdClass>
     */
    private function machines(EoraPostgresConnection $db, stdClass $op): array
    {
        $listed = $db->select(
            "select m.id, m.code from routing_operation_machines rm join machines m on m.id = rm.machine_id
              where rm.operation_id = ? and m.status = 'ACTIVE' and m.work_center_id = ? order by rm.is_preferred desc, m.code",
            [$op->id, $op->work_center_id],
        );

        // machines named on the routing are the only choice, even when none of them is active now; only an operation that
        // names none may use any active machine of its line
        $named = $db->selectOne('select 1 as named from routing_operation_machines where operation_id = ? limit 1', [$op->id]) !== null;

        return array_values($named ? $listed : $db->select("select id, code from machines where work_center_id = ? and status = 'ACTIVE' order by code", [$op->work_center_id]));
    }

    /**
     * Spreads one operation over machines and shifts; returns the first shift it uses (the next operation starts there).
     *
     * @param  array<string, mixed>  $sheet
     * @param  list<stdClass>  $machines
     * @param  array<int, numeric-string>|null  $supply  the operation before it, per shift (null for the first operation)
     * @param  array<int, numeric-string>  $mine  this operation's lines already on SPKs, per shift (other days in the first)
     * @param  array<int, numeric-string>  $placed  filled with what is placed now, per shift
     * @param  array<string, list<array<string, mixed>>>  $out
     */
    private function place(array &$sheet, stdClass $wo, stdClass $op, array $machines, string $qty, int $priority, int $after, ?array $supply, array $mine, array &$placed, array &$out): int
    {
        $count = count($sheet['shifts']);
        // G-3: what may still go into shift $k without running ahead of the operation before it at the end of that or any later shift
        $room = function (int $k) use (&$placed, $supply, $mine, $count): ?string {
            if ($supply === null) {
                return null;
            }
            $made = '0';
            $done = '0';
            $least = null;
            for ($j = 0; $j < $count; $j++) {
                $made = bcadd($made, SpkHours::num($supply[$j] ?? '0'), 6);
                $done = bcadd(bcadd($done, SpkHours::num($mine[$j] ?? '0'), 6), SpkHours::num($placed[$j] ?? '0'), 6);
                if ($j >= $k) {
                    $free = bcsub($made, $done, 6);
                    $least = $least === null || bccomp($free, $least, 6) < 0 ? $free : $least;
                }
            }

            return $least === null || bccomp($least, '0', 6) < 0 ? '0' : self::floor($least);
        };
        /** @return numeric-string */
        $fit = static fn (string $take, ?string $limit): string => SpkHours::num($limit !== null && bccomp(SpkHours::num($take), SpkHours::num($limit), 6) > 0 ? $limit : $take);
        $sph = SpkHours::sph($op);
        $factor = $wo->paired_item_id === null ? '1' : '2';
        $setup = SpkHours::num($op->setup_minutes);
        $after = min($after, $count - 1);
        $first = null;
        $left = SpkHours::num($qty);
        // a chunk that merges into the operation's line already on this machine and shift adds no setup or QC sample (G-4)
        $overhead = function (string $machine, int $k) use (&$sheet, $wo, $op, $setup): array {
            if ($this->hasLine($sheet, $wo, $op, $machine, $k)) {
                return ['0', '0'];
            }
            $minutes = isset($sheet['setup'][$machine.'|'.$wo->item_id.'|'.$op->id]) ? '0' : $setup;

            return [$minutes, bcdiv(bcadd($minutes, self::QC_MINUTES, 6), '60', 6)];
        };
        if ($sph === null) {
            // no SPH: the hours are not calculable, so the quantity goes to the preferred machine, in the first shifts the input allows
            $first = null;
            for ($k = $after; $k < $count && bccomp($left, '0', 6) > 0; $k++) {
                $take = $fit($left, $room($k));
                if (bccomp($take, '0', 6) <= 0) {
                    continue;
                }
                [$minutes] = $overhead((string) $machines[0]->id, $k);
                $this->addLine($sheet, $wo, $op, (string) $machines[0]->id, $k, $take, $priority, $minutes, null, '0');
                $placed[$k] = bcadd(SpkHours::num($placed[$k] ?? '0'), $take, 6);
                $first = $first === null ? $k : min($first, $k); // the earliest shift used on any machine
                $left = bcsub($left, $take, 6);
            }

            return $first ?? $after;
        }
        foreach ($machines as $machine) {
            for ($k = $after; $k < $count && bccomp($left, '0', 6) > 0; $k++) {
                [$minutes, $hours] = $overhead((string) $machine->id, $k);
                $key = $machine->id.'|'.$k;
                $cap = self::cap($sheet, $k);
                if ($cap === null) {
                    $take = $left;
                } else {
                    $free = bcsub(bcsub($cap, SpkHours::num($sheet['load'][$key] ?? '0'), 6), $hours, 6);
                    $take = bccomp($free, '0', 6) > 0 ? self::floor(bcdiv(bcmul($free, SpkHours::num($sph), 6), $factor, 6)) : '0';
                    $take = bccomp($take, $left, 6) > 0 ? $left : $take;
                }
                $take = $fit($take, $room($k));
                if (bccomp($take, '0', 6) <= 0) {
                    continue;
                }
                $this->addLine($sheet, $wo, $op, (string) $machine->id, $k, $take, $priority, $minutes, $sph, $hours);
                $placed[$k] = bcadd(SpkHours::num($placed[$k] ?? '0'), $take, 6);
                $first = $first === null ? $k : min($first, $k); // the earliest shift used on any machine
                $left = bcsub($left, $take, 6);
            }
        }
        $k = $count - 1;
        $limit = $room($k);
        if ($limit !== null && bccomp($left, $limit, 6) > 0) {
            // the operation before it does not make enough by the last shift: the rest waits for it
            $out['skipped'][] = ['workOrderCode' => (string) $wo->wo_no, 'itemCode' => (string) $wo->item_code, 'opSeq' => (int) $op->op_seq,
                'reason' => SpkHours::plain(bcsub($left, $limit, 6)).' not planned: the operation before it does not make them in time.'];
            $left = $limit;
        }
        if (bccomp($left, '0', 6) > 0) {
            // G-5: what fits nowhere stays on the preferred machine's last shift; the overtime is what that shift then holds
            // beyond its hours, with each line's setup and QC sample counted once
            $machine = $machines[0];
            $key = $machine->id.'|'.$k;
            [$minutes, $hours] = $overhead((string) $machine->id, $k);
            $before = SpkHours::num($sheet['load'][$key] ?? '0');
            $this->addLine($sheet, $wo, $op, (string) $machine->id, $k, SpkHours::plain($left), $priority, $minutes, $sph, $hours);
            $placed[$k] = bcadd(SpkHours::num($placed[$k] ?? '0'), $left, 6);
            $first = $first === null ? $k : min($first, $k); // the earliest shift used on any machine
            $capacity = SpkHours::num(self::cap($sheet, $k) ?? '0');
            $over = static fn (string $load): string => bccomp(SpkHours::num($load), $capacity, 6) > 0 ? bcsub(SpkHours::num($load), $capacity, 6) : '0';
            $out['overtime'][] = ['machineId' => (string) $machine->id, 'machineCode' => (string) $machine->code, 'workCenterCode' => $sheet['code'],
                'shiftCode' => $sheet['shifts'][$k]['code'], 'hours' => SpkHours::round3(bcsub($over(SpkHours::num($sheet['load'][$key])), $over($before), 6)),
                'qty' => SpkHours::plain($left), 'workOrderCode' => (string) $wo->wo_no, 'itemCode' => (string) $wo->item_code,
                'customerCode' => $wo->customer_code, 'opSeq' => (int) $op->op_seq];
        }

        return $first ?? $after;
    }

    /** @param array<string, mixed> $sheet whether the operation of this WO already has a line on the machine and shift (new or saved) */
    private function hasLine(array $sheet, stdClass $wo, stdClass $op, string $machine, int $shift): bool
    {
        if (isset($sheet['new'][$wo->id.'|'.$op->id.'|'.$machine.'|'.$shift])) {
            return true;
        }
        foreach ($sheet['existing'] as $l) {
            if ((string) $l->work_order_id === (string) $wo->id && (string) $l->routing_operation_id === (string) $op->id
                && (string) $l->machine_id === $machine && ($l->shift_id === null ? null : (string) $l->shift_id) === $sheet['shifts'][$shift]['id']) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  array<string, mixed>  $sheet
     * @param  numeric-string  $overhead  hours of setup and QC sample this chunk adds (none when it merges into a line)
     */
    private function addLine(array &$sheet, stdClass $wo, stdClass $op, string $machine, int $shift, string $qty, int $priority, string $setup, ?string $sph, string $overhead): void
    {
        $run = $sph === null ? '0' : bcdiv(self::runQty($qty, $wo->paired_item_id), SpkHours::num($sph), 6);
        $key = $machine.'|'.$shift;
        $sheet['load'][$key] = bcadd(bcadd(SpkHours::num($sheet['load'][$key] ?? '0'), $run, 6), $overhead, 6);
        $sheet['setup'][$machine.'|'.$wo->item_id.'|'.$op->id] = true;
        // G-4: the same work order and operation on the same machine and shift is one line
        $line = $wo->id.'|'.$op->id.'|'.$key;
        if (isset($sheet['new'][$line])) {
            $sheet['new'][$line]['qty'] = SpkHours::plain(bcadd(SpkHours::num($sheet['new'][$line]['qty']), SpkHours::num($qty), 6));

            return;
        }
        $sheet['new'][$line] = ['workOrderId' => (string) $wo->id, 'operationId' => (string) $op->id, 'machineId' => $machine,
            'shiftId' => $sheet['shifts'][$shift]['id'], 'qty' => $qty, 'priority' => $priority, 'setup' => $setup];
    }

    /**
     * Writes the new lines to each line's draft SPK (created when missing); a new line merges into an existing line of the
     * same work order, operation, machine and shift.
     *
     * @param  array<string, array<string, mixed>>  $sheets
     * @param  array<string, list<array<string, mixed>>>  $out
     */
    private function write(EoraPostgresConnection $db, string $plant, string $date, array $sheets, array &$out): void
    {
        foreach ($sheets as $sheet) {
            if ($sheet['new'] === []) {
                continue;
            }
            $id = $sheet['id'];
            $before = null;
            if ($id === null) {
                $id = (string) $db->selectOne(
                    'insert into spk_headers (tenant_id, plant_id, work_center_id, production_date) values ((select c.tenant from ctx_api.context() c), ?, ?, ?) returning id',
                    [$plant, $sheet['workCenterId'], $date],
                )->id;
            } else {
                $before = $this->state($db, (string) $id);
            }
            $lineNo = (int) ($db->selectOne('select coalesce(max(line_no), 0) as n from spk_lines where spk_id = ?', [$id])->n ?? 0);
            foreach ($sheet['new'] as $new) {
                $existing = null;
                foreach ($sheet['existing'] as $l) {
                    if ((string) $l->work_order_id === $new['workOrderId'] && (string) $l->routing_operation_id === $new['operationId']
                        && (string) $l->machine_id === $new['machineId'] && $l->shift_id === $new['shiftId']) {
                        $existing = $l;
                    }
                }
                $qty = $new['qty'];
                $no = ++$lineNo;
                if ($existing !== null) {
                    $db->delete('delete from spk_lines where id = ?', [$existing->id]);
                    $qty = SpkHours::plain(bcadd(SpkHours::num($existing->planned_qty), SpkHours::num($qty), 6));
                    $new['priority'] = (int) $existing->priority;
                    $new['setup'] = (string) $existing->setup_minutes;
                }
                $db->insert(
                    'insert into spk_lines (tenant_id, spk_id, line_no, work_order_id, routing_operation_id, machine_id, shift_id, planned_qty, priority, setup_minutes, qc_sample_minutes, note)
                     values ((select c.tenant from ctx_api.context() c), ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
                    [$id, $no, $new['workOrderId'], $new['operationId'], $new['machineId'], $new['shiftId'], $qty, $new['priority'], $new['setup'],
                        $existing !== null ? (string) $existing->qc_sample_minutes : self::QC_MINUTES, $existing?->note],
                );
            }
            // a planner holding the draft open sees it changed (If-Match)
            $db->update('update spk_headers set note = note where id = ?', [$id]);
            $this->audit->record($db, 'spk.generated', 'spk', (string) $id, $before, $this->state($db, (string) $id), ['lines' => count($sheet['new'])], $plant);
            $out['spks'][] = ['id' => (string) $id, 'workCenterCode' => $sheet['code'], 'created' => $sheet['id'] === null, 'linesAdded' => count($sheet['new'])];
        }
    }

    /**
     * @param  array<string, array<string, mixed>>  $sheets
     * @param  array<string, list<array<string, mixed>>>  $out
     */
    private function flagOvertime(EoraPostgresConnection $db, string $plant, array $sheets, array &$out): void
    {
        $limit = $db->selectOne('select trim_scale(spk_ot_limit_hours)::text as h from plants where id = ?', [$plant])?->h;
        // the limit holds for the machine's whole day as its draft now stands (lines already there included), not only what was added now
        $perMachine = [];
        foreach ($sheets as $sheet) {
            foreach ($sheet['load'] as $key => $load) {
                [$machine, $shift] = explode('|', (string) $key);
                $cap = self::cap($sheet, (int) $shift);
                if ($cap === null) {
                    continue;
                }
                $over = bcsub(SpkHours::num($load), $cap, 6);
                if (bccomp($over, '0', 6) > 0) {
                    $perMachine[$machine] = bcadd(SpkHours::num($perMachine[$machine] ?? '0'), $over, 6);
                }
            }
        }
        foreach ($out['overtime'] as $i => $ot) {
            $day = SpkHours::num($perMachine[$ot['machineId']] ?? $ot['hours']);
            $out['overtime'][$i]['machineDayHours'] = SpkHours::round3($day);
            $out['overtime'][$i]['overLimit'] = $limit !== null && bccomp($day, SpkHours::num($limit), 6) > 0;
            $out['overtime'][$i]['limitHours'] = $limit;
        }
    }

    /**
     * Hours a machine of the sheet's line may run in shift $k (D-142 C-3): the shift's work hours that day capped by the
     * line's hours per shift; null when neither is known.
     *
     * @param  array<string, mixed>  $sheet
     * @return numeric-string|null
     */
    private static function cap(array $sheet, int $k): ?string
    {
        $shift = $sheet['shifts'][$k]['hours'] ?? null;

        return ShiftCalendar::capacity($shift === null ? null : SpkHours::num((string) $shift), $sheet['capacity'] === null ? null : SpkHours::num((string) $sheet['capacity']));
    }

    /**
     * What an operation of a WO already has on the SPKs of the day, per shift (a draft revision in place of its released SPK).
     *
     * @param  list<array{id: ?string, code: ?string}>  $shifts
     * @return array<int, numeric-string>
     */
    private function plannedByShift(EoraPostgresConnection $db, string $wo, string $op, string $date, array $shifts): array
    {
        $by = [];
        foreach ($db->select(
            "select l.shift_id, trim_scale(l.planned_qty)::text as qty from spk_lines l join spk_headers h on h.id = l.spk_id
              where l.work_order_id = ? and l.routing_operation_id = ? and h.production_date = ?
                and (h.status = 'DRAFT' or (h.status in ('RELEASED', 'CLOSED')
                     and not exists (select 1 from spk_headers r where r.previous_id = h.id and r.status = 'DRAFT')))",
            [$wo, $op, $date],
        ) as $l) {
            $k = $this->shiftIndex($shifts, $l->shift_id === null ? null : (string) $l->shift_id);
            $by[$k] = bcadd(SpkHours::num($by[$k] ?? '0'), SpkHours::num($l->qty), 6);
        }
        ksort($by);

        return $by;
    }

    /** @param list<array{id: ?string, code: ?string}> $shifts */
    private function shiftIndex(array $shifts, mixed $shift): int
    {
        foreach ($shifts as $k => $s) {
            if ($s['id'] === $shift) {
                return $k;
            }
        }

        return 0;
    }

    /** @return array<string, mixed> */
    private function state(EoraPostgresConnection $db, string $id): array
    {
        return (array) ($db->selectOne('select * from spk_headers where id = ?', [$id]) ?? throw new LogicException('SPK not visible.'));
    }

    /** @return numeric-string a paired blanking WO plans pieces per side while SPH counts both sides (D-130) */
    private static function runQty(mixed $qty, mixed $paired): string
    {
        return $paired === null ? SpkHours::num($qty) : bcmul(SpkHours::num($qty), '2', 6);
    }

    /** @return numeric-string */
    private static function floor(string $value): string
    {
        return bcadd(SpkHours::num($value), '0', 0);
    }
}
