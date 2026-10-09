<?php

declare(strict_types=1);

namespace App\Platform\Http\Controllers;

use App\Platform\Health\HealthChecker;
use Illuminate\Http\JsonResponse;

/**
 * Liveness and readiness probes (doc 09). Liveness never touches dependencies; readiness checks
 * PostgreSQL and Redis and answers 503 when any dependency fails. Versions are hidden in production.
 */
final class HealthController
{
    /**
     * Liveness: the application process is up.
     *
     * @response array{status: 'ok', service: string, time: string}
     */
    public function live(): JsonResponse
    {
        return new JsonResponse([
            'status' => 'ok',
            'service' => (string) config('app.name'),
            'time' => now()->toIso8601ZuluString(),
        ]);
    }

    /**
     * Readiness: dependencies (PostgreSQL, Redis) are reachable.
     *
     * @response array{status: 'ok'|'degraded', checks: array{database: array{status: string, latencyMs: float}, redis: array{status: string, latencyMs: float}}, time: string}
     */
    public function ready(HealthChecker $checker): JsonResponse
    {
        $checks = ['database' => $checker->database(), 'redis' => $checker->redis()];
        if (app()->isProduction()) {
            $checks = array_map(static fn (array $c): array => array_intersect_key($c, ['status' => true, 'latencyMs' => true]), $checks);
        }
        $ok = collect($checks)->every(static fn (array $c): bool => $c['status'] === 'ok');

        return new JsonResponse([
            'status' => $ok ? 'ok' : 'degraded',
            'checks' => $checks,
            'time' => now()->toIso8601ZuluString(),
        ], $ok ? 200 : 503);
    }
}
