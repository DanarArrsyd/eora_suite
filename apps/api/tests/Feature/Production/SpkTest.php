<?php

declare(strict_types=1);

use App\Platform\Database\EoraPostgresConnection;
use App\Platform\Outbox\OutboxRelay;
use Illuminate\Database\QueryException;
use Tests\Support\AccessFixtures;
use Tests\Support\TenantFixtures;

/*
 * SPK per Line (doc 38, D-138), slice 1: one SPK per line and production day, lines plan released WO operations on the
 * line's machines, hours = qty / SPH + setup / 60 + QC sample / 60 against the line's hours per shift, numbered
 * SPK-<company>-<DDMMYY>-<0001> at release, changed after release only through a revision.
 */

/** @return array<string, mixed> mfFixture (a released WO) + the weld line with 7 hours per shift, signed in as PPC */
function spkFixture(object $test, array $t): array
{
    $f = mfFixture($test, $t);
    $company = AccessFixtures::run($t, function (EoraPostgresConnection $db) use ($f) {
        $db->update('update work_centers set available_hours_per_shift = 7 where id = ?', [$f['wc2']['id']]);

        return (string) $db->selectOne('select c.code from plants p join companies c on c.id = p.company_id where p.id = ?', [$f['plant']])->code;
    });
    $ppc = rtSwitch($test, $t, 'PPC');
    $op = AccessFixtures::run($t, fn (EoraPostgresConnection $db) => (string) $db->selectOne(
        'select o.id from routing_operations o join work_orders w on w.routing_version_id = o.routing_version_id where w.id = ? and o.op_seq = 20',
        [$f['wo']['id']],
    )->id);

    return $f + ['company' => $company, 'ppc' => $ppc, 'weldOp' => $op];
}

/** Runs the outbox relay until nothing is due: the release notice travels through the outbox (AGENTS.md). */
function spkDrainOutbox(): void
{
    for ($i = 0; $i < 50 && app(OutboxRelay::class)->runOnce('spk-test-relay', 500) > 0; $i++);
}

function spkLine(array $f, array $over = []): array
{
    return $over + ['workOrderId' => $f['wo']['id'], 'routingOperationId' => $f['weldOp'], 'machineId' => $f['m2']['id'], 'plannedQty' => '600', 'priority' => 1];
}

