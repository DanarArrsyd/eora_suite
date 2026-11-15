<?php

declare(strict_types=1);

namespace App\Modules\Planning\Mps;

use App\Platform\Database\EoraPostgresConnection;
use DateTimeImmutable;

/**
 * Capacity load of planned orders per work center and ISO week (G-MPS-7, BR-PLN-07, D-123 (4)); a warning, never a
 * reschedule. Load of a planned order = per operation of the item's primary routing version in force on its start date:
 * setup + standard cycle × cycles (PER_PIECE: quantity; PER_STROKE / PER_BATCH: quantity ÷ parts per cycle, rounded up),
 * in the week of its start. Available = hours per shift (work center) × shifts of the plant's shift calendar × working
 * days of the week from the plan date. No shift calendar, or no hours per shift: NOT_CONFIGURED, no percentage.
 * Warnings: NO_ACTIVE_MACHINE (work center without an active machine), DIE_NOT_AVAILABLE (an operation needing tooling
 * without an active die).
 */
final class MpsCapacity
{
    /**
     * @param  list<array{itemId: string, qty: numeric-string, startDate: string}>  $orders
     * @return array{rows: list<array{workCenterId: string, weekStart: string, load: numeric-string, available: ?numeric-string, status: string, warnings: list<string>}>, noRouting: list<int>}
     */
    public function compute(EoraPostgresConnection $db, string $plant, string $today, string $horizonStart, WorkingDays $days, array $orders): array
    {
        $calendar = $db->selectOne(
            'select id from shift_calendars where (plant_id = ? or plant_id is null) and effective_from <= ?::date order by (plant_id is null), effective_from desc limit 1',
            [$plant, $today],
        );
        $shifts = $calendar === null ? 0 : (int) $db->selectOne('select count(*) as n from shifts where shift_calendar_id = ?', [$calendar->id])->n;
        $load = [];
        $warnings = [];
        $noRouting = [];
        foreach ($orders as $n => $o) {
            $ops = $db->select(
                "select o.id, o.work_center_id, coalesce(o.std_setup_minutes, 0) as setup, coalesce(o.std_cycle_seconds, 0) as cycle, o.cycle_basis,
                        greatest(coalesce(o.parts_per_cycle, 1), 1) as per_cycle, o.requires_tooling,
                        exists (select 1 from routing_operation_tools t join dies d on d.tenant_id = t.tenant_id and d.id = t.die_id and d.status = 'ACTIVE'
                                 where t.tenant_id = o.tenant_id and t.operation_id = o.id) as has_die
                   from routing_operations o
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
                $cycles = $op->cycle_basis === 'PER_PIECE' ? $o['qty'] : $this->ceil(bcdiv($o['qty'], MpsItemInput::num($op->per_cycle), 6));
                $hours = bcadd(bcdiv(MpsItemInput::num($op->setup), '60', 6), bcdiv(bcmul(MpsItemInput::num($op->cycle), $cycles, 6), '3600', 6), 6);
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
            $available = null;
            if ($shifts > 0 && $centre->h !== null) {
                $sunday = (new DateTimeImmutable($week))->modify('+6 days')->format('Y-m-d');
                $available = bcmul(bcmul(MpsItemInput::num($centre->h), (string) $shifts, 6), (string) $days->count(max($week, $today), $sunday), 6);
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
