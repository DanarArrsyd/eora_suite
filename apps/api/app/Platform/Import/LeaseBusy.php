<?php

declare(strict_types=1);

namespace App\Platform\Import;

use RuntimeException;

/**
 * Another worker's lease on the job is still live: the queue message must come back after it expires (a crashed worker's
 * job is then taken over), not be acknowledged as done.
 */
final class LeaseBusy extends RuntimeException
{
    public function __construct(public readonly int $seconds)
    {
        parent::__construct("The import job is leased for another {$seconds} s.");
    }
}