it('drafts, plans, releases with a daily company number, tells production and revises an SPK', function () {
    $t = TenantFixtures::provision();
    AccessFixtures::roles($t);
    $f = spkFixture($this, $t);
    $prl = orgUser($this, $t, 'PRL');
    rtSwitch($this, $t, 'PPC');

    $spk = spa($this)->postJson('/api/v1/production/spk', ['plantId' => $f['plant'], 'workCenterId' => $f['wc2']['id'], 'productionDate' => $f['today'], 'reference' => '2/STP/I/26'])
        ->assertCreated()->assertJsonPath('data.status', 'DRAFT')->assertJsonPath('data.code', null)->json('data');
    // one SPK per line and day
    spa($this)->postJson('/api/v1/production/spk', ['plantId' => $f['plant'], 'workCenterId' => $f['wc2']['id'], 'productionDate' => $f['today']])
        ->assertStatus(409)->assertJsonPath('code', 'SPK_EXISTS');

    $options = spa($this)->getJson("/api/v1/production/spk/{$spk['id']}/options")->assertOk()->json('data');
    expect(array_column($options['operations'], 'operationId'))->toBe([$f['weldOp']])
        ->and($options['operations'][0]['sph'])->toBe('300')
        ->and(array_column($options['machines'], 'code'))->toBe(['W-1']);

    // 600 pcs at 300 pcs/h + 0 min setup + 5 min QC sample = 2.083 h of the 7 h shift
    $spk = spa($this)->putJson("/api/v1/production/spk/{$spk['id']}", ['reference' => '2/STP/I/26', 'lines' => [spkLine($f)]], ['If-Match' => '"'.$spk['version'].'"'])
        ->assertOk()->json('data');
    expect($spk['lines'][0])->toMatchArray(['plannedQty' => '600', 'sph' => '300', 'hours' => '2.083', 'qcSampleMinutes' => '5'])
        ->and($spk['machineLoad'][0])->toMatchArray(['machineCode' => 'W-1', 'hours' => '2.083', 'capacityHours' => '7', 'balanceHours' => '4.917', 'overloaded' => false]);

    $released = spa($this)->postJson("/api/v1/production/spk/{$spk['id']}/actions/release", [], ['If-Match' => '"'.$spk['version'].'"'])->assertOk()->json('data');
    $day = (new DateTimeImmutable($f['today']))->format('dmy');
    expect($released['status'])->toBe('RELEASED')->and($released['code'])->toBe("SPK-{$f['company']}-{$day}-0001");
    // a released SPK changes only through a revision
    spa($this)->putJson("/api/v1/production/spk/{$spk['id']}", ['lines' => []], ['If-Match' => '"'.$released['version'].'"'])
        ->assertStatus(409)->assertJsonPath('code', 'INVALID_STATE_TRANSITION');

    // production of the plant is told — through the outbox, once — and reads it, but cannot change it
    spkDrainOutbox();
    signIn($this, $prl, $t);
    expect(collect(spa($this)->getJson('/api/v1/inbox')->assertOk()->json('data'))->firstWhere('kind', 'production.spk_released')['deepLink'] ?? null)
        ->toBe("/production/spk-line?spk={$spk['id']}");
    spkDrainOutbox();
    expect(collect(spa($this)->getJson('/api/v1/inbox')->json('data'))->where('kind', 'production.spk_released'))->toHaveCount(1);
    spa($this)->getJson("/api/v1/production/spk/{$spk['id']}")->assertOk()->assertJsonPath('data.lines.0.workOrderCode', $f['wo']['code']);
    spa($this)->getJson('/api/v1/query/production.spk?'.http_build_query(['filter' => ['status' => 'RELEASED']]))->assertOk()->assertJsonPath('data.0.code', $released['code']);
    spa($this)->postJson("/api/v1/production/spk/{$spk['id']}/actions/close", [], ['If-Match' => '"'.$released['version'].'"'])->assertForbidden();
    // who released it, and when, cannot be rewritten afterwards — not by production, not by a planner (Codex on #97)
    expect(fn () => asApp($t, $prl['id'], fn (EoraPostgresConnection $db) => $db->update("update spk_headers set released_at = now() - interval '1 day' where id = ?", [$spk['id']])))
        ->toThrow(QueryException::class, 'records its release once');
    expect(fn () => asApp($t, $f['ppc']['id'], fn (EoraPostgresConnection $db) => $db->update('update spk_headers set released_by = ? where id = ?', [$prl['id'], $spk['id']])))
        ->toThrow(QueryException::class, 'records its release once');
    expect(fn () => asApp($t, $f['ppc']['id'], fn (EoraPostgresConnection $db) => $db->update('update spk_headers set closed_by = ?, closed_at = now() where id = ?', [$prl['id'], $spk['id']])))
        ->toThrow(QueryException::class, 'records its closing once');

    // the planner revises with a reason: the released SPK stays in force until the revision is released, which keeps the number
    rtSwitch($this, $t, 'PPC');
    spa($this)->postJson("/api/v1/production/spk/{$spk['id']}/actions/revise", ['reason' => 'x'], ['If-Match' => '"'.$released['version'].'"'])->assertStatus(422);
    $rev = spa($this)->postJson("/api/v1/production/spk/{$spk['id']}/actions/revise", ['reason' => 'Machine W-1 down in shift 1'], ['If-Match' => '"'.$released['version'].'"'])
        ->assertCreated()->json('data');
    expect($rev)->toMatchArray(['status' => 'DRAFT', 'revision' => 2, 'previousId' => $spk['id'], 'revisionReason' => 'Machine W-1 down in shift 1'])
        ->and($rev['lines'])->toHaveCount(1);
    $rev = spa($this)->putJson("/api/v1/production/spk/{$rev['id']}", ['lines' => [spkLine($f, ['plannedQty' => '2400', 'priority' => 2])]], ['If-Match' => '"'.$rev['version'].'"'])
        ->assertOk()->json('data');
    // 2400 / 300 = 8 h + 5 min: over the 7 h shift — a warning, not a block
    expect($rev['machineLoad'][0])->toMatchArray(['hours' => '8.083', 'overloaded' => true, 'balanceHours' => '-1.083']);
    $rev = spa($this)->postJson("/api/v1/production/spk/{$rev['id']}/actions/release", [], ['If-Match' => '"'.$rev['version'].'"'])->assertOk()->json('data');
    expect($rev['code'])->toBe($released['code']);
    spa($this)->getJson("/api/v1/production/spk/{$spk['id']}")->assertOk()->assertJsonPath('data.status', 'SUPERSEDED');
    expect(array_column($rev['revisions'], 'status'))->toBe(['SUPERSEDED', 'RELEASED']);
    spa($this)->postJson("/api/v1/production/spk/{$rev['id']}/actions/close", [], ['If-Match' => '"'.$rev['version'].'"'])->assertOk()->assertJsonPath('data.status', 'CLOSED');

    // the daily series counts per company: the next SPK of the day is 0002
    $next = spa($this)->postJson('/api/v1/production/spk', ['plantId' => $f['plant'], 'workCenterId' => $f['wc']['id'], 'productionDate' => $f['today']])->assertCreated()->json('data');
    $op10 = AccessFixtures::run($t, fn (EoraPostgresConnection $db) => (string) $db->selectOne(
        'select o.id from routing_operations o join work_orders w on w.routing_version_id = o.routing_version_id where w.id = ? and o.op_seq = 10', [$f['wo']['id']])->id);
    $next = spa($this)->putJson("/api/v1/production/spk/{$next['id']}", ['lines' => [spkLine($f, ['routingOperationId' => $op10, 'machineId' => $f['m1']['id'], 'plannedQty' => '1600'])]],
        ['If-Match' => '"'.$next['version'].'"'])->assertOk()->json('data');
    // a stroke makes 2 pieces every 4.5 s: 1600 pcs/h; 1600 pcs + 30 min setup + 5 min QC = 1.583 h; no hours per shift on this line
    expect($next['lines'][0])->toMatchArray(['sph' => '1600', 'hours' => '1.583', 'setupMinutes' => '30'])
        ->and($next['machineLoad'][0]['capacityHours'])->toBeNull();
    spa($this)->postJson("/api/v1/production/spk/{$next['id']}/actions/release", [], ['If-Match' => '"'.$next['version'].'"'])
        ->assertOk()->assertJsonPath('data.code', "SPK-{$f['company']}-{$day}-0002");
})->group('integration', 'production');

