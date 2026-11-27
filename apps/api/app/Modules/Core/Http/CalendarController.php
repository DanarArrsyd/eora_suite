<?php

declare(strict_types=1);

namespace App\Modules\Core\Http;

use App\Platform\Access\Authorizer;
use App\Platform\Access\Scope;
use App\Platform\Audit\AuditRecorder;
use App\Platform\Database\EoraPostgresConnection;
use App\Platform\Exceptions\DomainProblem;
use App\Platform\Http\MasterWrites;
use App\Platform\Query\QueryRunner;
use DateTimeZone;
use Illuminate\Database\QueryException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use stdClass;

/**
 * Master Data → Calendars (doc 40, D-142): per plant, the business calendar versions (time zone, production day start),
 * the exception days (holiday, non-working day, extra working day) and the shift calendar versions with each shift's times
 * per ISO weekday (C-1) and break (C-2). New versions are written for the plant (an override of the tenant default);
 * a version that has started never changes (ADR-0012), one that has not started yet may be removed and written again.
 * Exception days belong to the business calendar version in force on their date; from the plant's today on they may be
 * changed or removed. Read with `master.calendar.view`, written with `master.calendar.create` (C-4).
 */
final class CalendarController
{
    use MasterWrites;

    private const string VIEW = 'master.calendar.view';

    private const string MANAGE = 'master.calendar.create';

    public const string TIME = '/^([01]\d|2[0-3]):[0-5]\d$/';

    public function __construct(
        private readonly Authorizer $authorizer,
        private readonly AuditRecorder $audit,
        private readonly QueryRunner $rows,
    ) {}

    /** The plant's calendars: business versions, exception days from a month ago on, shift calendar versions with their shifts. */
    public function show(string $plant): JsonResponse
    {
        $db = $this->db();
        $this->authorizer->requireAnywhere($db, self::VIEW);
        $plant = $this->existing($db, 'plants', $plant);
        $this->authorizer->authorize($db, self::VIEW, $this->plantScope($db, $plant));

        return $this->respondPlant($db, $plant);
    }

    /** A new business calendar version for the plant from a later day: production day start and time zone. */
    public function storeBusiness(Request $request, string $plant): JsonResponse
    {
        $data = $request->validate([
            'effectiveFrom' => ['required', 'date_format:Y-m-d'],
            'productionDayStartsAt' => ['required', 'string', 'regex:'.self::TIME],
            'timezone' => ['nullable', 'string', 'max:64'],
        ]);
        $db = $this->db();
        $plant = $this->manageable($db, $plant);
        if ($data['effectiveFrom'] <= $this->today($db, $plant)) {
            throw ValidationException::withMessages(['effectiveFrom' => 'A new version starts after today; days already worked keep their production dates.']);
        }
        $previous = $db->selectOne(
            'select timezone from business_calendars where (plant_id = ? or plant_id is null) and effective_from <= ?::date order by (plant_id is null), effective_from desc limit 1',
            [$plant, $data['effectiveFrom']],
        );
        $zone = $data['timezone'] ?? (string) ($previous instanceof stdClass
            ? $previous->timezone
            : $db->selectOne('select timezone from plants where id = ?', [$plant])->timezone);
        if (! in_array($zone, DateTimeZone::listIdentifiers(), true)) {
            throw ValidationException::withMessages(['timezone' => 'Choose a time zone such as Asia/Jakarta.']);
        }
        $before = $this->businessVersions($db, $plant);
        $id = $this->insert($db,
            'insert into business_calendars (tenant_id, plant_id, timezone, production_day_starts_at, effective_from)
             values ((select c.tenant from ctx_api.context() c), ?, ?, ?, ?) returning id',
            [$plant, $zone, $data['productionDayStartsAt'], $data['effectiveFrom']],
            'The plant has a business calendar version from this day already.');
        // exception days already entered from that day on move to the new version wherever it takes over: a new day start
        // never resets the holidays or extra working days planning counts (only the version in force on a date is read)
        $after = $this->businessVersions($db, $plant);
        $copied = 0;
        $exceptions = $before === [] ? [] : $db->select(
            'select calendar_id, exception_date::text as d, kind, description from calendar_exceptions
              where calendar_id in ('.implode(',', array_fill(0, count($before), '?')).') and exception_date >= ?::date',
            [...array_map(static fn (stdClass $v) => $v->id, $before), $data['effectiveFrom']],
        );
        foreach ($exceptions as $e) {
            if ($this->inForce($before, (string) $e->d) === (string) $e->calendar_id && $this->inForce($after, (string) $e->d) === $id) {
                $db->insert(
                    'insert into calendar_exceptions (tenant_id, calendar_id, exception_date, kind, description)
                     values ((select c.tenant from ctx_api.context() c), ?, ?, ?, ?)',
                    [$id, $e->d, $e->kind, $e->description],
                );
                $copied++;
            }
        }
        $this->audit->record($db, 'business_calendar.created', 'business_calendar', $id, null, $this->state($db, 'business_calendars', $id), ['copiedExceptionDays' => $copied], $plant);

        return $this->respondPlant($db, $plant, 201);
    }

