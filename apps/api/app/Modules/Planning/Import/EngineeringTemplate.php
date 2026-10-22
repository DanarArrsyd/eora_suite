<?php

declare(strict_types=1);

namespace App\Modules\Planning\Import;

use App\Platform\Database\EoraPostgresConnection;
use App\Platform\Import\GroupedTemplate;
use App\Platform\Import\MasterTemplate;
use LogicException;

/**
 * Routing, BOM and map imports (doc 29 §1.6, doc 26 §5, D-B2-07, D-083): all-or-nothing, rows grouped into one new
 * DRAFT version or revision each; a record with an open version is refused as on screen; approval stays with a second
 * person (`lifecycle_api`), never bypassed by an import.
 */
abstract class EngineeringTemplate extends MasterTemplate implements GroupedTemplate
{
    public function mode(): string
    {
        return self::ALL_OR_NOTHING;
    }

    public function targetPermission(): string
    {
        return 'master.routing.create';
    }

    /** Rows are written per group by commitAll(). */
    public function commit(EoraPostgresConnection $db, array $row): string
    {
        throw new LogicException('All-or-nothing templates commit through commitAll().');
    }

    /**
     * The version-level columns must read the same on every row of a group (the first row decides).
     *
     * @param  array<string, array<string, int>>  $seen
     * @param  array<string, string>  $row
     * @param  list<string>  $columns
     * @param  list<string>  $errors
     */
    protected function sameAsGroup(array &$seen, string $group, int $rowNo, array $row, array $columns, string $what, array &$errors): void
    {
        $fingerprint = implode("\x1f", array_map(static fn (string $c) => $row[$c], $columns));
        $first = $seen['group-row'][$group] ?? null;
        if ($first === null) {
            $seen['group-row'][$group] = $rowNo;
            $seen['group-key'][$group.':'.md5($fingerprint)] = $rowNo;

            return;
        }
        if (! isset($seen['group-key'][$group.':'.md5($fingerprint)])) {
            $errors[] = "{$what}: ".implode(', ', $columns)." must be the same as on row {$first}.";
        }
    }

    /**
     * @param  array<int, array<string, string>>  $rows
     * @param  callable(array<string, string>): string  $key
     * @return array<string, array<int, array<string, string>>> group key => row number => row, in file order
     */
    protected static function groups(array $rows, callable $key): array
    {
        $groups = [];
        foreach ($rows as $rowNo => $row) {
            $groups[$key($row)][$rowNo] = $row;
        }

        return $groups;
    }

    /** "98.5" (percent, ≤ 3 decimals) → "0.98500"; '' → null. */
    protected static function fraction(string $percent): ?string
    {
        return $percent === '' ? null : bcdiv($percent, '100', 5);
    }
}
