<?php

declare(strict_types=1);

namespace App\Platform\Import;

use App\Platform\Database\EoraPostgresConnection;

/**
 * An all-or-nothing template (D-B2-07: routings, BOMs, maps): rows form groups (a routing version and its operations, a
 * BOM version and its lines, a map and its lines). Checks that need the whole file run in `finalize`; the commit writes
 * every group in the one fenced transaction of the job — nothing is written unless every row is valid.
 */
interface GroupedTemplate extends ImportTemplate
{
    /**
     * Problems that need every valid row of the file at once (e.g. each BOM line mapped exactly once).
     *
     * @param  array<int, array<string, string>>  $rows  row number => row
     * @return array<int, list<string>> row number => problems
     */
    public function finalize(EoraPostgresConnection $db, array $rows): array;

    /**
     * Writes all rows (inside the job's single fenced transaction) and audits what it created.
     *
     * @param  array<int, array<string, string>>  $rows  row number => row, in file order
     * @return array<int, string> row number => created record id
     */
    public function commitAll(EoraPostgresConnection $db, array $rows): array;
}
