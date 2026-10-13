<?php

use App\Platform\Auth\AuthSession;
use App\Platform\Auth\Http\Middleware\FlushSecurityEvents;
use App\Platform\Auth\Http\Middleware\TenantRequest;
use App\Platform\Exceptions\ProblemDetails;
use App\Platform\Http\Middleware\AssignCorrelationId;
use App\Platform\Security\SecurityEvent;
use App\Platform\Security\SecurityEventRecorder;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Context;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
        apiPrefix: 'api',
    )
    ->withCommands([__DIR__.'/../app/Platform/Auth/Delivery', __DIR__.'/../app/Platform/Provisioning'])
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->prepend(AssignCorrelationId::class);
        // Sanctum stateful SPA (ADR-0006): session cookie + XSRF for requests from the app's own origin
        $middleware->statefulApi();
        $middleware->api(prepend: [FlushSecurityEvents::class]);
        $middleware->alias(['tenant' => TenantRequest::class]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        // Every /api/* error is RFC 9457 problem+json (doc 24 §5); other paths keep Laravel defaults.
        $exceptions->render(function (Throwable $e, Request $request) {
            if ($request->is('api/*')) {
                $response = ProblemDetails::render($e, (bool) config('app.debug'));

                // denied access of a signed-in user becomes a durable security event (doc 25 §8, T-ISO-01)
                if (in_array($response->getStatusCode(), [403, 404], true) && $request->hasSession() && app(AuthSession::class)->userId() !== null) {
                    $correlation = (string) (Context::get('correlation_id') ?? '');
                    app(SecurityEventRecorder::class)->record(new SecurityEvent(
                        action: match ($response->getStatusCode() === 403 ? ($response->getData(true)['code'] ?? null) : 'NOT_FOUND') {
                            'NOT_FOUND' => 'access.not_found',
                            'SCOPE_FORBIDDEN' => 'access.scope_forbidden',
                            'SOD_VIOLATION' => 'access.sod_violation',
                            default => 'access.forbidden',
                        },
                        route: substr((string) ($request->route()?->getName() ?? $request->path()), 0, 200),
                        httpStatus: $response->getStatusCode(),
                        correlationId: preg_match('/^[A-Za-z0-9_-]{8,64}$/', $correlation) === 1 ? $correlation : null,
                    ));
                }

                return $response;
            }

            return null;
        });
    })->create();
