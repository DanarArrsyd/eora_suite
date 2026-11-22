<?php

declare(strict_types=1);

use App\Modules\Planning\Recovery\RecoveryAllocation;

/* D-141 / doc 39: one part's need from per-owner pools (D-1 own first, D-2 company shared once, D-5 safety stock). */

const DPM = 'customer-dpm';
const SJM = 'customer-sjm';
const ZERO = ['fg' => '0', 'beforeCheck' => '0', 'accepted' => '0', 'wo' => '0'];

it('covers a customer from its own stock first, then company stock', function () {
    $pool = [DPM => ['fg' => '30', 'accepted' => '0', 'beforeCheck' => '0'], 'COMPANY' => ['fg' => '50', 'accepted' => '0', 'beforeCheck' => '0', 'wo' => '0']];
    $got = RecoveryAllocation::allocate($pool, DPM, '60', '0', ZERO);
    expect($got['fg'])->toBe('60.000000')->and($pool[DPM]['fg'])->toBe('0.000000')->and($pool['COMPANY']['fg'])->toBe('20.000000');
});

it('never covers a customer from another customer\'s stock', function () {
    $pool = [DPM => ['fg' => '100', 'accepted' => '0', 'beforeCheck' => '0'], 'COMPANY' => ['fg' => '10', 'accepted' => '0', 'beforeCheck' => '0', 'wo' => '0']];
    $got = RecoveryAllocation::allocate($pool, SJM, '40', '0', ZERO);
    expect($got['fg'])->toBe('10.000000')->and($pool[DPM]['fg'])->toBe('100');
});

it('counts company stock once across customers, in the order parts are visited', function () {
    $pool = ['COMPANY' => ['fg' => '50', 'accepted' => '0', 'beforeCheck' => '0', 'wo' => '20']];
    $first = RecoveryAllocation::allocate($pool, DPM, '40', '0', ZERO);
    $second = RecoveryAllocation::allocate($pool, SJM, '40', '0', ZERO);
    expect($first['fg'])->toBe('40.000000')
        ->and($second['fg'])->toBe('10.000000')->and($second['wo'])->toBe('20.000000');
});

it('holds safety stock above the part buffer in company stock only (D-5)', function () {
    // DPM's own 100 pcs cover its 10 pcs demand; the item's safety stock of 30 is not protected by them
    $pool = [DPM => ['fg' => '100', 'accepted' => '0', 'beforeCheck' => '0'], 'COMPANY' => ['fg' => '12', 'accepted' => '0', 'beforeCheck' => '0', 'wo' => '0']];
    $got = RecoveryAllocation::allocate($pool, DPM, '10', '30', ZERO);
    expect($got['fg'])->toBe('22.000000')->and($pool[DPM]['fg'])->toBe('90.000000')->and($pool['COMPANY']['fg'])->toBe('0.000000');
});

it('lets protected surplus count toward safety stock as before', function () {
    $pool = ['COMPANY' => ['fg' => '100', 'accepted' => '0', 'beforeCheck' => '0', 'wo' => '0']];
    // 5 more already protected than required: only 25 of the 30 safety stock is still taken
    $got = RecoveryAllocation::allocate($pool, DPM, '-5', '30', ZERO);
    expect($got['fg'])->toBe('25.000000');
});
