<?php

declare(strict_types=1);

namespace App\Platform\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Context;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;

/**
 * Gives every request a correlation id (accepted from a well-formed X-Request-Id, otherwise generated),
 * shares it with logs through Laravel Context and returns it as X-Correlation-Id (doc 24 §5, doc 09).
 */
final class AssignCorrelationId
{
    public const HEADER_IN = 'X-Request-Id';

    public const HEADER_OUT = 'X-Correlation-Id';

    public function handle(Request $request, Closure $next): Response
    {
        $incoming = (string) $request->headers->get(self::HEADER_IN, '');
        $id = preg_match('/^[A-Za-z0-9-]{8,64}$/', $incoming) === 1 ? $incoming : (string) Str::ulid();

        Context::add('correlation_id', $id);

        $response = $next($request);
        $response->headers->set(self::HEADER_OUT, $id);

        return $response;
    }
}