it('plans only released WO operations of the line on its machines, also through direct SQL, inside the tenant', function () {
    $t = TenantFixtures::provision();
    $other = TenantFixtures::provision();
    AccessFixtures::roles($t);
    AccessFixtures::roles($other);
    $f = spkFixture($this, $t);
    $spk = spa($this)->postJson('/api/v1/production/spk', ['plantId' => $f['plant'], 'workCenterId' => $f['wc2']['id'], 'productionDate' => $f['today']])->assertCreated()->json('data');
    $put = fn (array $line) => spa($this)->putJson("/api/v1/production/spk/{$spk['id']}", ['lines' => [$line]], ['If-Match' => '"'.$spk['version'].'"']);

    $put(spkLine($f, ['machineId' => $f['m1']['id']]))->assertStatus(422)->assertJsonValidationErrors(['lines.0.machineId']);
    $op10 = AccessFixtures::run($t, fn (EoraPostgresConnection $db) => (string) $db->selectOne(
        'select o.id from routing_operations o join work_orders w on w.routing_version_id = o.routing_version_id where w.id = ? and o.op_seq = 10', [$f['wo']['id']])->id);
    $put(spkLine($f, ['routingOperationId' => $op10]))->assertStatus(422)->assertJsonValidationErrors(['lines.0.routingOperationId']);
    $draft = woCreate($this, $f)->assertCreated()->json('data');
    $put(spkLine($f, ['workOrderId' => $draft['id']]))->assertStatus(422)->assertJsonValidationErrors(['lines.0.workOrderId']);
    $put(spkLine($f, ['plannedQty' => '0']))->assertStatus(422);
    spa($this)->postJson("/api/v1/production/spk/{$spk['id']}/actions/release", [], ['If-Match' => '"'.$spk['version'].'"'])->assertStatus(422)->assertJsonValidationErrors(['lines']);
    spa($this)->postJson('/api/v1/production/spk', ['plantId' => $f['plant'], 'workCenterId' => $f['wc2']['id'], 'productionDate' => '2026-01-01'])
        ->assertStatus(422)->assertJsonValidationErrors(['productionDate']);

    // the database repeats the rules for the runtime role
    $line = fn (array $over = []) => array_merge(['tenant_id' => $t['tenant'], 'spk_id' => $spk['id'], 'line_no' => 1, 'work_order_id' => $f['wo']['id'],
        'routing_operation_id' => $f['weldOp'], 'machine_id' => $f['m2']['id'], 'planned_qty' => '10'], $over);
    $insert = fn (array $row) => fn (EoraPostgresConnection $db) => $db->insert(
        'insert into spk_lines (tenant_id, spk_id, line_no, work_order_id, routing_operation_id, machine_id, planned_qty) values (?, ?, ?, ?, ?, ?, ?)', array_values($row));
    expect(fn () => asApp($t, $f['ppc']['id'], $insert($line(['machine_id' => $f['m1']['id']]))))->toThrow(QueryException::class, 'active machine of the SPK');
    expect(fn () => asApp($t, $f['ppc']['id'], $insert($line(['routing_operation_id' => $op10]))))->toThrow(QueryException::class, 'operation of the work order');
    expect(fn () => asApp($t, $f['ppc']['id'], $insert($line(['work_order_id' => $draft['id']]))))->toThrow(QueryException::class, 'released work order');
    $prl = orgUser($this, $t, 'PRL');
    expect(fn () => asApp($t, $prl['id'], $insert($line())))->toThrow(QueryException::class, 'written by a planner');
    expect(fn () => asApp($t, $f['ppc']['id'], fn (EoraPostgresConnection $db) => $db->update("update spk_headers set status = 'CLOSED' where id = ?", [$spk['id']])))
        ->toThrow(QueryException::class, 'draft → released');
    // a draft's own text is the planner's too (Codex on #97)
    expect(fn () => asApp($t, $prl['id'], fn (EoraPostgresConnection $db) => $db->update("update spk_headers set reference = 'Taken over' where id = ?", [$spk['id']])))
        ->toThrow(QueryException::class, 'edited by a planner');
    expect(fn () => asApp($t, $prl['id'], fn (EoraPostgresConnection $db) => $db->insert(
        'insert into spk_headers (tenant_id, plant_id, work_center_id, production_date) values (?, ?, ?, ?)', [$t['tenant'], $f['plant'], $f['wc']['id'], $f['today']])))
        ->toThrow(QueryException::class, 'written by a planner');

    // another tenant sees nothing
    expect(AccessFixtures::run($other, fn (EoraPostgresConnection $db) => (int) $db->selectOne('select count(*) as n from spk_headers')->n))->toBe(0);
})->group('integration', 'production');

