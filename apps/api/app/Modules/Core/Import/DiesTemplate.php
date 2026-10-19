<?php

declare(strict_types=1);

namespace App\Modules\Core\Import;

use App\Modules\Core\Queries\DiesQuery;
use App\Platform\Database\EoraPostgresConnection;
use App\Platform\Import\ImportColumn;
use App\Platform\Import\MasterTemplate;
use App\Platform\Import\RowRules;

/** Dies, jigs and fixtures of a plant (doc 29 §1.6, doc 26 §2.1); codes unique across the tenant. */
final class DiesTemplate extends MasterTemplate
{
    public function code(): string
    {
        return 'dies';
    }

    public function title(): string
    {
        return 'Dies and tooling';
    }

    public function targetPermission(): string
    {
        return 'master.org.create';
    }

    public function columns(): array
    {
        return [
            new ImportColumn('Plant code', true, 'Code of an existing plant in your roles.'),
            new ImportColumn('Code', true, '1–20 characters A-Z, 0-9, "_" or "-"; unique in the company.'),
            new ImportColumn('Name', true, 'At most 200 characters.'),
            new ImportColumn('Die type', true, 'DIE, JIG or FIXTURE.'),
            new ImportColumn('Cavities', false, 'Whole number 1–1000; empty = 1.'),
            new ImportColumn('Stroke life', false, 'Whole number above 0; empty = not tracked.'),
            new ImportColumn('Current strokes', false, 'Whole number, 0 or more; empty = 0.'),
        ];
    }

    public function validate(EoraPostgresConnection $db, array $row, int $rowNo, array &$seen): array
    {
        $code = strtoupper($row['Code']);
        $errors = RowRules::collect([
            RowRules::required($code, 'Code'),
            $code !== '' && preg_match('/^[A-Z0-9][A-Z0-9_-]{0,19}$/', $code) !== 1 ? 'Code: use 1–20 characters A-Z, 0-9, "_" or "-".' : null,
            RowRules::required($row['Name'], 'Name'),
            RowRules::maxLength($row['Name'], 200, 'Name'),
            RowRules::required($row['Die type'], 'Die type'),
            RowRules::oneOf($row['Die type'], DiesQuery::TYPES, 'Die type'),
            self::wholeNumber($row['Cavities'], 1, 1000, 'Cavities'),
            self::wholeNumber($row['Stroke life'], 1, 999_999_999_999, 'Stroke life'),
            self::wholeNumber($row['Current strokes'], 0, 999_999_999_999, 'Current strokes'),
        ]);
        $this->checkPlant($db, $row['Plant code'], $errors, $plant);
        if ($errors === [] && $db->selectOne('select 1 as ok from dies where code = ?', [$code]) !== null) {
            $errors[] = "Die {$code} already exists.";
        }

        return $errors === [] ? RowRules::collect([RowRules::once($seen, 'die', $code, $rowNo, "Die {$code}")]) : $errors;
    }

    public function commit(EoraPostgresConnection $db, array $row): string
    {
        $plant = (string) $this->plant($db, $row['Plant code'])?->id;
        $id = (string) $db->selectOne(
            'insert into dies (tenant_id, plant_id, code, name, die_type, cavities, stroke_life, current_strokes)
             values ((select c.tenant from ctx_api.context() c), ?, ?, ?, ?, ?, ?, ?) returning id',
            [$plant, strtoupper($row['Code']), $row['Name'], strtoupper($row['Die type']), $row['Cavities'] === '' ? 1 : (int) $row['Cavities'],
                $row['Stroke life'] === '' ? null : (int) $row['Stroke life'], $row['Current strokes'] === '' ? 0 : (int) $row['Current strokes']],
        )->id;

        return $this->created($db, 'dies', 'die', $id, ['code' => strtoupper($row['Code'])], $plant);
    }
}
