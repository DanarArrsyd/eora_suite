<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Modules\Core\Import\ItemsTemplate;
use App\Platform\Audit\AuditRecorder;
use App\Platform\Database\EoraPostgresConnection;
use App\Platform\Import\ImportTemplate;
use RuntimeException;

/**
 * The Items template with a simulated worker death: committing the item coded `config('eora.test.crash_at_code')`
 * throws, which aborts that chunk's transaction exactly like a killed process (B2-AT-62, 69).
 */
final class CrashingItemsTemplate implements ImportTemplate
{
    private readonly ItemsTemplate $items;

    public function __construct(AuditRecorder $audit)
    {
        $this->items = new ItemsTemplate($audit);
    }

    public function code(): string
    {
        return 'crash_items';
    }

    public function version(): int
    {
        return 1;
    }

    public function title(): string
    {
        return 'Items (crash test)';
    }

    public function mode(): string
    {
        return self::VALID_ROWS;
    }

    public function targetPermission(): string
    {
        return $this->items->targetPermission();
    }

    public function columns(): array
    {
        return $this->items->columns();
    }

    public function validate(EoraPostgresConnection $db, array $row, int $rowNo, array &$seen): array
    {
        return $this->items->validate($db, $row, $rowNo, $seen);
    }

    public function commit(EoraPostgresConnection $db, array $row): string
    {
        $at = config('eora.test.crash_at_code');
        if ($at !== null && strtoupper($row['Code']) === $at) {
            throw new RuntimeException('worker killed');
        }

        return $this->items->commit($db, $row);
    }
}
