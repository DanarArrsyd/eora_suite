<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Contracts;

/** A parent → child lot link written with its posting (lot split, production output), quantities in base units. */
final readonly class GenealogyLink
{
    public function __construct(
        public string $fromLotId,
        public string $toLotId,
        public string $qtyFrom,
        public string $uomFrom,
        public string $qtyTo,
        public string $uomTo,
    ) {}
}
