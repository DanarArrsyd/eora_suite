<?php

declare(strict_types=1);

namespace App\Modules\Core\Import;

use App\Modules\Core\Queries\WarehousesQuery;
use App\Platform\Database\EoraPostgresConnection;
use App\Platform\Import\ImportColumn;
use App\Platform\Import\MasterTemplate;
use App\Platform\Import\RowRules;

/** Warehouses (doc 29 §1.6): new warehouses of plants in the user's `master.org.create` scope. */
final class WarehousesTemplate extends MasterTemplate
{
    public function code(): string
    {
        return 'warehouses';
    }

    public function title(): string
    {
        return 'Warehouses';
    }

    public function targetPermission(): string
    {
        return 'master.org.create';
    }

    public function columns(): array
    {
        return [
            new ImportColumn('Plant code', true, 'Code of an existing plant in your roles.'),
            new ImportColumn('Code', true, '1–20 characters A-Z, 0-9, "_" or "-"; unique in the plant.'),
            new ImportColumn('Name', true, 'At most 200 characters.'),
            new ImportColumn('Warehouse type', true, 'RAW_MATERIAL, FINISHED_GOODS or GENERAL.'),
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
            RowRules::required($row['Warehouse type'], 'Warehouse type'),
            RowRules::oneOf($row['Warehouse type'], WarehousesQuery::TYPES, 'Warehouse type'),
        ]);
        $this->checkPlant($db, $row['Plant code'], $errors, $plant);
        if ($errors === [] && $plant !== null && $db->selectOne('select 1 as ok from warehouses where plant_id = ? and code = ?', [$plant->id, $code]) !== null) {
            $errors[] = "Warehouse {$code} already exists in plant ".strtoupper($row['Plant code']).'.';
        }

        return $errors === [] ? RowRules::collect([RowRules::once($seen, 'warehouse', strtoupper($row['Plant code']).'/'.$code, $rowNo, "Warehouse {$code}")]) : $errors;
    }

    public function commit(EoraPostgresConnection $db, array $row): string
    {
        $plant = (string) $this->plant($db, $row['Plant code'])?->id;
        $id = (string) $db->selectOne(
            'insert into warehouses (tenant_id, plant_id, code, name, warehouse_type) values ((select c.tenant from ctx_api.context() c), ?, ?, ?, ?) returning id',
            [$plant, strtoupper($row['Code']), $row['Name'], strtoupper($row['Warehouse type'])],
        )->id;

        return $this->created($db, 'warehouses', 'warehouse', $id, ['code' => strtoupper($row['Code'])], $plant);
    }
}
