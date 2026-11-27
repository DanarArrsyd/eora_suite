<?php

declare(strict_types=1);

namespace App\Platform\Calendar;

use App\Platform\Database\EoraPostgresConnection;
use stdClass;

/**
 * The shifts a plant runs on a production date (doc 40, D-142): the shift calendar version in force on that date (plant
 * override before the tenant default, ADR-0012), each shift with the times of that date's ISO weekday (C-1). A shift with
 * weekday rows runs only on those weekdays; a shift without any runs every day with its own times. Work hours = length −
 * break (C-2). Shifts come in the order they run within the production day: from the business calendar's day start, a
 * shift starting before it runs last.
 */
final class ShiftCalendar
{
    /**
     * @return list<array{id: string, code: string, name: string, startsAt: string, endsAt: string, crossesMidnight: bool, breakMinutes: int, workHours: numeric-string}>
     */
    public function shiftsOn(EoraPostgresConnection $db, string $plantId, string $date): array
    {
        $version = $this->versionOn($db, $plantId, $date);
        if ($version === null) {
            return [];
        }
        $rows = $db->select(
            'select s.id, s.code, s.name,
                    coalesce(w.starts_at, s.starts_at)::text as starts_at, coalesce(w.ends_at, s.ends_at)::text as ends_at,
                    coalesce(w.break_minutes, 0) as break_minutes
               from shifts s
               left join shift_weekday_times w on w.tenant_id = s.tenant_id and w.shift_id = s.id and w.iso_weekday = extract(isodow from ?::date)
              where s.shift_calendar_id = ?
                and (w.id is not null or not exists (select 1 from shift_weekday_times x where x.tenant_id = s.tenant_id and x.shift_id = s.id))',
            [$date, $version],
        );
        $dayStart = self::normalize($this->dayStart($db, $plantId, $date));
        $shifts = array_map(static function (stdClass $s): array {
            $start = self::normalize((string) $s->starts_at);
            $end = self::normalize((string) $s->ends_at);
            $length = self::minutes($end) - self::minutes($start);
            $length = $length <= 0 ? $length + 1440 : $length;
            $work = max(0, $length - (int) $s->break_minutes);

            return ['id' => (string) $s->id, 'code' => (string) $s->code, 'name' => (string) $s->name, 'startsAt' => $start, 'endsAt' => $end,
                'crossesMidnight' => $end < $start, 'breakMinutes' => (int) $s->break_minutes, 'workHours' => self::hours($work)];
        }, $rows);
        usort($shifts, static fn (array $a, array $b): int => [$a['startsAt'] < $dayStart, $a['startsAt'], $a['code']] <=> [$b['startsAt'] < $dayStart, $b['startsAt'], $b['code']]);

        return $shifts;
    }

    /** The shift calendar version in force on the date at the plant (plant override before the tenant default), or null. */
    public function versionOn(EoraPostgresConnection $db, string $plantId, string $date): ?string
    {
        $row = $db->selectOne(
            'select id from shift_calendars where (plant_id = ? or plant_id is null) and effective_from <= ?::date
              order by (plant_id is null), effective_from desc limit 1',
            [$plantId, $date],
        );

        return $row instanceof stdClass ? (string) $row->id : null;
    }

    /**
     * Hours a work center can run in a shift (C-3): the shift's work hours, capped by the work center's hours per shift when
     * it has one; null when neither is known (no shift calendar and no hours per shift).
     *
     * @param  numeric-string|null  $shiftHours
     * @param  numeric-string|null  $centreHours
     * @return numeric-string|null
     */
    public static function capacity(?string $shiftHours, ?string $centreHours): ?string
    {
        if ($shiftHours === null) {
            return $centreHours;
        }
        if ($centreHours === null) {
            return $shiftHours;
        }

        return bccomp($centreHours, $shiftHours, 6) < 0 ? $centreHours : $shiftHours;
    }

    /** The production day's start at the plant on that date (business calendar: plant before tenant, latest version). */
    public function dayStart(EoraPostgresConnection $db, string $plantId, string $date): string
    {
        $row = $db->selectOne(
            'select production_day_starts_at::text as t from business_calendars where (plant_id = ? or plant_id is null) and effective_from <= ?::date
              order by (plant_id is null), effective_from desc limit 1',
            [$plantId, $date],
        );

        return $row instanceof stdClass ? (string) $row->t : '00:00:00';
    }

    /** @return numeric-string */
    private static function hours(int $minutes): string
    {
        return bcdiv((string) $minutes, '60', 6);
    }

    private static function minutes(string $time): int
    {
        [$h, $m] = array_map('intval', explode(':', $time));

        return $h * 60 + $m;
    }

    private static function normalize(string $time): string
    {
        return substr($time.':00', 0, 8);
    }
}
