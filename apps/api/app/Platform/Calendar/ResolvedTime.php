<?php

declare(strict_types=1);

namespace App\Platform\Calendar;

/**
 * The time concepts of one event instant at one plant (doc 04 §Time model, ADR-0012, D-044). Dates are Y-m-d strings
 * in the plant calendar's timezone; the ISO week key is YYYY-Www of the production date.
 */
final readonly class ResolvedTime
{
    public function __construct(
        public string $timezone,
        public string $calendarDate,
        public string $productionDate,
        public string $isoWeek,
        public ?string $shiftId,
        public ?string $shiftCode,
        public ?string $shiftDate,
    ) {}
}
