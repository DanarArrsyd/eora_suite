<?php

declare(strict_types=1);

namespace App\Platform\Export;

use RuntimeException;

/** A newer attempt claimed the export job (its generation moved on): this attempt writes nothing. */
final class LostClaim extends RuntimeException {}
