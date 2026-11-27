<?php

declare(strict_types=1);

namespace App\Platform\Calendar;

use App\Platform\Database\EoraPostgresConnection;
use DateTimeImmutable;
use DateTimeInterface;
use DateTimeZone;
use RuntimeException;
use stdClass;

/**
 * Resolves calendar date, production date, shift and ISO week of an instant at a plant (ADR-0012, D-044, doc 04).
 * Hierarchy: plant override → tenant default, each versioned by effective_from (the version effective on the local
 * calendar date applies, so a later version never moves earlier dates). Rules:
 * - production date = local date, or the previous date when the local time is before the production-day cut-off;
 * - a shift belongs wholly to the production date of its start (a 23:00–07:00 shift started Monday is Monday's);
 * - weekly buckets use the ISO week of the production date.
 * Runs inside a TenantTransaction, so RLS limits every read to the signed tenant. No calendar → no guess: it fails.
 */
final class BusinessCalendar
{
    public function __construct(private readonly ShiftCalendar $shifts) {}

    public function resolve(EoraPostgresConnection $db, string $plantId, DateTimeInterface $instant): ResolvedTime
    {
        // Version selection uses the plant's local date (plant timezone inherits the tenant default, doc 04).
        $plantDate = DateTimeImmutable::createFromInterface($instant)->setTimezone(new DateTimeZone($this->plantZone($db, $plantId)))->format('Y-m-d');
        $calendar = $this->effectiveVersion($db, 'business_calendars', $plantId, $plantDate)
            ?? throw new RuntimeException('No business calendar is configured for this plant or tenant.');
        $zone = new DateTimeZone((string) $calendar->timezone);
        $local = DateTimeImmutable::createFromInterface($instant)->setTimezone($zone);
        $cutoff = (string) $calendar->production_day_starts_at;

        $productionDate = $this->productionDate($local, $cutoff);
        [$shiftId, $shiftCode, $shiftDate] = $this->shift($db, $plantId, $local, $cutoff);

        if ($shiftDate !== null) {
            $productionDate = $shiftDate; // ADR-0012 §4: the whole shift belongs to the production date of its start
        }

        return new ResolvedTime(
            timezone: $zone->getName(),
            calendarDate: $local->format('Y-m-d'),
            productionDate: $productionDate,
            isoWeek: (new DateTimeImmutable($productionDate, $zone))->format('o-\\WW'),
            shiftId: $shiftId,
            shiftCode: $shiftCode,
            shiftDate: $shiftDate,
        );
    }

    private function productionDate(DateTimeImmutable $local, string $cutoff): string
    {
        return $local->format('H:i:s') >= $this->normalizeTime($cutoff)
            ? $local->format('Y-m-d')
            : $local->modify('-1 day')->format('Y-m-d');
    }

    /**
     * The shift the instant falls in (doc 40 C-1): a shift of the local date's weekday that has started, or a shift of the day
     * before that runs past midnight and has not ended yet; it belongs to the production date of its start (ADR-0012 §4).
     *
     * @return array{0: ?string, 1: ?string, 2: ?string}
     */
    private function shift(EoraPostgresConnection $db, string $plantId, DateTimeImmutable $local, string $cutoff): array
    {
        $time = $local->format('H:i:s');
        $yesterday = $local->modify('-1 day');
        foreach ([[$local, false], [$yesterday, true]] as [$day, $carried]) {
            foreach ($this->shifts->shiftsOn($db, $plantId, $day->format('Y-m-d')) as $shift) {
                $inside = $carried
                    ? $shift['crossesMidnight'] && $time < $shift['endsAt']
                    : $time >= $shift['startsAt'] && ($shift['crossesMidnight'] || $time < $shift['endsAt']);
                if ($inside) {
                    $shiftStart = new DateTimeImmutable($day->format('Y-m-d').' '.$shift['startsAt'], $local->getTimezone());

                    return [$shift['id'], $shift['code'], $this->productionDate($shiftStart, $cutoff)];
                }
            }
        }

        return [null, null, null];
    }

    /**
     * The version effective on the plant's local date: the latest plant override, else the latest tenant default.
     *
     * @param  'business_calendars'|'shift_calendars'  $table
     */
    private function effectiveVersion(EoraPostgresConnection $db, string $table, string $plantId, string $plantDate): ?stdClass
    {
        $row = $db->selectOne(
            "select * from {$table} where (plant_id = ? or plant_id is null) and effective_from <= ?::date
              order by (plant_id is null), effective_from desc limit 1",
            [$plantId, $plantDate],
        );

        return $row instanceof stdClass ? $row : null;
    }

    private function plantZone(EoraPostgresConnection $db, string $plantId): string
    {
        $plant = $db->selectOne('select timezone from plants where id = ?', [$plantId]) ?? throw new RuntimeException('Unknown plant.');

        return (string) $plant->timezone;
    }

    private function normalizeTime(string $time): string
    {
        return substr($time.':00', 0, 8);
    }
}
