<?php

declare(strict_types=1);

namespace App\Modules\Planning\Mps;

use App\Platform\Calendar\ShiftCalendar;
use App\Platform\Database\EoraPostgresConnection;
use DateTimeImmutable;

/**
 * Capacity load of planned orders per work center and ISO week (G-MPS-7, BR-PLN-07, D-123 (4)); a warning, never a
 * reschedule. Load of a planned order = per operation of the item's primary routing version in force on its start date:
 * setup + planned good quantity ÷ standard good output per running hour, or for a legacy operation,
 * setup + standard cycle × cycles (PER_PIECE: quantity; PER_STROKE / PER_BATCH: quantity ÷ parts per cycle, rounded up),
 * in the week of its start. Available = Σ over the working days of the week (from the plan date) of that day's shifts
 * (shift calendar version in force on it, weekday times), each shift's work hours capped by the work center's hours per
 * shift (D-142 C-3). A working day without a shift calendar: NOT_CONFIGURED, no percentage.
 * Warnings: NO_ACTIVE_MACHINE (work center without an active machine), DIE_NOT_AVAILABLE (an operation needing tooling
 * without an active die).
 */
final class MpsCapacity
{
    public function __construct(private readonly ShiftCalendar $shifts) {}

    /** @return array<string, array{itemCode: string, pairedItemCode: string, qtyPerSide: string, sheets: string, startDate: string, workCenterCode: string, runHours: string, setupMinutes: string}> */
    public static function pairedWorkloads(EoraPostgresConnection $db, string $run): array
    {
        $workloads = [];
        foreach ($db->select(
            'select o.id, i.code, p.code as paired_code, trim_scale(o.qty)::text as qty, o.start_date::text as start,
                    trim_scale(o.qty / v.pairs_per_sheet)::text as sheets, wc.code as center,
                    trim_scale(round(2 * o.qty / a.std_good_output_per_hour, 3))::text as hours, trim_scale(a.std_setup_minutes)::text as setup
               from mrp_orders o join routing_versions v on v.id = o.paired_routing_version_id
               join mrp_items m on m.id = o.mrp_item_id join items i on i.id = m.item_id join items p on p.id = v.paired_item_id
               join routing_operations a on a.routing_version_id = v.id join work_centers wc on wc.id = a.work_center_id
              where o.mrp_run_id = ? order by o.start_date, i.code, o.id',
            [$run],
        ) as $r) {
            $workloads[(string) $r->id] = ['itemCode' => (string) $r->code, 'pairedItemCode' => (string) $r->paired_code,
                'qtyPerSide' => (string) $r->qty, 'sheets' => (string) $r->sheets, 'startDate' => (string) $r->start,
                'workCenterCode' => (string) $r->center, 'runHours' => (string) $r->hours, 'setupMinutes' => (string) $r->setup];
        }

        return $workloads;
    }

