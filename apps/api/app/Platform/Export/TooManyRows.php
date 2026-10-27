<?php

declare(strict_types=1);

namespace App\Platform\Export;

use RuntimeException;

/** The list grew past the export limit between the request and the run (D-092: at most 100,000 rows). */
final class TooManyRows extends RuntimeException {}