    /** Removes a business calendar version that has not started yet (its exception days with it). */
    public function destroyBusiness(string $calendar): JsonResponse
    {
        $db = $this->db();
        $this->authorizer->requireAnywhere($db, self::MANAGE);
        $id = $this->existing($db, 'business_calendars', $calendar);
        $row = $db->selectOne('select plant_id from business_calendars where id = ?', [$id]);
        $plant = $this->scopeOf($db, $row->plant_id);
        $before = $this->state($db, 'business_calendars', $id);
        $this->delete($db, 'delete from business_calendars where id = ?', $id, 'This version is in effect already; create a new version instead.');
        $this->audit->record($db, 'business_calendar.removed', 'business_calendar', $id, $before, null, [], $plant);

        return $this->respondPlant($db, $plant ?? $this->anyPlant($db));
    }

    /** An exception day on the business calendar version in force on that date at the plant. */
    public function storeException(Request $request, string $plant): JsonResponse
    {
        $data = $request->validate([
            'date' => ['required', 'date_format:Y-m-d'],
            'kind' => ['required', 'in:HOLIDAY,NON_WORKING,WORKING'],
            'description' => ['nullable', 'string', 'max:200'],
        ]);
        $db = $this->db();
        $this->authorizer->requireAnywhere($db, self::MANAGE);
        $plant = $this->existing($db, 'plants', $plant);
        if ($data['date'] < $this->today($db, $plant)) {
            throw ValidationException::withMessages(['date' => 'Days already past keep their calendar. Choose today or a later day.']);
        }
        $calendar = $db->selectOne(
            'select id, plant_id from business_calendars where (plant_id = ? or plant_id is null) and effective_from <= ?::date order by (plant_id is null), effective_from desc limit 1',
            [$plant, $data['date']],
        ) ?? throw ValidationException::withMessages(['date' => 'No business calendar is in force on this day.']);
        $this->authorizeScope($db, $calendar->plant_id, $plant);
        $id = $this->insert($db,
            'insert into calendar_exceptions (tenant_id, calendar_id, exception_date, kind, description)
             values ((select c.tenant from ctx_api.context() c), ?, ?, ?, ?) returning id',
            [$calendar->id, $data['date'], $data['kind'], self::optionalText($data['description'] ?? null)],
            'This day has an exception already; change it instead.');
        $this->audit->record($db, 'calendar_exception.created', 'calendar_exception', $id, null, $this->state($db, 'calendar_exceptions', $id), [], $plant);

        return $this->respondPlant($db, $plant, 201);
    }