it('refuses to release an SPK whose work order was cancelled after planning (Codex on #97)', function () {
    $t = TenantFixtures::provision();
    AccessFixtures::roles($t);
    $f = spkFixture($this, $t);
    $wo = woCreate($this, $f, ['qty' => '50'])->assertCreated()->json('data');
    $wo = woAct($this, $wo['id'], 'submit', $wo['version'])->assertOk()->json('data');
    rtSwitch($this, $t, 'CSA');
    $wo = woAct($this, $wo['id'], 'release', $wo['version'])->assertOk()->json('data');
    rtSwitch($this, $t, 'PPC');
    $op = AccessFixtures::run($t, fn (EoraPostgresConnection $db) => (string) $db->selectOne(
        'select o.id from routing_operations o join work_orders w on w.routing_version_id = o.routing_version_id where w.id = ? and o.op_seq = 20', [$wo['id']])->id);
    $spk = spa($this)->postJson('/api/v1/production/spk', ['plantId' => $f['plant'], 'workCenterId' => $f['wc2']['id'], 'productionDate' => $f['today']])->assertCreated()->json('data');
    $spk = spa($this)->putJson("/api/v1/production/spk/{$spk['id']}", ['lines' => [spkLine($f, ['workOrderId' => $wo['id'], 'routingOperationId' => $op])]], ['If-Match' => '"'.$spk['version'].'"'])
        ->assertOk()->json('data');
    woAct($this, $wo['id'], 'cancel', $wo['version'], ['reason' => 'Customer cancelled the order'])->assertOk();

    spa($this)->postJson("/api/v1/production/spk/{$spk['id']}/actions/release", [], ['If-Match' => '"'.$spk['version'].'"'])
        ->assertStatus(409)->assertJsonPath('code', 'INVALID_STATE_TRANSITION');
    expect(fn () => asApp($t, $f['ppc']['id'], fn (EoraPostgresConnection $db) => $db->update(
        "update spk_headers set status = 'RELEASED', spk_no = 'SPK-X-191126-0001', released_at = now() where id = ?", [$spk['id']])))
        ->toThrow(QueryException::class, 'work orders are released');
})->group('integration', 'production');

