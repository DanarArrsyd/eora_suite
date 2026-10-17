<?php

declare(strict_types=1);

namespace App\Modules\Core\Import;

use App\Modules\Core\Queries\UomsQuery;
use App\Platform\Audit\AuditRecorder;
use App\Platform\Database\EoraPostgresConnection;
use App\Platform\Import\ImportColumn;
use App\Platform\Import\ImportTemplate;
use App\Platform\Import\RowRules;

/** Units of measure (doc 29 §1.6): new units only; an existing code is reported, never changed. */
final class UomsTemplate implements ImportTemplate
{
    public function __construct(private readonly AuditRecorder $audit) {}

    public function code(): string
    {
        return 'uoms';
    }

    public function version(): int
    {
        return 1;
    }

    public function title(): string
    {
        return 'Units of measure';
    }

    public function mode(): string
    {
        return self::VALID_ROWS;
    }

    public function targetPermission(): string
    {
        return 'master.part.create';
    }

    public function columns(): array
    {
        return [
            new ImportColumn('Code', true, 'A-Z, 0-9 or "_", starting with a letter, at most 10, e.g. SHEET. Fixed once created.'),
            new ImportColumn('Name', true, 'At most 100 characters.'),
            new ImportColumn('Unit class', true, 'MASS, COUNT, LENGTH, AREA or VOLUME.'),
            new ImportColumn('Decimals', true, 'Whole number 0–6: decimals allowed in quantities.'),
        ];
    }

    public function validate(EoraPostgresConnection $db, array $row, int $rowNo, array &$seen): array
    {
        $code = strtoupper($row['Code']);
        $errors = RowRules::collect([
            RowRules::required($code, 'Code'),
            $code !== '' && preg_match('/^[A-Z][A-Z0-9_]{0,9}$/', $code) !== 1 ? 'Code: use A-Z, 0-9 or "_", starting with a letter, at most 10.' : null,
            RowRules::required($row['Name'], 'Name'),
            RowRules::maxLength($row['Name'], 100, 'Name'),
            RowRules::required($row['Unit class'], 'Unit class'),
            RowRules::oneOf($row['Unit class'], UomsQuery::CLASSES, 'Unit class'),
            RowRules::required($row['Decimals'], 'Decimals'),
            $row['Decimals'] !== '' && preg_match('/^[0-6]$/', $row['Decimals']) !== 1 ? 'Decimals: use a whole number from 0 to 6.' : null,
        ]);
        if ($errors === [] && $db->selectOne('select 1 as ok from uoms where code = ?', [$code]) !== null) {
            $errors[] = "Unit {$code} already exists.";
        }

        return $errors === [] ? RowRules::collect([RowRules::once($seen, 'uom', $code, $rowNo, "Unit {$code}")]) : $errors;
    }

    public function commit(EoraPostgresConnection $db, array $row): string
    {
        $code = strtoupper($row['Code']);
        $id = (string) $db->selectOne(
            'insert into uoms (tenant_id, code, name, uom_class, decimals) values ((select c.tenant from ctx_api.context() c), ?, ?, ?, ?) returning id',
            [$code, $row['Name'], strtoupper($row['Unit class']), (int) $row['Decimals']],
        )->id;
        $this->audit->record($db, 'uom.created', 'uom', $id, null, (array) $db->selectOne('select * from uoms where id = ?', [$id]), ['code' => $code, 'source' => 'import']);

        return $id;
    }
}