    /** Changes the kind or description of an exception day from today on. */
    public function updateException(Request $request, string $exception): JsonResponse
    {
        $data = $request->validate([
            'plantId' => ['required', 'uuid'],
            'kind' => ['required', 'in:HOLIDAY,NON_WORKING,WORKING'],
            'description' => ['nullable', 'string', 'max:200'],
        ]);
        $db = $this->db();
        $this->authorizer->requireAnywhere($db, self::MANAGE);
        $plant = $this->existing($db, 'plants', $data['plantId']);
        $id = $this->existing($db, 'calendar_exceptions', $exception);
        $row = $db->selectOne('select b.plant_id from calendar_exceptions e join business_calendars b on b.id = e.calendar_id where e.id = ? for update of e', [$id]);
        $this->authorizeScope($db, $row->plant_id, $plant);
        $before = $this->state($db, 'calendar_exceptions', $id);
        try {
            $db->update('update calendar_exceptions set kind = ?, description = ? where id = ?', [$data['kind'], self::optionalText($data['description'] ?? null), $id]);
        } catch (QueryException $e) {
            throw $e->getCode() === '23514' ? ValidationException::withMessages(['date' => 'A day already past keeps its calendar.']) : $e;
        }
        $this->audit->record($db, 'calendar_exception.updated', 'calendar_exception', $id, $before, $this->state($db, 'calendar_exceptions', $id), [], $plant);

        return $this->respondPlant($db, $plant);
    }

    /** Removes an exception day from today on: the day follows the normal working week again. */
    public function destroyException(Request $request, string $exception): JsonResponse
    {
        $data = $request->validate(['plantId' => ['required', 'uuid']]);
        $db = $this->db();
        $this->authorizer->requireAnywhere($db, self::MANAGE);
        $plant = $this->existing($db, 'plants', $data['plantId']);
        $id = $this->existing($db, 'calendar_exceptions', $exception);
        $row = $db->selectOne('select b.plant_id from calendar_exceptions e join business_calendars b on b.id = e.calendar_id where e.id = ? for update of e', [$id]);
        $this->authorizeScope($db, $row->plant_id, $plant);
        $before = $this->state($db, 'calendar_exceptions', $id);
        $this->delete($db, 'delete from calendar_exceptions where id = ?', $id, 'A day already past keeps its calendar.');
        $this->audit->record($db, 'calendar_exception.removed', 'calendar_exception', $id, $before, null, [], $plant);

        return $this->respondPlant($db, $plant);
    }

