<?php

use App\Modules\Planning\Mps\MpsEngine;
use App\Modules\Planning\Mps\WorkingDays;

/*
 * M5 step 3 (doc 23 §4–§7, D-046, D-123): the MPS netting without a database. 2026-10-05 is the Monday of ISO week 41.
 */

function mpsItem(array $overrides = []): array
{
    return [...['onHand' => '0', 'outOfScope' => '0', 'safetyStock' => null, 'leadTimeDays' => null, 'demands' => [], 'supplies' => []], ...$overrides];
}

function firm(string $key, string $date, string $qty): array
{
    return ['key' => $key, 'kind' => 'FIRM', 'date' => $date, 'qty' => $qty];
}

function wo(string $key, string $date, string $qty): array
{
    return ['key' => $key, 'kind' => 'WORK_ORDER', 'date' => $date, 'qty' => $qty];
}

/** @return array<string, string> week => planned quantity (whole numbers) */
function planned(array $plan): array
{
    return array_column(array_map(static fn (array $o) => [$o['weekStart'], rtrim(rtrim($o['qty'], '0'), '.')], $plan['plannedOrders']), 1, 0);
}

it('#65 F-1 regression: PART-A of doc 23 §6 needs 780 / 300 / 250 = 1,330 pcs, never the per-bucket 650', function () {
    $plan = (new MpsEngine(new WorkingDays))->plan(mpsItem([
        'onHand' => '240',
        'demands' => [
            firm('W41-firm', '2026-10-05', '150'),
            ['key' => 'W41-fc', 'kind' => 'FORECAST', 'date' => '2026-10-05', 'qty' => '50'],
            firm('DSL-2', '2026-10-13', '120'),
            firm('W42-rest', '2026-10-13', '1080'),
            firm('W43', '2026-10-19', '300'),
            firm('W44', '2026-10-26', '250'),
        ],
        // WO-2214: 2,000 − good 1,620 = 380 remaining, due Monday of W42
        'supplies' => [wo('WO-2214', '2026-10-12', '380')],
    ]), '2026-10-05', 12, '2026-10-05');

    expect(planned($plan))->toBe(['2026-10-12' => '780', '2026-10-19' => '300', '2026-10-26' => '250'])
        ->and(array_sum(array_map('floatval', planned($plan))))->toBe(1330.0)
        ->and(array_map(static fn (array $b) => (float) $b['closing'], array_slice($plan['buckets'], 0, 4)))->toBe([40.0, 0.0, 0.0, 0.0])
        // daily protection (F-5): the 780 is due on DSL-2's Tuesday, not at the end of the week
        ->and($plan['plannedOrders'][0]['dueDate'])->toBe('2026-10-13');
});

it('#51 uses the opening stock once: 240 on hand, 200 then 300 needs 0 then 260', function () {
    $plan = (new MpsEngine(new WorkingDays))->plan(mpsItem([
        'onHand' => '240',
        'demands' => [firm('a', '2026-10-06', '200'), firm('b', '2026-10-13', '300')],
    ]), '2026-10-05', 12, '2026-10-05');

    expect(planned($plan))->toBe(['2026-10-12' => '260']);
});

it('#53 / #67 subtract allocations outside the horizon and safety stock from the opening', function () {
    $engine = new MpsEngine(new WorkingDays);
    $base = ['onHand' => '240', 'outOfScope' => '100', 'demands' => [firm('a', '2026-10-06', '200')]];

    expect($engine->plan(mpsItem($base), '2026-10-05', 12, '2026-10-05')['opening'])->toBe('140.000000')
        ->and(planned($engine->plan(mpsItem($base), '2026-10-05', 12, '2026-10-05')))->toBe(['2026-10-05' => '60'])
        ->and(planned($engine->plan(mpsItem([...$base, 'safetyStock' => '50']), '2026-10-05', 12, '2026-10-05')))->toBe(['2026-10-05' => '110']);
});

it('#66 a receipt after the due date does not cover it; the order starts the lead time in working days (Mon–Sat) before', function () {
    $item = mpsItem([
        'leadTimeDays' => 2,
        'demands' => [firm('tue', '2026-10-13', '100')],
        'supplies' => [wo('thu', '2026-10-15', '100')],
    ]);
    $plan = (new MpsEngine(new WorkingDays))->plan($item, '2026-10-05', 12, '2026-10-05');

    expect(planned($plan))->toBe(['2026-10-12' => '100'])
        ->and($plan['plannedOrders'][0]['dueDate'])->toBe('2026-10-13')
        // Tue − 2 working days: Mon 12, Sat 10 (Saturday is a working day, D-123)
        ->and($plan['plannedOrders'][0]['startDate'])->toBe('2026-10-10')
        ->and((float) $plan['buckets'][1]['closing'])->toBe(100.0);

    // a holiday on Monday 12 moves the start to Friday 9
    $holiday = (new MpsEngine(new WorkingDays(['2026-10-12' => 'HOLIDAY'])))->plan($item, '2026-10-05', 12, '2026-10-05');
    expect($holiday['plannedOrders'][0]['startDate'])->toBe('2026-10-09');
});

it('#58 dates the planned order on the earliest uncovered day and pegs each shortage to its demand', function () {
    $plan = (new MpsEngine(new WorkingDays))->plan(mpsItem([
        'onHand' => '50',
        'demands' => [firm('mon', '2026-10-12', '40'), firm('wed', '2026-10-14', '30'), firm('fri', '2026-10-16', '20')],
    ]), '2026-10-05', 12, '2026-10-05');

    expect($plan['plannedOrders'])->toHaveCount(1)
        ->and($plan['plannedOrders'][0]['dueDate'])->toBe('2026-10-14')
        ->and(array_map(static fn (array $p) => [$p['demand'], (float) $p['qty']], $plan['plannedOrders'][0]['pegs']))->toBe([['wed', 20.0], ['fri', 20.0]]);
});

it('puts past due demand on today and leaves demand beyond the horizon out', function () {
    $plan = (new MpsEngine(new WorkingDays))->plan(mpsItem([
        'demands' => [firm('late', '2026-10-01', '10'), firm('far', '2027-01-04', '99')],
    ]), '2026-10-05', 12, '2026-10-07');

    expect(array_column($plan['demands'], 'date', 'key'))->toBe(['late' => '2026-10-07'])
        ->and($plan['plannedOrders'][0]['dueDate'])->toBe('2026-10-07');
});

it('restores safety stock on the first day when stock is below it (peg without demand)', function () {
    $plan = (new MpsEngine(new WorkingDays))->plan(mpsItem(['onHand' => '20', 'safetyStock' => '50']), '2026-10-05', 12, '2026-10-06');

    expect(planned($plan))->toBe(['2026-10-05' => '30'])
        ->and($plan['plannedOrders'][0]['pegs'])->toBe([['demand' => null, 'qty' => '30.000000']]);
});

it('counts working days Monday to Saturday with calendar exceptions', function () {
    $days = new WorkingDays(['2026-10-11' => 'WORKING', '2026-10-07' => 'NON_WORKING']);

    expect($days->count('2026-10-05', '2026-10-11'))->toBe(6)
        ->and((new WorkingDays)->count('2026-10-05', '2026-10-11'))->toBe(6)
        ->and((new WorkingDays)->isWorking('2026-10-10'))->toBeTrue()
        ->and((new WorkingDays)->isWorking('2026-10-11'))->toBeFalse();
});
