<?php

declare(strict_types=1);

namespace App\Modules\Planning\Mps;

use DateTimeImmutable;

/**
 * The MPS netting of one plant (doc 23 §4–§6, D-046, D-123, BR-PLN-05), pure: no database, so the F-1 regression
 * (#65) and the daily rules are unit tested on their own.
 *
 * Per FG item, day by day over the horizon (ISO weeks from `horizonStart`):
 *   PAB(start) = on hand − allocations outside the horizon − safety stock (NULL = NOT CONFIGURED = 0)
 *   each day: + projected supply of that day (QC released, remaining WO output), then − each demand of that day
 *   (firm before forecast); a demand the PAB does not cover is a shortage: it is pegged and the PAB set back to 0.
 * A receipt later than a due date therefore never covers it (G-MPS-4). Lot-for-lot per week: the planned order of a
 * week is the sum of its shortages, due on its first shortage day (the earliest uncovered daily due date) and started
 * the item's production lead time in working days before (NULL = not configured = 0). Opening FG is used once (F-1),
 * allocations inside the horizon stay covered by their own demand (F-2), Before Check may be dated provisional supply (D-134).
 * Quantities are decimal strings (scale 6, bcmath).
 */
final readonly class MpsEngine
{
    private const int SCALE = 6;

    public function __construct(private WorkingDays $days) {}

    /**
     * @param  array{onHand: numeric-string, outOfScope: numeric-string, safetyStock: ?numeric-string, leadTimeDays: ?int,
     *               demands: list<array{key: string, kind: 'FIRM'|'FORECAST', date: string, qty: numeric-string}>,
     *               supplies: list<array{key: string, kind: string, date: string, qty: numeric-string}>}  $item
     * @return array{opening: numeric-string,
     *               demands: list<array{key: string, kind: string, date: string, originalDate: string, qty: numeric-string}>,
     *               supplies: list<array{key: string, kind: string, date: string, originalDate: string, qty: numeric-string}>,
     *               buckets: list<array{weekStart: string, opening: numeric-string, firm: numeric-string, forecast: numeric-string, receipts: numeric-string, planned: numeric-string, closing: numeric-string}>,
     *               plannedOrders: list<array{weekStart: string, qty: numeric-string, dueDate: string, startDate: string, pegs: list<array{demand: ?string, qty: numeric-string}>}>}
     */
    public function plan(array $item, string $horizonStart, int $weeks, string $today, ?int $decimals = null): array
    {
        $end = (new DateTimeImmutable($horizonStart))->modify('+'.(7 * $weeks - 1).' days')->format('Y-m-d');
        $demands = self::inside($item['demands'], $today, $end);
        usort($demands, static fn (array $a, array $b) => [$a['date'], $a['kind'] === 'FIRM' ? 0 : 1, $a['key']] <=> [$b['date'], $b['kind'] === 'FIRM' ? 0 : 1, $b['key']]);
        $supplies = self::inside($item['supplies'], $today, $end);
        usort($supplies, static fn (array $a, array $b) => [$a['date'], $a['key']] <=> [$b['date'], $b['key']]);

        $opening = bcsub(bcsub($item['onHand'], $item['outOfScope'], self::SCALE), $item['safetyStock'] ?? '0', self::SCALE);
        $pab = $opening;
        $buckets = [];
        $orders = [];
        $di = 0;
        $si = 0;
        for ($w = 0; $w < $weeks; $w++) {
            $monday = (new DateTimeImmutable($horizonStart))->modify("+{$w} weeks");
            $bucket = ['weekStart' => $monday->format('Y-m-d'), 'opening' => $pab, 'firm' => '0', 'forecast' => '0', 'receipts' => '0', 'planned' => '0', 'closing' => '0'];
            $order = null;
            for ($d = 0; $d < 7; $d++) {
                $day = $monday->modify("+{$d} days")->format('Y-m-d');
                for (; $si < count($supplies) && $supplies[$si]['date'] === $day; $si++) {
                    $pab = bcadd($pab, $supplies[$si]['qty'], self::SCALE);
                    $bucket['receipts'] = bcadd($bucket['receipts'], $supplies[$si]['qty'], self::SCALE);
                }
                // a negative opening (safety stock above stock) not covered by the day's receipts is short on the plan's first day
                if ($day === $today && bccomp($pab, '0', self::SCALE) < 0) {
                    [$order, $pab] = $this->cover($order, $day, $pab, null, $decimals);
                }
                for (; $di < count($demands) && $demands[$di]['date'] === $day; $di++) {
                    $dm = $demands[$di];
                    $pab = bcsub($pab, $dm['qty'], self::SCALE);
                    $field = $dm['kind'] === 'FIRM' ? 'firm' : 'forecast';
                    $bucket[$field] = bcadd($bucket[$field], $dm['qty'], self::SCALE);
                    if (bccomp($pab, '0', self::SCALE) < 0) {
                        [$order, $pab] = $this->cover($order, $day, $pab, $dm['key'], $decimals);
                    }
                }
            }
            if ($order !== null) {
                $bucket['planned'] = $order['qty'];
                $order['weekStart'] = $bucket['weekStart'];
                $order['startDate'] = $this->days->back($order['dueDate'], $item['leadTimeDays'] ?? 0);
                $orders[] = $order;
            }
            $bucket['closing'] = $pab;
            $buckets[] = $bucket;
        }

        return ['opening' => $opening, 'demands' => $demands, 'supplies' => $supplies, 'buckets' => $buckets, 'plannedOrders' => $orders];
    }

    /**
     * Past dates are today's (past due); dates beyond the horizon and empty quantities are not this plan's.
     *
     * @param  list<array{key: string, kind: string, date: string, qty: numeric-string}>  $rows
     * @return list<array{key: string, kind: string, date: string, originalDate: string, qty: numeric-string}>
     */
    private static function inside(array $rows, string $today, string $end): array
    {
        $out = [];
        foreach ($rows as $r) {
            $date = max($r['date'], $today);
            if ($date <= $end && bccomp($r['qty'], '0', self::SCALE) > 0) {
                $out[] = ['key' => $r['key'], 'kind' => $r['kind'], 'date' => $date, 'originalDate' => $r['date'], 'qty' => $r['qty']];
            }
        }

        return $out;
    }

    /**
     * Covers a negative balance: the shortage is pegged; the order grows by it, rounded up to $decimals when given (a work
     * order counts in its unit's decimals), and what the rounding adds stays in the balance for the next demand.
     *
     * @param  ?array{weekStart: string, qty: numeric-string, dueDate: string, startDate: string, pegs: list<array{demand: ?string, qty: numeric-string}>}  $order
     * @param  numeric-string  $pab
     * @return array{0: array{weekStart: string, qty: numeric-string, dueDate: string, startDate: string, pegs: list<array{demand: ?string, qty: numeric-string}>}, 1: numeric-string}
     */
    private function cover(?array $order, string $day, string $pab, ?string $demand, ?int $decimals): array
    {
        $short = bcmul($pab, '-1', self::SCALE);
        $make = $short;
        if ($decimals !== null) {
            $down = bcadd($short, '0', $decimals);
            $make = bccomp($down, $short, self::SCALE) < 0 ? bcadd($down, bcpow('10', (string) -$decimals, $decimals), self::SCALE) : bcadd($down, '0', self::SCALE);
        }
        $order = $this->short($order, $day, $short, $demand);
        $order['qty'] = bcadd(bcsub($order['qty'], $short, self::SCALE), $make, self::SCALE);

        return [$order, bcsub($make, $short, self::SCALE)];
    }

    /**
     * @param  ?array{weekStart: string, qty: numeric-string, dueDate: string, startDate: string, pegs: list<array{demand: ?string, qty: numeric-string}>}  $order
     * @param  numeric-string  $qty
     * @return array{weekStart: string, qty: numeric-string, dueDate: string, startDate: string, pegs: list<array{demand: ?string, qty: numeric-string}>}
     */
    private function short(?array $order, string $day, string $qty, ?string $demand): array
    {
        $order ??= ['weekStart' => '', 'qty' => '0', 'dueDate' => $day, 'startDate' => $day, 'pegs' => []];
        $order['qty'] = bcadd($order['qty'], $qty, self::SCALE);
        $order['pegs'][] = ['demand' => $demand, 'qty' => $qty];

        return $order;
    }
}
