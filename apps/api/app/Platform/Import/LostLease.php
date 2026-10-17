<?php

declare(strict_types=1);

namespace App\Platform\Import;

use RuntimeException;

/** The worker no longer owns the job (generation moved on, lease expired, or status changed): stop without writing. */
final class LostLease extends RuntimeException {}
