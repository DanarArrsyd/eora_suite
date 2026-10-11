<?php

declare(strict_types=1);

use App\Platform\Calendar\BusinessCalendar;
use App\Platform\Calendar\ResolvedTime;
use App\Platform\Database\EoraPostgresConnection;
use App\Platform\Tenancy\TenantContext;
use App\Platform\Tenancy\TenantTransaction;
use Illuminate\Database\QueryException;
use Tests\Support\TenantFixtures;

/*
 * Business calendar (ADR-0012, D-044, doc 04 §Time model; doc 14 #62).
 */

function inT(array $t, Closure $work): mixed
{
    return app(TenantTransaction::class)->run(TenantContext::forTenant($t['tenant'], $t['actor']), $work);
}

/** Tenant default calendar Asia/Jakarta, cut-off 07:00, shifts S1 07–15, S2 15–23, S3 23–07 (doc 14 #62). */
function configureJakarta(array $t): void
{
    inT($t, function (EoraPostgresConnection $db) use ($t) {
        $db->insert("insert into business_calendars (tenant_id, plant_id, timezone, production_day_starts_at, effective_from) values (?, null, 'Asia/Jakarta', '07:00', '2026-01-01')", [$t['tenant']]);
        $sc = $db->selectOne("insert into shift_calendars (tenant_id, plant_id, code, name, effective_from) values (?, null, 'STD', 'Standard 3 shifts', '2026-01-01') returning id", [$t['tenant']])->id;
        foreach ([['S1', '07:00', '15:00'], ['S2', '15:00', '23:00'], ['S3', '23:00', '07:00']] as [$code, $from, $to]) {
            $db->insert('insert into shifts (tenant_id, shift_calendar_id, code, name, starts_at, ends_at) values (?, ?, ?, ?, ?, ?)', [$t['tenant'], $sc, $code, "Shift {$code}", $from, $to]);
        }
    });
}

function resolveAt(array $t, string $plant, string $instant): ResolvedTime
{
    return inT($t, fn (EoraPostgresConnection $db) => app(BusinessCalendar::class)->resolve($db, $plant, new DateTimeImmutable($instant)));
}

beforeEach(function () {
    $this->t = TenantFixtures::provision();
    configureJakarta($this->t);
});

it('#62: a Tuesday 02:30 WIB posting in shift S3 belongs to Monday and Monday\'s ISO week', function () {
    $r = resolveAt($this->t, $this->t['plants'][0], '2026-10-13T02:30:00+07:00');

    expect($r->calendarDate)->toBe('2026-10-13')
        ->and($r->productionDate)->toBe('2026-10-12')
        ->and($r->shiftCode)->toBe('S3')
        ->and($r->shiftDate)->toBe('2026-10-12')
        ->and($r->isoWeek)->toBe('2026-W42');
})->group('integration', 'calendar');

it('applies the cut-off at the boundary and assigns day shifts to the same date', function () {
    $before = resolveAt($this->t, $this->t['plants'][0], '2026-10-13T06:59:59+07:00');
    $at = resolveAt($this->t, $this->t['plants'][0], '2026-10-13T07:00:00+07:00');
    $evening = resolveAt($this->t, $this->t['plants'][0], '2026-10-13T23:30:00+07:00');

    expect([$before->productionDate, $before->shiftCode])->toBe(['2026-10-12', 'S3'])
        ->and([$at->productionDate, $at->shiftCode])->toBe(['2026-10-13', 'S1'])
        ->and([$evening->calendarDate, $evening->productionDate, $evening->shiftCode])->toBe(['2026-10-13', '2026-10-13', 'S3']);
})->group('integration', 'calendar');

it('uses the ISO week of the production date: Sunday night shift stays in the old week', function () {
    $sundayNight = resolveAt($this->t, $this->t['plants'][0], '2026-10-19T03:00:00+07:00'); // Monday 03:00, S3 started Sunday

    expect($sundayNight->calendarDate)->toBe('2026-10-19')
        ->and($sundayNight->productionDate)->toBe('2026-10-18')
        ->and($sundayNight->isoWeek)->toBe('2026-W42');
})->group('integration', 'calendar');

