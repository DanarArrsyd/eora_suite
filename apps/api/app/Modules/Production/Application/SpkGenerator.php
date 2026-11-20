<?php

declare(strict_types=1);

namespace App\Modules\Production\Application;

use App\Modules\Planning\Http\WorkOrderController;
use App\Modules\Planning\Recovery\DailyRecoveryPlanner;
use App\Modules\Planning\Recovery\RecoveryCalculation;
use App\Platform\Audit\AuditRecorder;
use App\Platform\Database\EoraPostgresConnection;
use App\Platform\Exceptions\DomainProblem;
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
    ) {}

    /** @return array<string, list<array<string, mixed>>> spks, workOrders, overtime, materialShort, skipped */
    public function generate(EoraPostgresConnection $db, string $plant, string $date): array
    {
        $out = ['spks' => [], 'workOrders' => [], 'overtime' => [], 'materialShort' => [], 'skipped' => []];
        $this->createWorkOrders($db, $plant, $date, $out);

        $sheets = [];
        $orders = $db->select(
            "select w.id, w.wo_no, w.item_id, i.code as item_code, trim_scale(w.qty)::text as qty, w.routing_version_id, v.paired_item_id, c.code as customer_code
               from work_orders w join items i on i.id = w.item_id and i.item_type = 'FINISHED'
               left join routing_versions v on v.id = w.routing_version_id left join customers c on c.id = w.production_customer_id
              where w.plant_id = ? and w.status in ('RELEASED', 'IN_PROGRESS') and w.due_date <= ?::date
              order by w.due_date, w.wo_no",
            [$plant, $date],
        );
        foreach ($orders as $rank => $wo) {
            $ops = $db->select(
                "select o.id, o.op_seq, o.work_center_id, wc.code as work_center_code, trim_scale(wc.available_hours_per_shift)::text as hours_per_shift,
                        trim_scale(o.std_setup_minutes)::text as setup_minutes, trim_scale(o.std_good_output_per_hour)::text as sph,
                        trim_scale(o.std_cycle_seconds)::text as cycle_seconds, o.cycle_basis, o.parts_per_cycle,
                        coalesce((select sum(l.planned_qty) from spk_lines l join spk_headers h on h.id = l.spk_id
                                   where l.work_order_id = ? and l.routing_operation_id = o.id
                                     and (h.status in ('RELEASED', 'CLOSED') or (h.status = 'DRAFT' and h.previous_id is null))), 0)::text as planned
                   from routing_operations o join work_centers wc on wc.id = o.work_center_id
                  where o.routing_version_id = ? order by o.op_seq",
                [$wo->id, $wo->routing_version_id],
            );
            $after = 0; // G-3: a later operation never starts in an earlier shift than the one before it
            foreach ($ops as $op) {
                $qty = bcsub(SpkHours::num($wo->qty), SpkHours::num($op->planned), 6);
                if (bccomp($qty, '0', 6) <= 0) {
                    continue;
                }
                $sheet = $this->sheet($db, $plant, $date, $op, $sheets);
                if ($sheet['blocked'] !== null) {
                    $out['skipped'][] = ['workOrderCode' => $wo->wo_no, 'itemCode' => $wo->item_code, 'opSeq' => (int) $op->op_seq, 'reason' => $sheet['blocked']];

                    continue;
                }
                $machines = $this->machines($db, $op);
                if ($machines === []) {
                    $out['skipped'][] = ['workOrderCode' => $wo->wo_no, 'itemCode' => $wo->item_code, 'opSeq' => (int) $op->op_seq,
                        'reason' => "No active machine on {$op->work_center_code}."];

                    continue;
                }
                $after = $this->place($sheets[$op->work_center_id], $wo, $op, $machines, SpkHours::plain($qty), min(99, $rank + 1), $after, $out);
            }
        }
        $this->write($db, $plant, $date, $sheets, $out);
        $this->flagOvertime($db, $plant, $out);

        return $out;
    }

    /** @param array<string, list<array<string, mixed>>> $out */
    private function createWorkOrders(EoraPostgresConnection $db, string $plant, string $date, array &$out): void
    {
        $recovery = $this->recovery->read($db, $plant, $date);
        $safety = [];
        foreach ($db->select('select item_id, trim_scale(safety_stock)::text as qty from item_plan_parameters where plant_id = ? and safety_stock is not null', [$plant]) as $p) {
            $safety[(string) $p->item_id] = (string) $p->qty;
        }
        foreach ($recovery['rows'] as $row) {
            if ($row['workOrderId'] !== null) {
                continue;
            }
            $plan = SpkHours::num($row['planQty']);
            if (bccomp($plan, '0', 6) > 0) {
                $qty = $plan;
            } else {
                $buffer = SpkHours::num($row['bufferQty']);
                $stock = SpkHours::num($safety[(string) $row['itemId']] ?? '0');
                $supply = array_reduce($row['allocated'], static fn (string $t, string $q): string => bcadd(SpkHours::num($t), SpkHours::num($q), 6), '0');
                $calc = RecoveryCalculation::calculate((string) $row['requiredQty'], $supply, bccomp($stock, $buffer, 6) > 0 ? $stock : $buffer,
                    (string) $row['minimumQty'], $row['batchQty'] === null ? null : (string) $row['batchQty'], '0');
                $qty = $calc['proposedQty'] ?? self::ceil($calc['netQty']);
            }
            if (bccomp($qty, '0', 6) <= 0) {
                continue;
            }
            $who = ['itemCode' => $row['itemCode'], 'itemName' => $row['itemName'], 'customerCode' => $row['customerCode']];
            if ($row['materialCapacityQty'] === null) {
                $out['skipped'][] = $who + ['qty' => SpkHours::plain($qty), 'reason' => 'No approved BOM in force: material cannot be checked.'];

                continue;
            }
            $capacity = self::floor(SpkHours::num($row['materialCapacityQty']));
            if (bccomp($capacity, $qty, 6) < 0) {
                $out['materialShort'][] = $who + ['needQty' => SpkHours::plain($qty), 'plannedQty' => $capacity, 'shortQty' => SpkHours::plain(bcsub($qty, $capacity, 6))];
                $qty = $capacity;
            }
            if (bccomp($qty, '0', 6) <= 0) {
                continue;
            }
            $demands = $row['demands'];
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
                    if ($row['planId'] !== null) {
                        // the saved Daily Recovery plan is now covered by this WO, as with Create work order
                        $db->update('update daily_recovery_plans set work_order_id = ? where id = ? and work_order_id is null', [$wo['id'], $row['planId']]);
                    }

                    return $wo;
                });
            } catch (DomainProblem|ValidationException $e) {
                $out['skipped'][] = $who + ['qty' => SpkHours::plain($qty), 'reason' => $e instanceof DomainProblem ? $e->getMessage() : implode(' ', array_merge(...array_values($e->errors())))];

                continue;
            }
            $out['workOrders'][] = ['id' => (string) $wo['id'], 'code' => (string) $wo['code']] + $who + ['qty' => SpkHours::plain($qty)];
        }
    }

    /**
     * The draft SPK of the operation's line for the date, with its shifts, hours per shift and current load.
     *
     * @param  array<string, array<string, mixed>>  $sheets
     * @return array<string, mixed>
     */
    private function sheet(EoraPostgresConnection $db, string $plant, string $date, stdClass $op, array &$sheets): array
    {
        $wc = (string) $op->work_center_id;
        if (isset($sheets[$wc])) {
            return $sheets[$wc];
        }
        $draft = $db->selectOne("select id from spk_headers where plant_id = ? and work_center_id = ? and production_date = ? and status = 'DRAFT' for update", [$plant, $wc, $date]);
        $released = $draft === null ? $db->selectOne("select spk_no from spk_headers where plant_id = ? and work_center_id = ? and production_date = ? and status = 'RELEASED'", [$plant, $wc, $date]) : null;
        $calendar = $db->selectOne('select id from shift_calendars where (plant_id = ? or plant_id is null) and effective_from <= ?::date order by (plant_id is null), effective_from desc limit 1', [$plant, $date]);
        $shifts = $calendar === null ? [] : $db->select('select id, code from shifts where shift_calendar_id = ? order by starts_at, code', [$calendar->id]);
        $sheets[$wc] = [
            'id' => $draft?->id, 'created' => false, 'code' => (string) $op->work_center_code, 'workCenterId' => $wc,
            'blocked' => $released === null ? null : "SPK {$released->spk_no} is released for {$op->work_center_code} on {$date}: revise it to add lines.",
            'capacity' => $op->hours_per_shift === null ? null : SpkHours::num($op->hours_per_shift),
            'shifts' => $shifts === [] ? [['id' => null, 'code' => null]] : array_map(static fn (stdClass $s) => ['id' => (string) $s->id, 'code' => (string) $s->code], $shifts),
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
     * Machines for an operation: the routing's machines on the line (preferred first), else every active machine of the line.
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

        return array_values($listed !== [] ? $listed : $db->select("select id, code from machines where work_center_id = ? and status = 'ACTIVE' order by code", [$op->work_center_id]));
    }

    /**
     * Spreads one operation over machines and shifts; returns the first shift it uses (the next operation starts there).
     *
     * @param  array<string, mixed>  $sheet
     * @param  list<stdClass>  $machines
     * @param  array<string, list<array<string, mixed>>>  $out
     */
    private function place(array &$sheet, stdClass $wo, stdClass $op, array $machines, string $qty, int $priority, int $after, array &$out): int
    {
        $sph = SpkHours::sph($op);
        $factor = $wo->paired_item_id === null ? '1' : '2';
        $setup = SpkHours::num($op->setup_minutes);
        $count = count($sheet['shifts']);
        $after = min($after, $count - 1);
        $first = null;
        $left = SpkHours::num($qty);
        $overhead = function (string $machine) use (&$sheet, $wo, $op, $setup): array {
            $minutes = isset($sheet['setup'][$machine.'|'.$wo->item_id.'|'.$op->id]) ? '0' : $setup;

            return [$minutes, bcdiv(bcadd($minutes, self::QC_MINUTES, 6), '60', 6)];
        };
        if ($sph === null) {
            // no SPH: the hours are not calculable, so the whole quantity goes to the preferred machine's first allowed shift
            [$minutes] = $overhead((string) $machines[0]->id);
            $this->addLine($sheet, $wo, $op, (string) $machines[0]->id, $after, $left, $priority, $minutes, null);

            return $after;
        }
        foreach ($machines as $machine) {
            for ($k = $after; $k < $count && bccomp($left, '0', 6) > 0; $k++) {
                [$minutes, $hours] = $overhead((string) $machine->id);
                $key = $machine->id.'|'.$k;
                if ($sheet['capacity'] === null) {
                    $take = $left;
                } else {
                    $free = bcsub(bcsub($sheet['capacity'], SpkHours::num($sheet['load'][$key] ?? '0'), 6), $hours, 6);
                    $take = bccomp($free, '0', 6) > 0 ? self::floor(bcdiv(bcmul($free, SpkHours::num($sph), 6), $factor, 6)) : '0';
                    $take = bccomp($take, $left, 6) > 0 ? $left : $take;
                }
                if (bccomp($take, '0', 6) <= 0) {
                    continue;
                }
                $this->addLine($sheet, $wo, $op, (string) $machine->id, $k, $take, $priority, $minutes, $sph);
                $first ??= $k;
                $left = bcsub($left, $take, 6);
            }
        }
        if (bccomp($left, '0', 6) > 0) {
            // G-5: what fits nowhere stays on the preferred machine's last shift and is proposed as overtime
            $k = $count - 1;
            $machine = $machines[0];
            [$minutes] = $overhead((string) $machine->id);
            $hours = SpkHours::hours($sph, bcmul($left, $factor, 6), $minutes, self::QC_MINUTES) ?? '0';
            $this->addLine($sheet, $wo, $op, (string) $machine->id, $k, SpkHours::plain($left), $priority, $minutes, $sph);
            $first ??= $k;
            $out['overtime'][] = ['machineId' => (string) $machine->id, 'machineCode' => (string) $machine->code, 'workCenterCode' => $sheet['code'],
                'shiftCode' => $sheet['shifts'][$k]['code'], 'hours' => SpkHours::round3($hours), 'qty' => SpkHours::plain($left),
                'workOrderCode' => (string) $wo->wo_no, 'itemCode' => (string) $wo->item_code, 'customerCode' => $wo->customer_code, 'opSeq' => (int) $op->op_seq];
        }

        return $first ?? $after;
    }

    /** @param array<string, mixed> $sheet */
    private function addLine(array &$sheet, stdClass $wo, stdClass $op, string $machine, int $shift, string $qty, int $priority, string $setup, ?string $sph): void
    {
        $hours = $sph === null ? '0' : (SpkHours::hours($sph, self::runQty($qty, $wo->paired_item_id), $setup, self::QC_MINUTES) ?? '0');
        $key = $machine.'|'.$shift;
        $sheet['load'][$key] = bcadd(SpkHours::num($sheet['load'][$key] ?? '0'), $hours, 6);
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

    /** @param array<string, list<array<string, mixed>>> $out */
    private function flagOvertime(EoraPostgresConnection $db, string $plant, array &$out): void
    {
        $limit = $db->selectOne('select trim_scale(spk_ot_limit_hours)::text as h from plants where id = ?', [$plant])?->h;
        $perMachine = [];
        foreach ($out['overtime'] as $ot) {
            $perMachine[$ot['machineId']] = bcadd($perMachine[$ot['machineId']] ?? '0', SpkHours::num($ot['hours']), 6);
        }
        foreach ($out['overtime'] as $i => $ot) {
            $out['overtime'][$i]['overLimit'] = $limit !== null && bccomp($perMachine[$ot['machineId']], SpkHours::num($limit), 6) > 0;
            $out['overtime'][$i]['limitHours'] = $limit;
        }
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

    /** @return numeric-string */
    private static function ceil(string $value): string
    {
        $value = SpkHours::num($value);
        $whole = bcadd($value, '0', 0);

        return bccomp($whole, $value, 6) < 0 ? bcadd($whole, '1', 0) : $whole;
    }
}
