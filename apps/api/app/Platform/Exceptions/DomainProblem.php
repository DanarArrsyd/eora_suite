<?php

declare(strict_types=1);

namespace App\Platform\Exceptions;

use InvalidArgumentException;
use RuntimeException;

/**
 * A business-rule failure with one of the error codes catalogued in doc 24 §5 (e.g. 409 INVALID_STATE_TRANSITION).
 * Rendered as problem+json with that code; the detail must be safe to show to the caller.
 */
final class DomainProblem extends RuntimeException
{
    public function __construct(public readonly int $status, public readonly string $errorCode, public readonly string $title, public readonly ?string $detail = null)
    {
        if ($status < 400 || $status > 499 || preg_match('/^[A-Z][A-Z0-9_]{2,63}$/', $errorCode) !== 1) {
            throw new InvalidArgumentException('Domain problems are 4xx with an UPPER_SNAKE error code.');
        }

        parent::__construct($title);
    }
}
