<?php

declare(strict_types=1);

namespace App\Platform\Health;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Redis;
use Throwable;

/**
 * Dependency checks for the readiness endpoint. Each check is time-boxed by the driver timeouts
 * (database connect_timeout, redis timeout) and never throws.
 */
class HealthChecker
{
    /** @return array{status: 'ok'|'fail', latencyMs: float, version?: string, error?: string} */
    public function database(): array
    {
        return $this->measure(static function (): array {
            $row = DB::selectOne('select current_setting(\'server_version\') as version');

            return ['version' => (string) ($row->version ?? 'unknown')];
        });
    }

    /** @return array{status: 'ok'|'fail', latencyMs: float, version?: string, error?: string} */
    public function redis(): array
    {
        return $this->measure(static function (): array {
            $info = Redis::connection()->command('info', ['server']);
            $version = is_array($info) ? (string) ($info['Server']['redis_version'] ?? $info['redis_version'] ?? 'unknown') : 'unknown';

            return ['version' => $version];
        });
    }

    /**
     * @param  callable(): array{version: string}  $probe
     * @return array{status: 'ok'|'fail', latencyMs: float, version?: string, error?: string}
     */
    private function measure(callable $probe): array
    {
        $start = hrtime(true);
        try {
            $extra = $probe();

            return ['status' => 'ok', 'latencyMs' => round((hrtime(true) - $start) / 1e6, 2), ...$extra];
        } catch (Throwable $e) {
            return ['status' => 'fail', 'latencyMs' => round((hrtime(true) - $start) / 1e6, 2), 'error' => class_basename($e)];
        }
    }
}
