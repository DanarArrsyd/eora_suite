<?php

declare(strict_types=1);

namespace App\Modules\Planning\Recovery;

use App\Modules\Planning\Mps\MpsItemInput;

/**
 * Allocates one customer part's need from the item's supply pools, kept per owner (D-141, doc 39). The customer's own
 * stock goes first (FG, QC accepted, Before Check), then company stock and unattributed WO output (D-1, D-2: parts are
 * visited earliest due date first, then customer code). What safety stock adds above the part's own buffer is held in
 * company stock only (D-5). Pools are `owner => stage => qty`, owner `COMPANY` or a customer id.
 */
final class RecoveryAllocation
{
    private const array STAGES = ['fg', 'accepted', 'beforeCheck'];

    /**
     * @param  array<string,array<string,numeric-string>>  $pool  the item's pools, reduced by what is taken
     * @param  numeric-string  $need  required + the part's buffer − protected − bound WO output (negative: nothing to take)
     * @param  numeric-string  $floorExtra  safety stock above the part's buffer
     * @param  array<string,numeric-string>  $allocated  per stage, added to
     * @return array<string,numeric-string>
     */
    public static function allocate(array &$pool, string $customer, string $need, string $floorExtra, array $allocated): array
    {
        $own = array_map(static fn (string $stage) => [$customer, $stage], self::STAGES);
        $company = [...array_map(static fn (string $stage) => [MaterialCapacity::COMPANY, $stage], self::STAGES), [MaterialCapacity::COMPANY, 'wo']];
        self::take($pool, [...$own, ...$company], $need, $allocated);
        // protected stock and output bound to this customer belong to its delivery: a surplus of it never stands in for
        // the company's safety stock (D-5)
        self::take($pool, $company, $floorExtra, $allocated);

        return $allocated;
    }

    /**
     * @param  array<string,array<string,numeric-string>>  $pool
     * @param  list<array{0:string,1:string}>  $sequence
     * @param  numeric-string  $left
     * @param  array<string,numeric-string>  $allocated
     * @return numeric-string what is still uncovered
     */
    private static function take(array &$pool, array $sequence, string $left, array &$allocated): string
    {
        foreach ($sequence as [$owner, $stage]) {
            $available = MpsItemInput::num($pool[$owner][$stage] ?? '0');
            $take = bccomp($left, '0', 6) <= 0 ? '0' : (bccomp($available, $left, 6) < 0 ? $available : $left);
            $allocated[$stage] = bcadd(MpsItemInput::num($allocated[$stage] ?? '0'), $take, 6);
            if (isset($pool[$owner])) {
                $pool[$owner][$stage] = bcsub($available, $take, 6);
            }
            $left = bcsub($left, $take, 6);
        }

        return $left;
    }
}
