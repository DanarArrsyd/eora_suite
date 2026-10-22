<?php

declare(strict_types=1);

namespace App\Platform\Import;

use RuntimeException;

/**
 * Thrown by `commitAll` when a group no longer fits once it holds its lock (e.g. another job opened a version of the same
 * routing meanwhile): the job's transaction rolls back and the job fails as COMMIT_REFUSED with this message.
 */
final class GroupRefused extends RuntimeException {}
