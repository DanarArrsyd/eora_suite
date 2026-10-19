<?php

declare(strict_types=1);

namespace App\Modules\Planning\Import;

use App\Modules\Planning\Queries\ProcessTypesQuery;
use App\Platform\Database\EoraPostgresConnection;
use App\Platform\Import\ImportColumn;
use App\Platform\Import\MasterTemplate;
use App\Platform\Import\RowRules;

/** Process types (doc 29 §1.6): the tenant's catalogue of operation kinds, new codes only. */
final class ProcessTypesTemplate extends MasterTemplate
{
    public function code(): string
    {
        return 'process_types';
    }

    public function title(): string
    {
        return 'Process types';
    }

    public function targetPermission(): string
    {
        return 'master.routing.create';
    }

    public function columns(): array
    {
        return [
            new ImportColumn('Code', true, 'A-Z, 0-9, "_" or "-", starting with a letter, e.g. BLANK, BEND, INSP.'),
            new ImportColumn('Name', true, 'At most 100 characters.'),
            new ImportColumn('Category', true, 'TRANSFORMATION, INSPECTION or HANDLING.'),
            new ImportColumn('Usual work center type', false, 'e.g. PRESS; suggests the work center when an operation is added.'),
        ];
    }

    public function validate(EoraPostgresConnection $db, array $row, int $rowNo, array &$seen): array
    {
        $code = strtoupper($row['Code']);
        $wcType = strtoupper($row['Usual work center type']);
        $errors = RowRules::collect([
            RowRules::required($code, 'Code'),
            $code !== '' && preg_match('/^[A-Z][A-Z0-9_-]{0,31}$/', $code) !== 1 ? 'Code: use A-Z, 0-9, "_" or "-", starting with a letter, at most 32.' : null,
            RowRules::required($row['Name'], 'Name'),
            RowRules::maxLength($row['Name'], 100, 'Name'),
            RowRules::required($row['Category'], 'Category'),
            RowRules::oneOf($row['Category'], ProcessTypesQuery::CATEGORIES, 'Category'),
            $wcType !== '' && preg_match('/^[A-Z][A-Z0-9_]{1,31}$/', $wcType) !== 1 ? 'Usual work center type: use A-Z, 0-9 or "_", starting with a letter, 2–32 characters.' : null,
        ]);
        if ($errors === [] && $db->selectOne('select 1 as ok from process_types where code = ?', [$code]) !== null) {
            $errors[] = "Process type {$code} already exists.";
        }

        return $errors === [] ? RowRules::collect([RowRules::once($seen, 'process_type', $code, $rowNo, "Process type {$code}")]) : $errors;
    }

    public function commit(EoraPostgresConnection $db, array $row): string
    {
        $code = strtoupper($row['Code']);
        $id = (string) $db->selectOne(
            'insert into process_types (tenant_id, code, name, category, default_work_center_type) values ((select c.tenant from ctx_api.context() c), ?, ?, ?, ?) returning id',
            [$code, $row['Name'], strtoupper($row['Category']), RowRules::text(strtoupper($row['Usual work center type']))],
        )->id;

        return $this->created($db, 'process_types', 'process_type', $id, ['code' => $code]);
    }
}
