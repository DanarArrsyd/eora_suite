<?php

declare(strict_types=1);

namespace App\Modules\Core\Import;

use App\Platform\Database\EoraPostgresConnection;
use App\Platform\Import\ImportColumn;
use App\Platform\Import\MasterTemplate;
use App\Platform\Import\RowRules;

/** Work centers (doc 29 §1.6); each gets its Material at Production location `MAP-<code>` like on screen. */
final class WorkCentersTemplate extends MasterTemplate
{
    public function code(): string
    {
        return 'work_centers';
    }

    public function title(): string
    {
        return 'Work centers';
    }

    public function targetPermission(): string
    {
        return 'master.org.create';
    }

    public function columns(): array
    {
        return [
            new ImportColumn('Plant code', true, 'Code of an existing plant in your roles.'),
            new ImportColumn('Code', true, '1–16 characters A-Z, 0-9, "_" or "-"; unique in the plant.'),
            new ImportColumn('Name', true, 'At most 200 characters.'),
            new ImportColumn('Work center type', true, 'A-Z, 0-9 or "_", starting with a letter, e.g. PRESS, WELD.'),
            new ImportColumn('Available hours per shift', false, 'Above 0, at most 24, 2 decimals; empty = not configured.'),
        ];
    }

    public function validate(EoraPostgresConnection $db, array $row, int $rowNo, array &$seen): array
    {
        $code = strtoupper($row['Code']);
        $type = strtoupper($row['Work center type']);
        $hours = $row['Available hours per shift'];
        $errors = RowRules::collect([
            RowRules::required($code, 'Code'),
            $code !== '' && preg_match('/^[A-Z0-9][A-Z0-9_-]{0,15}$/', $code) !== 1 ? 'Code: use 1–16 characters A-Z, 0-9, "_" or "-".' : null,
            RowRules::required($row['Name'], 'Name'),
            RowRules::maxLength($row['Name'], 200, 'Name'),
            RowRules::required($type, 'Work center type'),
            $type !== '' && preg_match('/^[A-Z][A-Z0-9_]{1,31}$/', $type) !== 1 ? 'Work center type: use A-Z, 0-9 or "_", starting with a letter, 2–32 characters.' : null,
            $hours !== '' && (preg_match('/^\d{1,2}(\.\d{1,2})?$/', $hours) !== 1 || (float) $hours <= 0 || (float) $hours > 24)
                ? 'Available hours per shift: above 0, at most 24, with at most 2 decimals.' : null,
        ]);
        $this->checkPlant($db, $row['Plant code'], $errors, $plant);
        if ($errors === [] && $plant !== null && $db->selectOne('select 1 as ok from work_centers where plant_id = ? and code = ?', [$plant->id, $code]) !== null) {
            $errors[] = "Work center {$code} already exists in plant ".strtoupper($row['Plant code']).'.';
        }

        return $errors === [] ? RowRules::collect([RowRules::once($seen, 'wc', strtoupper($row['Plant code']).'/'.$code, $rowNo, "Work center {$code}")]) : $errors;
    }

    public function commit(EoraPostgresConnection $db, array $row): string
    {
        $plant = (string) $this->plant($db, $row['Plant code'])?->id;
        $id = (string) $db->selectOne(
            'insert into work_centers (tenant_id, plant_id, code, name, work_center_type, available_hours_per_shift)
             values ((select c.tenant from ctx_api.context() c), ?, ?, ?, ?, ?) returning id',
            [$plant, strtoupper($row['Code']), $row['Name'], strtoupper($row['Work center type']), RowRules::text($row['Available hours per shift'])],
        )->id;

        return $this->created($db, 'work_centers', 'work_center', $id, ['code' => strtoupper($row['Code'])], $plant);
    }
}
