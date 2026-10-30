<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Contracts;

use InvalidArgumentException;

/**
 * One ledger leg of a posting (doc 04 §Inventory ledger posting specification): a signed quantity in the item's base
 * unit at one location, lot and status. The quantity is a decimal string (never a float, BR-INV-10). A leg in another
 * unit carries the entered quantity, its unit and the conversion version used (M3-D5).
 */
final readonly class PostingLeg
{
    public function __construct(
        public string $movementCode,
        public string $locationId,
        public string $itemId,
        public ?string $lotId,
        public string $stockStatus,
        public string $qty,
        public ?string $enteredQty = null,
        public ?string $enteredUomId = null,
        public ?string $uomConversionId = null,
        public ?string $reversesMovementId = null,
    ) {
        if (! is_numeric($qty) || preg_match('/^-?\d{1,14}(\.\d{1,6})?$/', $qty) !== 1 || bccomp($qty, '0', 6) === 0) {
            throw new InvalidArgumentException('A leg quantity is a non-zero decimal with at most 6 decimals.');
        }
    }
}