    /**
     * A shift calendar version for the plant with its shifts and their times per weekday (C-1, C-2): the first version may
     * start today, a later one after today. A weekday not listed for a shift means it does not run that day.
     */
    public function storeShifts(Request $request, string $plant): JsonResponse
    {
        $data = $request->validate([
            'code' => ['required', 'string', 'regex:/^[A-Z0-9][A-Z0-9_-]{0,19}$/'],
            'name' => ['required', 'string', 'min:1', 'max:200'],
            'effectiveFrom' => ['required', 'date_format:Y-m-d'],
            'shifts' => ['required', 'array', 'min:1', 'max:6'],
            'shifts.*.code' => ['required', 'string', 'regex:/^[A-Z0-9][A-Z0-9_-]{0,9}$/', 'distinct'],
            'shifts.*.name' => ['required', 'string', 'min:1', 'max:100'],
            'shifts.*.days' => ['required', 'array', 'min:1', 'max:7'],
            'shifts.*.days.*.isoWeekday' => ['required', 'integer', 'min:1', 'max:7'],
            'shifts.*.days.*.startsAt' => ['required', 'string', 'regex:'.self::TIME],
            'shifts.*.days.*.endsAt' => ['required', 'string', 'regex:'.self::TIME],
            'shifts.*.days.*.breakMinutes' => ['required', 'integer', 'min:0', 'max:720'],
        ]);
        $db = $this->db();
        $plant = $this->manageable($db, $plant);
        $today = $this->today($db, $plant);
        $own = $db->selectOne('select 1 as ok from shift_calendars where plant_id = ?', [$plant]) !== null;
        if ($data['effectiveFrom'] < $today || ($own && $data['effectiveFrom'] === $today)) {
            throw ValidationException::withMessages(['effectiveFrom' => $own
                ? 'A new version starts after today; shifts already worked keep their calendar.'
                : 'The first version starts today or later.']);
        }
        foreach ($data['shifts'] as $i => $shift) {
            $seen = [];
            foreach ($shift['days'] as $j => $day) {
                if (isset($seen[$day['isoWeekday']])) {
                    throw ValidationException::withMessages(["shifts.{$i}.days.{$j}.isoWeekday" => 'Each weekday once per shift.']);
                }
                $seen[$day['isoWeekday']] = true;
                $length = self::minutes($day['endsAt']) - self::minutes($day['startsAt']);
                $length = $length <= 0 ? $length + 1440 : $length;
                if ($day['startsAt'] === $day['endsAt']) {
                    throw ValidationException::withMessages(["shifts.{$i}.days.{$j}.endsAt" => 'A shift ends at another time than it starts.']);
                }
                if ((int) $day['breakMinutes'] >= $length) {
                    throw ValidationException::withMessages(["shifts.{$i}.days.{$j}.breakMinutes" => 'The break is shorter than the shift.']);
                }
            }
        }
        $id = $this->insert($db,
            'insert into shift_calendars (tenant_id, plant_id, code, name, effective_from) values ((select c.tenant from ctx_api.context() c), ?, ?, ?, ?) returning id',
            [$plant, $data['code'], trim($data['name']), $data['effectiveFrom']],
            'The plant has a shift calendar version from this day already.');
        foreach ($data['shifts'] as $shift) {
            $days = $shift['days'];
            usort($days, static fn (array $a, array $b): int => $a['isoWeekday'] <=> $b['isoWeekday']);
            // the shift's own times are its first weekday's; the weekday rows decide when it runs
            $shiftId = (string) $db->selectOne(
                'insert into shifts (tenant_id, shift_calendar_id, code, name, starts_at, ends_at) values ((select c.tenant from ctx_api.context() c), ?, ?, ?, ?, ?) returning id',
                [$id, $shift['code'], trim($shift['name']), $days[0]['startsAt'], $days[0]['endsAt']],
            )->id;
            foreach ($days as $day) {
                $db->insert(
                    'insert into shift_weekday_times (tenant_id, shift_id, iso_weekday, starts_at, ends_at, break_minutes) values ((select c.tenant from ctx_api.context() c), ?, ?, ?, ?, ?)',
                    [$shiftId, (int) $day['isoWeekday'], $day['startsAt'], $day['endsAt'], (int) $day['breakMinutes']],
                );
            }
        }
        $this->audit->record($db, 'shift_calendar.created', 'shift_calendar', $id, null, $this->state($db, 'shift_calendars', $id), ['shifts' => $data['shifts']], $plant);

        return $this->respondPlant($db, $plant, 201);
    }

    /** Removes a shift calendar version that has not started yet, its shifts and their times with it. */
    public function destroyShifts(string $calendar): JsonResponse
    {
        $db = $this->db();
        $this->authorizer->requireAnywhere($db, self::MANAGE);
        $id = $this->existing($db, 'shift_calendars', $calendar);
        $row = $db->selectOne('select plant_id from shift_calendars where id = ?', [$id]);
        $plant = $this->scopeOf($db, $row->plant_id);
        $before = $this->state($db, 'shift_calendars', $id);
        $this->delete($db, 'delete from shift_calendars where id = ?', $id, 'This version is in effect already, or an SPK uses its shifts; create a new version instead.');
        $this->audit->record($db, 'shift_calendar.removed', 'shift_calendar', $id, $before, null, [], $plant);

        return $this->respondPlant($db, $plant ?? $this->anyPlant($db));
    }

    /** The plant, when the actor may manage its calendars (404 for an unknown plant, 403 outside the scope). */
    private function manageable(EoraPostgresConnection $db, string $plant): string
    {
        $this->authorizer->requireAnywhere($db, self::MANAGE);
        $plant = $this->existing($db, 'plants', $plant);
        $this->authorizer->authorize($db, self::MANAGE, $this->plantScope($db, $plant));

        return $plant;
    }

