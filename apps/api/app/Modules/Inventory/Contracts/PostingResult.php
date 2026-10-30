<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Contracts;

/** The posted document; `replayed` = the same request was posted before under its idempotency key. */
final readonly class PostingResult
{
    public function __construct(
        public string $documentId,
        public string $docNo,
        public string $productionDate,
        public bool $replayed = false,
    ) {}

    /** @return array{documentId: string, docNo: string, productionDate: string} */
    public function toArray(): array
    {
        return ['documentId' => $this->documentId, 'docNo' => $this->docNo, 'productionDate' => $this->productionDate];
    }
}
