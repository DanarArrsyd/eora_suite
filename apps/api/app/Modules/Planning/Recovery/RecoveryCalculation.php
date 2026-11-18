<?php

declare(strict_types=1);

namespace App\Modules\Planning\Recovery;

use App\Modules\Planning\Mps\MpsItemInput;

/** DAILY_RECOVERY_V1. Quantities remain decimal strings until the PCS batch is rounded. */
final class RecoveryCalculation
{
    /** @return array{netQty:numeric-string,proposedQty:?numeric-string,planQty:numeric-string,positionQty:numeric-string} */
    public static function calculate(string $demand, string $supply, string $buffer, string $minimum, ?string $batch, string $plan): array
    {
        [$demand,$supply,$buffer,$minimum,$plan] = array_map(MpsItemInput::num(...), [$demand, $supply, $buffer, $minimum, $plan]);
        $batch = $batch === null ? null : MpsItemInput::num($batch);
        $net = bcsub(bcadd($demand, $buffer, 6), $supply, 6);
        $net = bccomp($net, '0', 6) > 0 ? $net : '0';
        $proposed = null;
        if (bccomp($net, '0', 6) === 0) {
            $proposed = '0';
        } elseif ($batch !== null && bccomp($batch, '0', 6) > 0) {
            $required = bccomp($net, $minimum, 6) > 0 ? $net : $minimum;
            $runs = bcdiv($required, $batch, 0);
            if (bccomp(bcmul($runs, $batch, 6), $required, 6) < 0) {
                $runs = bcadd($runs, '1', 0);
            }
            $proposed = bcmul($runs, $batch, 0);
        }

        return ['netQty' => self::plain($net), 'proposedQty' => $proposed, 'planQty' => $plan,
            'positionQty' => self::plain(bcsub(bcadd($supply, $plan, 6), bcadd($demand, $buffer, 6), 6))];
    }

    /** @return numeric-string */
    public static function plain(string $number): string
    {
        return MpsItemInput::num(str_contains($number, '.') ? rtrim(rtrim($number, '0'), '.') : $number);
    }
}
