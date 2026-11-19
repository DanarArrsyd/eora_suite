<?php

declare(strict_types=1);

use App\Modules\Planning\Http\DailyRecoveryController;
use App\Modules\Planning\Recovery\RecoveryCalculation;

it('does not propose another batch when existing projected output covers delivery', function () {
    expect(RecoveryCalculation::calculate('500', '500', '0', '500', '22', '0'))
        ->toMatchArray(['netQty' => '0', 'proposedQty' => '0']);
});

it('rounds a replacement shortage through the minimum and whole material batches', function () {
    expect(RecoveryCalculation::calculate('500', '480', '0', '500', '22', '506'))
        ->toMatchArray(['netQty' => '20', 'proposedQty' => '506', 'positionQty' => '486'])
        ->and(RecoveryCalculation::calculate('1000', '300', '0', '500', '22', '0')['proposedQty'])->toBe('704');
});

it('keeps missing conversion explicit and applies an optional buffer once', function () {
    expect(RecoveryCalculation::calculate('500', '480', '100', '500', null, '0'))
        ->toMatchArray(['netQty' => '120', 'proposedQty' => null]);
});

it('sorts the Daily Recovery list: quantities as decimals, codes and names as text even when they look numeric', function () {
    $row = fn (string $customer, string $part, ?string $proposed, string $net = '0') => ['customerCode' => $customer, 'itemName' => $part, 'proposedQty' => $proposed, 'netQty' => $net];
    $rows = [$row('1E3', '10 Bracket', '506', '12'), $row('200', '9 Bracket', null, '2'), $row('OEM-A', 'Arm', '22', '100')];

    // "1E3" and "200" are customer codes: text order, never BCMath (which rejects the exponent)
    expect(array_column(DailyRecoveryController::sortRows($rows, 'customer'), 'customerCode'))->toBe(['1E3', '200', 'OEM-A'])
        ->and(array_column(DailyRecoveryController::sortRows($rows, '-part'), 'itemName'))->toBe(['Arm', '9 Bracket', '10 Bracket'])
        // decimals by value; an uncalculated proposal comes first
        ->and(array_column(DailyRecoveryController::sortRows($rows, 'netQty'), 'netQty'))->toBe(['2', '12', '100'])
        ->and(array_column(DailyRecoveryController::sortRows($rows, 'proposedQty'), 'proposedQty'))->toBe([null, '22', '506']);
});