it('holds each shift of a machine to one shift of hours: pooling shifts never hides an over-full shift (Codex on #97)', function () {
    $t = TenantFixtures::provision();
    AccessFixtures::roles($t);
    $f = spkFixture($this, $t);
    [$s1, $s2] = AccessFixtures::run($t, function (EoraPostgresConnection $db) use ($t, $f) {
        $calendar = (string) $db->selectOne("insert into shift_calendars (tenant_id, plant_id, code, name, effective_from) values (?, ?, 'DAY2', 'Two shifts', '2026-01-01') returning id",
            [$t['tenant'], $f['plant']])->id;
        $shift = fn (string $code, string $from, string $to) => (string) $db->selectOne(
            'insert into shifts (tenant_id, shift_calendar_id, code, name, starts_at, ends_at) values (?, ?, ?, ?, ?, ?) returning id', [$t['tenant'], $calendar, $code, "Shift {$code}", $from, $to])->id;

        // a later calendar version of the plant (not in force yet) with its own S1: never offered for today, never accepted
        $future = (string) $db->selectOne("insert into shift_calendars (tenant_id, plant_id, code, name, effective_from) values (?, ?, 'DAY2-NEXT', 'Next year', '2099-01-01') returning id",
            [$t['tenant'], $f['plant']])->id;
        $db->insert("insert into shifts (tenant_id, shift_calendar_id, code, name, starts_at, ends_at) values (?, ?, 'S1', 'Shift S1 next year', '06:00', '14:00')", [$t['tenant'], $future]);

        return [$shift('S1', '07:00', '15:00'), $shift('S2', '15:00', '23:00')];
    });
    $later = AccessFixtures::run($t, fn (EoraPostgresConnection $db) => (string) $db->selectOne("select s.id from shifts s join shift_calendars c on c.id = s.shift_calendar_id where c.code = 'DAY2-NEXT'")->id);
    $spk = spa($this)->postJson('/api/v1/production/spk', ['plantId' => $f['plant'], 'workCenterId' => $f['wc2']['id'], 'productionDate' => $f['today']])->assertCreated()->json('data');
    $shifts = spa($this)->getJson("/api/v1/production/spk/{$spk['id']}/options")->assertOk()->json('data.shifts');
    expect(array_column($shifts, 'id'))->toBe([$s1, $s2]);
    spa($this)->putJson("/api/v1/production/spk/{$spk['id']}", ['lines' => [spkLine($f, ['shiftId' => $later])]], ['If-Match' => '"'.$spk['version'].'"'])
        ->assertStatus(422)->assertJsonValidationErrors(['lines.0.shiftId']);
    expect(fn () => asApp($t, $f['ppc']['id'], fn (EoraPostgresConnection $db) => $db->insert(
        'insert into spk_lines (tenant_id, spk_id, line_no, work_order_id, routing_operation_id, machine_id, shift_id, planned_qty) values (?, ?, 1, ?, ?, ?, ?, 10)',
        [$t['tenant'], $spk['id'], $f['wo']['id'], $f['weldOp'], $f['m2']['id'], $later])))->toThrow(QueryException::class, 'calendar in force');
    // 8 h + 5 min in shift 1 and 1 h + 5 min in shift 2: 9.167 h of 14 h for the day, yet shift 1 is over its 7 h
    $spk = spa($this)->putJson("/api/v1/production/spk/{$spk['id']}", ['lines' => [
        spkLine($f, ['plannedQty' => '2400', 'shiftId' => $s1]),
        spkLine($f, ['plannedQty' => '300', 'shiftId' => $s2, 'priority' => 2]),
    ]], ['If-Match' => '"'.$spk['version'].'"'])->assertOk()->json('data');
    $load = $spk['machineLoad'][0];
    expect($load)->toMatchArray(['hours' => '9.167', 'capacityHours' => '14', 'balanceHours' => '4.833', 'overloaded' => true])
        ->and(collect($load['shifts'])->keyBy('shiftCode')->map(fn ($s) => [$s['hours'], $s['overloaded']])->all())
        ->toBe(['S1' => ['8.083', true], 'S2' => ['1.083', false]]);
})->group('integration', 'production');
