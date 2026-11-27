<?php

declare(strict_types=1);

use App\Platform\Calendar\BusinessCalendar;
use App\Platform\Calendar\ShiftCalendar;
use App\Platform\Database\EoraPostgresConnection;
use Illuminate\Database\QueryException;
use Tests\Support\AccessFixtures;
use Tests\Support\TenantFixtures;

/*
 * Calendars screen (doc 40, D-142): business calendar versions, exception days and shift calendars with times per weekday
 * (C-1), breaks (C-2), capacity per shift (C-3), CSA / PPC manage (C-4).
 */

beforeEach(function () {
    $this->t = TenantFixtures::provision(2);
    AccessFixtures::roles($this->t);
    $this->plant = $this->t['plants'][0];
    AccessFixtures::run($this->t, fn (EoraPostgresConnection $db) => $db->insert(
        "insert into business_calendars (tenant_id, plant_id, timezone, production_day_starts_at, effective_from) values (?, null, 'Asia/Jakarta', '07:00', '2026-01-01')",
        [$this->t['tenant']],
    ));
});

/** The factory week of doc 40 §1, shifts 1 and 2 (C-6): Mon–Thu, Friday and Saturday times with their breaks. */
function calTwoShifts(): array
{
    $day = static fn (int $d, string $from, string $to, int $break) => ['isoWeekday' => $d, 'startsAt' => $from, 'endsAt' => $to, 'breakMinutes' => $break];

    return [
        ['code' => 'S1', 'name' => 'Shift 1', 'days' => [
            $day(1, '06:00', '14:00', 60), $day(2, '06:00', '14:00', 60), $day(3, '06:00', '14:00', 60), $day(4, '06:00', '14:00', 60),
            $day(5, '06:00', '14:30', 90), $day(6, '07:00', '12:15', 15)]],
        ['code' => 'S2', 'name' => 'Shift 2', 'days' => [
            $day(1, '14:00', '22:00', 60), $day(2, '14:00', '22:00', 60), $day(3, '14:00', '22:00', 60), $day(4, '14:00', '22:00', 60),
            $day(5, '14:30', '22:30', 60), $day(6, '13:00', '18:15', 15)]],
    ];
}

function calToday(array $t, string $plant): string
{
    return AccessFixtures::run($t, fn (EoraPostgresConnection $db) => (string) $db->selectOne('select eora_calendar_scope_today(?::uuid, ?::uuid)::text as d', [$t['tenant'], $plant])->d);
}

it('writes a shift calendar with times per weekday and breaks; the day\'s shifts and work hours follow the weekday (C-1, C-2)', function () {
    orgUser($this, $this->t, 'PPC');
    $today = calToday($this->t, $this->plant);
    $body = ['code' => 'KMI-2S', 'name' => 'Two shifts', 'effectiveFrom' => $today, 'shifts' => calTwoShifts()];
    $data = spa($this)->postJson("/api/v1/core/plants/{$this->plant}/shift-calendars", $body)->assertCreated()->json('data');
    expect($data['shiftCalendars'])->toHaveCount(1)
        ->and($data['shiftCalendars'][0])->toMatchArray(['code' => 'KMI-2S', 'scope' => 'PLANT', 'status' => 'IN_FORCE', 'removable' => false])
        ->and(array_column($data['shiftCalendars'][0]['shifts'][0]['days'], 'workMinutes'))->toBe([420, 420, 420, 420, 420, 300]);

    // the same version again, or a second version from today, is refused; a break as long as the shift too
    spa($this)->postJson("/api/v1/core/plants/{$this->plant}/shift-calendars", $body)->assertStatus(422)->assertJsonValidationErrors(['effectiveFrom']);
    $bad = calTwoShifts();
    $bad[0]['days'][0]['breakMinutes'] = 480;
    spa($this)->postJson("/api/v1/core/plants/{$this->plant}/shift-calendars", ['effectiveFrom' => date('Y-m-d', strtotime($today.' +7 days')), 'shifts' => $bad] + $body)
        ->assertStatus(422)->assertJsonValidationErrors(['shifts.0.days.0.breakMinutes']);

    $friday = date('Y-m-d', strtotime('friday next week', strtotime($today)));
    $saturday = date('Y-m-d', strtotime($friday.' +1 day'));
    $sunday = date('Y-m-d', strtotime($friday.' +2 days'));
    [$fri, $sat, $sun] = AccessFixtures::run($this->t, fn (EoraPostgresConnection $db) => array_map(
        fn (string $d) => array_map(static fn (array $s) => [$s['code'], $s['startsAt'], $s['workHours']], app(ShiftCalendar::class)->shiftsOn($db, $this->plant, $d)),
        [$friday, $saturday, $sunday]));
    // in the order they run from the day start (07:00 here): Friday's S1 from 06:00 runs last, Saturday's S1 from 07:00 first;
    // hence the 06:00 day start for kmi (C-5)
    expect($fri)->toBe([['S2', '14:30:00', '7.000000'], ['S1', '06:00:00', '7.000000']])
        ->and($sat)->toBe([['S1', '07:00:00', '5.000000'], ['S2', '13:00:00', '5.000000']])
        ->and($sun)->toBe([]);

    // postings: Saturday 13:30 is S2, Friday 23:00 is in no shift (production date from the day start)
    $resolve = fn (string $at) => AccessFixtures::run($this->t, fn (EoraPostgresConnection $db) => app(BusinessCalendar::class)->resolve($db, $this->plant, new DateTimeImmutable($at)));
    $s2 = $resolve("{$saturday}T13:30:00+07:00");
    $late = $resolve("{$friday}T23:00:00+07:00");
    expect([$s2->shiftCode, $s2->productionDate])->toBe(['S2', $saturday])
        ->and([$late->shiftCode, $late->productionDate])->toBe([null, $friday]);
})->group('integration', 'calendar');

