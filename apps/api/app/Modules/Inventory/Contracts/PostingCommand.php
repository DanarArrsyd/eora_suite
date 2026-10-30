<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Contracts;

use InvalidArgumentException;

/**
 * A document to post directly (ADR-0014): its type, plant, legs and the idempotency key of the request. Adjustments,
 * reversals and closed-period corrections are requests decided by a second person (D-060) and never posted this way.
 * `effectiveDate` null = today's production date at the plant; an earlier day is a backdated posting into an open
 * period (BR-PER-01).
 */
final readonly class PostingCommand
{
    /**
     * @param  list<PostingLeg>  $legs
     * @param  list<GenealogyLink>  $genealogy
     */
    public function __construct(
        public string $docType,
        public string $plantId,
        public string $idempotencyKey,
        public array $legs,
        public array $genealogy = [],
        public ?string $effectiveDate = null,
        public ?string $note = null,
        public ?string $sourceType = null,
        public ?string $sourceId = null,
    ) {
        if ($legs === []) {
            throw new InvalidArgumentException('A posting has legs.');
        }
        if (preg_match('/^[A-Za-z0-9._:-]{8,200}$/', $idempotencyKey) !== 1) {
            throw new InvalidArgumentException('An idempotency key is 8–200 characters A-Z, a-z, 0-9, ".", "_", ":" or "-".');
        }
    }

    /** Canonical hash of what the request asks, so a replay under the same key can be told from another request. */
    public function hash(): string
    {
        return hash('sha256', json_encode([$this->docType, $this->plantId, $this->effectiveDate, $this->note, $this->sourceType, $this->sourceId,
            array_map(static fn (PostingLeg $l) => array_values(get_object_vars($l)), $this->legs),
            array_map(static fn (GenealogyLink $g) => array_values(get_object_vars($g)), $this->genealogy)], JSON_THROW_ON_ERROR));
    }
}
