<?php

declare(strict_types=1);

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