it('caps a shift by the work center hours per shift (C-3)', function () {
    expect(ShiftCalendar::capacity('7.000000', '8'))->toBe('7.000000')
        ->and(ShiftCalendar::capacity('5.000000', '7'))->toBe('5.000000')
        ->and(ShiftCalendar::capacity('7.000000', '6.5'))->toBe('6.5')
        ->and(ShiftCalendar::capacity('7.000000', null))->toBe('7.000000')
        ->and(ShiftCalendar::capacity(null, '7'))->toBe('7')
        ->and(ShiftCalendar::capacity(null, null))->toBeNull();
})->group('calendar');

it('keeps started versions; a later one may be removed before it starts and the day start moves from a later day (ADR-0012, C-5)', function () {
    orgUser($this, $this->t, 'CSA');
    $today = calToday($this->t, $this->plant);
    $tomorrow = date('Y-m-d', strtotime($today.' +1 day'));
    spa($this)->postJson("/api/v1/core/plants/{$this->plant}/business-calendars", ['effectiveFrom' => $today, 'productionDayStartsAt' => '06:00'])
        ->assertStatus(422)->assertJsonValidationErrors(['effectiveFrom']);
    $data = spa($this)->postJson("/api/v1/core/plants/{$this->plant}/business-calendars", ['effectiveFrom' => $tomorrow, 'productionDayStartsAt' => '06:00'])
        ->assertCreated()->json('data');
    $future = collect($data['business'])->firstWhere('effectiveFrom', $tomorrow);
    $current = collect($data['business'])->firstWhere('status', 'IN_FORCE');
    expect($future)->toMatchArray(['scope' => 'PLANT', 'timezone' => 'Asia/Jakarta', 'productionDayStartsAt' => '06:00', 'status' => 'FUTURE', 'removable' => true])
        ->and($current)->toMatchArray(['scope' => 'TENANT', 'productionDayStartsAt' => '07:00', 'removable' => false]);
    spa($this)->deleteJson("/api/v1/core/business-calendars/{$current['id']}")->assertStatus(409);
    spa($this)->deleteJson("/api/v1/core/business-calendars/{$future['id']}")->assertOk()->assertJsonCount(1, 'data.business');

    $shifts = ['code' => 'KMI-2S', 'name' => 'Two shifts', 'effectiveFrom' => $today, 'shifts' => calTwoShifts()];
    $first = spa($this)->postJson("/api/v1/core/plants/{$this->plant}/shift-calendars", $shifts)->assertCreated()->json('data.shiftCalendars.0');
    $next = spa($this)->postJson("/api/v1/core/plants/{$this->plant}/shift-calendars", ['effectiveFrom' => $tomorrow] + $shifts)->assertCreated()
        ->json('data.shiftCalendars.0');
    expect($next['status'])->toBe('FUTURE');
    spa($this)->deleteJson("/api/v1/core/shift-calendars/{$first['id']}")->assertStatus(409);
    spa($this)->deleteJson("/api/v1/core/shift-calendars/{$next['id']}")->assertOk()->assertJsonCount(1, 'data.shiftCalendars');

    // the database keeps it too: weekday times of a version in effect never change
    $ppc = orgUser($this, $this->t, 'PPC');
    try {
        asApp($this->t, (string) $ppc['id'], fn (EoraPostgresConnection $db) => $db->delete('delete from shift_weekday_times'));
        $this->fail('removed weekday times of a version in effect');
    } catch (QueryException $e) {
        expect($e->getCode())->toBe('23514');
    }
})->group('integration', 'calendar');