it('lets a plant override the tenant default (other timezone, other cut-off)', function () {
    $plant = $this->t['plants'][1];
    inT($this->t, fn (EoraPostgresConnection $db) => $db->insert(
        "insert into business_calendars (tenant_id, plant_id, timezone, production_day_starts_at, effective_from) values (?, ?, 'Asia/Makassar', '06:00', '2026-01-01')",
        [$this->t['tenant'], $plant],
    ));

    // 2026-10-12 21:30 UTC = Tue 05:30 WITA (before the 06:00 cut-off) but Tue 04:30 WIB at the other plant
    $override = resolveAt($this->t, $plant, '2026-10-12T21:30:00Z');
    $default = resolveAt($this->t, $this->t['plants'][0], '2026-10-12T21:30:00Z');

    expect([$override->timezone, $override->calendarDate, $override->productionDate])->toBe(['Asia/Makassar', '2026-10-13', '2026-10-12'])
        ->and([$default->timezone, $default->calendarDate, $default->productionDate])->toBe(['Asia/Jakarta', '2026-10-13', '2026-10-12']);
})->group('integration', 'calendar');

it('applies a new calendar version only from its effective date; earlier dates never move', function () {
    // new version from 1 Nov: cut-off 06:00 with shifts moved one hour earlier (S1 06–14, S2 14–22, S3 22–06)
    inT($this->t, function (EoraPostgresConnection $db) {
        $db->insert("insert into business_calendars (tenant_id, plant_id, timezone, production_day_starts_at, effective_from) values (?, null, 'Asia/Jakarta', '06:00', '2026-11-01')", [$this->t['tenant']]);
        $sc = $db->selectOne("insert into shift_calendars (tenant_id, plant_id, code, name, effective_from) values (?, null, 'STD2', 'Standard from Nov', '2026-11-01') returning id", [$this->t['tenant']])->id;
        foreach ([['S1', '06:00', '14:00'], ['S2', '14:00', '22:00'], ['S3', '22:00', '06:00']] as [$code, $from, $to]) {
            $db->insert('insert into shifts (tenant_id, shift_calendar_id, code, name, starts_at, ends_at) values (?, ?, ?, ?, ?, ?)', [$this->t['tenant'], $sc, $code, "Shift {$code}", $from, $to]);
        }
    });

    // 06:30 Tue: still S3 of Monday under the old version; S1 of the same day under the new one
    $old = resolveAt($this->t, $this->t['plants'][0], '2026-10-13T06:30:00+07:00');
    $new = resolveAt($this->t, $this->t['plants'][0], '2026-11-03T06:30:00+07:00');

    expect([$old->productionDate, $old->shiftCode])->toBe(['2026-10-12', 'S3'])
        ->and([$new->productionDate, $new->shiftCode])->toBe(['2026-11-03', 'S1']);
})->group('integration', 'calendar');

it('refuses to guess when no calendar is configured', function () {
    $other = TenantFixtures::provision(1);

    resolveAt($other, $other['plants'][0], '2026-10-13T08:00:00+07:00');
})->throws(RuntimeException::class, 'No business calendar')->group('integration', 'calendar');

it('keeps calendars tenant-isolated and versions immutable for the runtime role', function () {
    $other = TenantFixtures::provision(1);
    $visible = inT($other, fn (EoraPostgresConnection $db) => count($db->select('select id from business_calendars')));

    expect($visible)->toBe(0);
    expect(fn () => inT($this->t, fn (EoraPostgresConnection $db) => $db->update("update business_calendars set production_day_starts_at = '05:00'")))
        ->toThrow(QueryException::class, 'permission denied');
})->group('integration', 'calendar');
