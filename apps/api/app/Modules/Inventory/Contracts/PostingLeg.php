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
    /** Fields added after the first postings: they enter the request hash by name, only when set (ADR-0014). */
    private const array KEYED = ['measurementMethod', 'measuredQty', 'measurementEvidence', 'reasonCode', 'reasonNote', 'scheduleLineId'];

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
        /** The work order whose material this leg moves (issue, return, their reversals; M4-D3). */
        public ?string $woId = null,
        /** Consumption (ADR-0003): how it was measured, the measured quantity (remaining or consumed) and its evidence. */
        public ?string $measurementMethod = null,
        public ?string $measuredQty = null,
        public ?string $measurementEvidence = null,
        /** Process scrap (BR-OUT-04): its reason code and, for OTHER, the note. */
        public ?string $reasonCode = null,
        public ?string $reasonNote = null,
        /** The schedule line a pick or shipment leg serves (D-115, BR-DEM-09), kept by its reversal. */
        public ?string $scheduleLineId = null,
    ) {
        if (! is_numeric($qty) || preg_match('/^-?\d{1,14}(\.\d{1,6})?$/', $qty) !== 1 || bccomp($qty, '0', 6) === 0) {
            throw new InvalidArgumentException('A leg quantity is a non-zero decimal with at most 6 decimals.');
        }
    }

    /**
     * The leg as it enters a posting's request hash. A leg without a work order keeps the shape it had before `woId`
     * existed, so a retry of a posting made then still replays under its key (ADR-0014).
     *
     * @return list<mixed>
     */
    public function hashShape(): array
    {
        $values = get_object_vars($this);
        // fields added later enter the hash only when set, by name, so older legs keep their shape
        $extra = array_filter(array_intersect_key($values, array_flip(self::KEYED)), static fn ($v) => $v !== null);
        $values = array_diff_key($values, array_flip(self::KEYED));
        if ($values['woId'] === null) {
            unset($values['woId']);
        }

        return $extra === [] ? array_values($values) : [...array_values($values), $extra];
    }
}
