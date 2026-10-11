<?php

declare(strict_types=1);

namespace App\Platform\Auth\Http\Middleware;

use RuntimeException;
use Symfony\Component\HttpFoundation\Response;

/** Carries an error response out of the tenant transaction so the transaction rolls back (doc 25 §6.2). */
final class RollbackWithResponse extends RuntimeException
{
    public function __construct(public readonly Response $response)
    {
        parent::__construct('rollback');
    }
}
