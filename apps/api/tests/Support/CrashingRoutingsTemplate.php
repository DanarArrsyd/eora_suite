<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Modules\Planning\Import\RoutingsTemplate;
use App\Platform\Access\Authorizer;
use App\Platform\Audit\AuditRecorder;
use App\Platform\Database\EoraPostgresConnection;
use App\Platform\Import\GroupedTemplate;
use RuntimeException;

/**
 * The Routings template with a simulated worker death after it wrote everything but before the transaction commits
 * (`config('eora.test.crash_routings')`): the fenced transaction rolls back, so nothing may persist (B2-AT-61, 73).
 */
final class CrashingRoutingsTemplate implements GroupedTemplate
{
    private readonly RoutingsTemplate $routings;

    public function __construct(AuditRecorder $audit, Authorizer $authorizer)
    {
        $this->routings = new RoutingsTemplate($audit, $authorizer);
    }

    public function code(): string
    {
        return 'crash_routings';
    }

    public function version(): int
    {
        return 1;
    }

    public function title(): string
    {
        return 'Routings (crash test)';
    }

    public function mode(): string
    {
        return self::ALL_OR_NOTHING;
    }

    public function targetPermission(): string
    {
        return $this->routings->targetPermission();
    }

    public function columns(): array
    {
        return $this->routings->columns();
    }

    public function validate(EoraPostgresConnection $db, array $row, int $rowNo, array &$seen): array
    {
        return $this->routings->validate($db, $row, $rowNo, $seen);
    }

    public function finalize(EoraPostgresConnection $db, array $rows): array
    {
        return $this->routings->finalize($db, $rows);
    }

    public function commit(EoraPostgresConnection $db, array $row): string
    {
        return $this->routings->commit($db, $row);
    }

    public function commitAll(EoraPostgresConnection $db, array $rows): array
    {
        $ids = $this->routings->commitAll($db, $rows);
        if (config('eora.test.crash_routings') === true) {
            throw new RuntimeException('worker killed');
        }

        return $ids;
    }
}