    /** A plant's version needs the plant in scope; the tenant default needs the whole tenant. */
    private function authorizeScope(EoraPostgresConnection $db, mixed $calendarPlant, string $plant): void
    {
        $this->authorizer->authorize($db, self::MANAGE, $calendarPlant === null ? Scope::tenant() : $this->plantScope($db, (string) $calendarPlant));
        if ($calendarPlant !== null && (string) $calendarPlant !== $plant) {
            throw new DomainProblem(409, 'INVALID_STATE_TRANSITION', 'Other plant', 'This calendar belongs to another plant.');
        }
    }

    /** Checks the scope of a version's owner (plant, or the tenant default) and returns its plant id or null. */
    private function scopeOf(EoraPostgresConnection $db, mixed $plant): ?string
    {
        $this->authorizer->authorize($db, self::MANAGE, $plant === null ? Scope::tenant() : $this->plantScope($db, (string) $plant));

        return $plant === null ? null : (string) $plant;
    }

    private function anyPlant(EoraPostgresConnection $db): string
    {
        return (string) $db->selectOne('select id from plants order by code limit 1')->id;
    }

    private function delete(EoraPostgresConnection $db, string $sql, string $id, string $refused): void
    {
        try {
            $db->transaction(fn () => $db->delete($sql, [$id]));
        } catch (QueryException $e) {
            if (in_array($e->getCode(), ['23514', '23503'], true)) {
                throw new DomainProblem(409, 'INVALID_STATE_TRANSITION', 'Not removable', $refused);
            }
            throw $e;
        }
    }

    private function today(EoraPostgresConnection $db, string $plant): string
    {
        return (string) $db->selectOne('select eora_calendar_scope_today((select c.tenant from ctx_api.context() c), ?::uuid)::text as d', [$plant])->d;
    }

    private static function minutes(string $time): int
    {
        [$h, $m] = array_map('intval', explode(':', $time));

        return $h * 60 + $m;
    }

