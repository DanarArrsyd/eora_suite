<?php

declare(strict_types=1);

namespace App\Platform\Import;

/** One template column: the header text in the file, whether a value is required, and what to enter. */
final readonly class ImportColumn
{
    public function __construct(
        public string $header,
        public bool $required,
        public string $hint,
    ) {}
}