it('writes exception days from today on; past days stay (C-7)', function () {
    orgUser($this, $this->t, 'CSA');
    $today = calToday($this->t, $this->plant);
    $next = date('Y-m-d', strtotime($today.' +3 days'));
    $url = "/api/v1/core/plants/{$this->plant}/calendar-exceptions";
    spa($this)->postJson($url, ['date' => date('Y-m-d', strtotime($today.' -1 day')), 'kind' => 'HOLIDAY'])->assertStatus(422)->assertJsonValidationErrors(['date']);
    $e = collect(spa($this)->postJson($url, ['date' => $next, 'kind' => 'HOLIDAY', 'description' => 'Company holiday'])->assertCreated()->json('data.exceptions'))
        ->firstWhere('date', $next);
    expect($e)->toMatchArray(['kind' => 'HOLIDAY', 'description' => 'Company holiday', 'scope' => 'TENANT', 'editable' => true]);
    spa($this)->postJson($url, ['date' => $next, 'kind' => 'NON_WORKING'])->assertStatus(422);
    spa($this)->patchJson("/api/v1/core/calendar-exceptions/{$e['id']}", ['plantId' => $this->plant, 'kind' => 'NON_WORKING', 'description' => null])
        ->assertOk()->assertJsonPath('data.exceptions.0.kind', 'NON_WORKING');
    spa($this)->deleteJson("/api/v1/core/calendar-exceptions/{$e['id']}", ['plantId' => $this->plant])->assertOk()->assertJsonCount(0, 'data.exceptions');
    expect(AccessFixtures::run($this->t, fn (EoraPostgresConnection $db) => array_column($db->select(
        "select action from audit_events where entity_type = 'calendar_exception' order by occurred_at, id"), 'action')))
        ->toBe(['calendar_exception.created', 'calendar_exception.updated', 'calendar_exception.removed']);
})->group('integration', 'calendar');

it('lets viewers read and only CSA / PPC write, inside their scope (C-4)', function () {
    $today = calToday($this->t, $this->plant);
    rtSwitch($this, $this->t, 'MAT');
    spa($this)->getJson("/api/v1/core/plants/{$this->plant}/calendars")->assertOk()->assertJsonPath('data.canManage', false)->assertJsonCount(1, 'data.business');
    spa($this)->postJson("/api/v1/core/plants/{$this->plant}/shift-calendars", ['code' => 'X', 'name' => 'X', 'effectiveFrom' => $today, 'shifts' => calTwoShifts()])
        ->assertForbidden();
    rtSwitch($this, $this->t, 'PPC', ['PLANT', $this->t['plants'][1]]);
    spa($this)->postJson("/api/v1/core/plants/{$this->plant}/shift-calendars", ['code' => 'X', 'name' => 'X', 'effectiveFrom' => $today, 'shifts' => calTwoShifts()])
        ->assertForbidden();
    // a plant-scoped planner cannot write on the tenant default calendar (exception days of other plants)
    spa($this)->postJson("/api/v1/core/plants/{$this->t['plants'][1]}/calendar-exceptions", ['date' => $today, 'kind' => 'HOLIDAY'])->assertForbidden();
})->group('integration', 'calendar');