    private function respondPlant(EoraPostgresConnection $db, string $plant, int $status = 200): JsonResponse
    {
        $today = $this->today($db, $plant);
        $p = $db->selectOne('select id, code, name, company_id from plants where id = ?', [$plant]);
        $manage = $this->authorizer->scopes($db, self::MANAGE);
        $covers = static fn (Scope $target): bool => array_filter($manage, static fn (Scope $s) => $s->covers($target)) !== [];
        $mayPlant = $covers(Scope::plant($plant, (string) $p->company_id));
        $mayTenant = $covers(Scope::tenant());

        $business = $db->select(
            "select id, plant_id, effective_from::text as since, timezone, to_char(production_day_starts_at, 'HH24:MI') as starts
               from business_calendars where plant_id = ? or plant_id is null order by effective_from desc, (plant_id is null)",
            [$plant],
        );
        $inForce = $this->inForce($business, $today);
        $shiftCalendars = $db->select(
            'select id, plant_id, code, name, effective_from::text as since from shift_calendars where plant_id = ? or plant_id is null order by effective_from desc, (plant_id is null)',
            [$plant],
        );
        $shiftInForce = $this->inForce($shiftCalendars, $today);
        $stateOf = static fn (stdClass $v, ?string $current): string => (string) $v->id === $current ? 'IN_FORCE' : ((string) $v->since > $today ? 'FUTURE' : 'PAST');
        $removable = static fn (stdClass $v): bool => (string) $v->since > $today && ($v->plant_id === null ? $mayTenant : $mayPlant);

        $exceptions = $business === [] ? [] : $db->select(
            'select e.id, e.calendar_id, e.exception_date::text as d, e.kind, e.description, b.plant_id
               from calendar_exceptions e join business_calendars b on b.id = e.calendar_id
              where e.calendar_id in ('.implode(',', array_fill(0, count($business), '?')).') and e.exception_date >= ?::date - 31
              order by e.exception_date',
            [...array_map(static fn (stdClass $v) => $v->id, $business), $today],
        );
        // only the exception days of the version that is in force on each date count
        $exceptions = array_values(array_filter($exceptions, fn (stdClass $e): bool => $this->inForce($business, (string) $e->d) === (string) $e->calendar_id));

        $shifts = [];
        foreach ($shiftCalendars === [] ? [] : $db->select(
            "select s.id, s.shift_calendar_id, s.code, s.name, to_char(s.starts_at, 'HH24:MI') as starts, to_char(s.ends_at, 'HH24:MI') as ends,
                    w.iso_weekday, to_char(w.starts_at, 'HH24:MI') as w_starts, to_char(w.ends_at, 'HH24:MI') as w_ends, w.break_minutes
               from shifts s left join shift_weekday_times w on w.tenant_id = s.tenant_id and w.shift_id = s.id
              where s.shift_calendar_id in (".implode(',', array_fill(0, count($shiftCalendars), '?')).')
              order by s.starts_at, s.code, w.iso_weekday',
            array_map(static fn (stdClass $v) => $v->id, $shiftCalendars),
        ) as $r) {
            $key = (string) $r->id;
            $shifts[(string) $r->shift_calendar_id][$key] ??= ['id' => $key, 'code' => (string) $r->code, 'name' => (string) $r->name, 'days' => []];
            $days = $r->iso_weekday === null
                ? array_map(static fn (int $d) => [$d, (string) $r->starts, (string) $r->ends, 0], range(1, 7))
                : [[(int) $r->iso_weekday, (string) $r->w_starts, (string) $r->w_ends, (int) $r->break_minutes]];
            foreach ($days as [$weekday, $start, $end, $break]) {
                $length = self::minutes($end) - self::minutes($start);
                $length = $length <= 0 ? $length + 1440 : $length;
                $shifts[(string) $r->shift_calendar_id][$key]['days'][] = ['isoWeekday' => $weekday, 'startsAt' => $start, 'endsAt' => $end,
                    'breakMinutes' => $break, 'workMinutes' => $length - $break];
            }
        }

        return response()->json(['data' => [
            'plant' => ['id' => (string) $p->id, 'code' => (string) $p->code, 'name' => (string) $p->name],
            'today' => $today,
            'canManage' => $mayPlant,
            'business' => array_map(static fn (stdClass $v) => ['id' => (string) $v->id, 'scope' => $v->plant_id === null ? 'TENANT' : 'PLANT',
                'effectiveFrom' => (string) $v->since, 'timezone' => (string) $v->timezone, 'productionDayStartsAt' => (string) $v->starts,
                'status' => $stateOf($v, $inForce), 'removable' => $removable($v)], $business),
            'exceptions' => array_map(static fn (stdClass $e) => ['id' => (string) $e->id, 'date' => (string) $e->d, 'kind' => (string) $e->kind,
                'description' => $e->description, 'scope' => $e->plant_id === null ? 'TENANT' : 'PLANT',
                'editable' => (string) $e->d >= $today && ($e->plant_id === null ? $mayTenant : $mayPlant)], $exceptions),
            'shiftCalendars' => array_map(static fn (stdClass $v) => ['id' => (string) $v->id, 'scope' => $v->plant_id === null ? 'TENANT' : 'PLANT',
                'code' => (string) $v->code, 'name' => (string) $v->name, 'effectiveFrom' => (string) $v->since,
                'status' => $stateOf($v, $shiftInForce), 'removable' => $removable($v),
                'shifts' => array_values($shifts[(string) $v->id] ?? [])], $shiftCalendars),
        ]], $status);
    }

    /** @return array<stdClass> the plant's business calendar versions and the tenant defaults, as {@see inForce()} reads them */
    private function businessVersions(EoraPostgresConnection $db, string $plant): array
    {
        return $db->select(
            'select id, plant_id, effective_from::text as since from business_calendars where plant_id = ? or plant_id is null order by effective_from desc, (plant_id is null)',
            [$plant],
        );
    }

    /**
     * The version in force on a date: the latest plant version from on or before it, else the latest tenant default.
     *
     * @param  array<stdClass>  $versions
     */
    private function inForce(array $versions, string $date): ?string
    {
        foreach ([true, false] as $plant) {
            foreach ($versions as $v) {
                if (($v->plant_id !== null) === $plant && (string) $v->since <= $date) {
                    return (string) $v->id;
                }
            }
        }

        return null;
    }
}
