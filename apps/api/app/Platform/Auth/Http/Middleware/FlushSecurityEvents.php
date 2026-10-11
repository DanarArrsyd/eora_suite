<?php

declare(strict_types=1);

namespace App\Platform\Auth\Http\Middleware;

use App\Platform\Auth\AuthSession;
use App\Platform\Security\SecurityEventRecorder;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Writes the request's buffered security events after every inner transaction has finished (doc 25 §8): denials are
 * recorded even when the business transaction rolled back. Outermost API middleware.
 */
final class FlushSecurityEvents
{
    public function __construct(private readonly SecurityEventRecorder $recorder, private readonly AuthSession $auth) {}

    public function handle(Request $request, Closure $next): Response
    {
        try {
            return $next($request);
        } finally {
            $this->recorder->flush($this->auth->context());
        }
    }
}
