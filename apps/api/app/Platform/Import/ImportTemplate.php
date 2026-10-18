<?php

declare(strict_types=1);

namespace App\Platform\Import;

use App\Platform\Database\EoraPostgresConnection;

/**
 * One versioned Excel import template (doc 29 §1.6, doc 26 §5, ADR-0017). Columns are matched by header (D-081); a row
 * reaches the template as `header key => trimmed text` ('' for an empty cell). Validation runs in the worker against
 * the current master data, again at commit; `commit` writes one row and returns the id of the record it created.
 */
interface ImportTemplate
{
    /** Valid rows commit in chunks; the others are reported (D-B2-07). */
    public const string VALID_ROWS = 'VALID_ROWS';

    /** Nothing commits unless every row is valid, in one fenced transaction (routing, BOM, map). */
    public const string ALL_OR_NOTHING = 'ALL_OR_NOTHING';

    public function code(): string;

    public function version(): int;

    public function title(): string;

    /** VALID_ROWS or ALL_OR_NOTHING. */
    public function mode(): string;

    /** The master-data permission the committing user needs besides `exchange.import.post` (D-081). */
    public function targetPermission(): string;

    /** @return list<ImportColumn> in file order */
    public function columns(): array;

    /**
     * Problems of one row (empty = valid). `$seen` carries what earlier rows of the same file claimed (duplicate keys
     * within the file); the template adds the row's own keys when it is valid.
     *
     * @param  array<string, string>  $row
     * @param  array<string, array<string, int>>  $seen  key kind => key => first row number
     * @return list<string>
     */
    public function validate(EoraPostgresConnection $db, array $row, int $rowNo, array &$seen): array;

    /**
     * Writes one validated row inside the fenced transaction and audits it; returns the created record id.
     *
     * @param  array<string, string>  $row
     */
    public function commit(EoraPostgresConnection $db, array $row): string;
}