    /**
     * @param  list<array{itemId: string, qty: numeric-string, startDate: string}>  $orders
     * @return array{rows: list<array{workCenterId: string, weekStart: string, load: numeric-string, available: ?numeric-string, status: string, warnings: list<string>}>, noRouting: list<int>}
     */
    public function compute(EoraPostgresConnection $db, string $plant, string $today, string $horizonStart, WorkingDays $days, array $orders): array
    {
        $load = [];
        $warnings = [];
        $noRouting = [];
        foreach ($orders as $n => $o) {
            $ops = $db->select(
                "select o.id, o.work_center_id, o.std_setup_minutes as setup, o.std_cycle_seconds as cycle, o.cycle_basis,
                        o.std_good_output_per_hour as good_output_per_hour, v.paired_item_id,
                        greatest(coalesce(o.parts_per_cycle, 1), 1) as per_cycle, o.requires_tooling,
                        exists (select 1 from routing_operation_tools t join dies d on d.tenant_id = t.tenant_id and d.id = t.die_id and d.status = 'ACTIVE'
                                 where t.tenant_id = o.tenant_id and t.operation_id = o.id) as has_die
                   from routing_operations o join routing_versions v on v.id = o.routing_version_id
                  where o.routing_version_id = (
                    -- the version in force on the start date: approved, or superseded by a later-dated approval (as WO release)
                    select v.id from routings r join routing_versions v on v.tenant_id = r.tenant_id and v.routing_id = r.id
                     where r.plant_id = ? and r.item_id = ? and r.routing_kind = 'PRIMARY' and r.status = 'ACTIVE' and v.status in ('APPROVED', 'SUPERSEDED')
                       and v.effective_from <= ?::date and (v.effective_to is null or v.effective_to >= ?::date)
                     order by v.effective_from desc limit 1)
                  order by o.op_seq",
                [$plant, $o['itemId'], $o['startDate'], $o['startDate']],
            );
            if ($ops === []) {
                $noRouting[] = $n;

                continue;
            }
            $week = (new DateTimeImmutable(max($o['startDate'], $horizonStart)))->modify('monday this week')->format('Y-m-d');
            foreach ($ops as $op) {
                if ($op->good_output_per_hour !== null) {
                    $outputQty = $op->paired_item_id === null ? $o['qty'] : bcmul($o['qty'], '2', 6);
                    $runningHours = bcdiv($outputQty, MpsItemInput::num($op->good_output_per_hour), 6);
                } else {
                    $cycles = $op->cycle_basis === 'PER_PIECE' ? $o['qty'] : $this->ceil(bcdiv($o['qty'], MpsItemInput::num($op->per_cycle), 6));
                    $runningHours = bcdiv(bcmul(MpsItemInput::num($op->cycle), $cycles, 6), '3600', 6);
                }
                $hours = bcadd(bcdiv(MpsItemInput::num($op->setup), '60', 6), $runningHours, 6);
                $key = "{$op->work_center_id}|{$week}";
                $load[$key] = bcadd($load[$key] ?? '0', $hours, 6);
                if ((bool) $op->requires_tooling && ! (bool) $op->has_die) {
                    $warnings[$key]['DIE_NOT_AVAILABLE'] = true;
                }
            }
        }
        ksort($load);
        $rows = [];
        foreach ($load as $key => $hours) {
            [$wc, $week] = explode('|', $key);
            $centre = $db->selectOne(
                "select available_hours_per_shift as h, exists (select 1 from machines m where m.tenant_id = w.tenant_id and m.work_center_id = w.id and m.status = 'ACTIVE') as machine
                   from work_centers w where w.id = ?",
                [$wc],
            );
            if (! (bool) $centre->machine) {
                $warnings[$key]['NO_ACTIVE_MACHINE'] = true;
            }
            // available = Σ over the week's working days (from the plan date) of that day's shifts, each shift its work hours
            // capped by the work center's hours per shift (D-142 C-3); a working day without a shift calendar in force (nor
            // hours to count) leaves the week not configured
            $available = '0';
            for ($d = new DateTimeImmutable(max($week, $today)), $end = (new DateTimeImmutable($week))->modify('+6 days'); $d <= $end; $d = $d->modify('+1 day')) {
                $day = $d->format('Y-m-d');
                if (! $days->isWorking($day)) {
                    continue;
                }
                $shifts = $this->shifts->shiftsOn($db, $plant, $day);
                if ($shifts === []) {
                    $available = null;
                    break;
                }
                foreach ($shifts as $shift) {
                    $available = bcadd($available, ShiftCalendar::capacity($shift['workHours'], $centre->h === null ? null : MpsItemInput::num($centre->h)) ?? '0', 6);
                }
            }
            $rows[] = [
                'workCenterId' => $wc, 'weekStart' => $week, 'load' => $hours, 'available' => $available,
                'status' => $available === null ? 'NOT_CONFIGURED' : (bccomp($hours, $available, 6) > 0 ? 'OVERLOAD' : 'OK'),
                'warnings' => $this->warnings($warnings[$key] ?? []),
            ];
        }

        return ['rows' => $rows, 'noRouting' => $noRouting];
    }

    /**
     * @param  array<string, true>  $set
     * @return list<string>
     */
    private function warnings(array $set): array
    {
        $out = [];
        foreach ($set as $code => $on) {
            $out[] = $code;
        }

        return $out;
    }

    /**
     * @param  numeric-string  $n
     * @return numeric-string
     */
    private function ceil(string $n): string
    {
        $whole = MpsItemInput::num(explode('.', $n)[0]);

        return bccomp($n, $whole, 6) > 0 ? bcadd($whole, '1', 0) : $whole;
    }
}
