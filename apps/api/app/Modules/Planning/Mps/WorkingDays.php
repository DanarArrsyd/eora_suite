<?php

declare(strict_types=1);

namespace App\Modules\Planning\Mps;

use DateTimeImmutable;

/**
 * Working days of a plant (D-123 (1)): Monday–Saturday, minus the plant calendar's HOLIDAY / NON_WORKING exceptions,
 * plus its WORKING exceptions (a Sunday worked). Dates are `Y-m-d` strings.
 */
final readonly class WorkingDays
{
    /** @param  array<string, string>  $exceptions  date => HOLIDAY | NON_WORKING | WORKING */
    public function __construct(private array $exceptions = []) {}

    public function isWorking(string $date): bool
    {
        $kind = $this->exceptions[$date] ?? null;
        if ($kind !== null) {
            return $kind === 'WORKING';
        }

        return (int) (new DateTimeImmutable($date))->format('N') <= 6;
    }

    /** The date $days working days before $date (0: $date itself). */
    public function back(string $date, int $days): string
    {
        $d = new DateTimeImmutable($date);
        while ($days > 0) {
            $d = $d->modify('-1 day');
            if ($this->isWorking($d->format('Y-m-d'))) {
                $days--;
            }
        }

        return $d->format('Y-m-d');
    }

    /** Working days from $from to $to, both included. */
    public function count(string $from, string $to): int
    {
        $n = 0;
        for ($d = new DateTimeImmutable($from); $d->format('Y-m-d') <= $to; $d = $d->modify('+1 day')) {
            $n += $this->isWorking($d->format('Y-m-d')) ? 1 : 0;
        }

        return $n;
    }

    /** @return array<string, string> */
    public function exceptions(): array
    {
        return $this->exceptions;
    }
}
