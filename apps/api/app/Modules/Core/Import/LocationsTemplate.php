<?php

declare(strict_types=1);

namespace App\Modules\Core\Import;

use App\Modules\Core\Queries\LocationsQuery;
use App\Platform\Database\EoraPostgresConnection;
use App\Platform\Import\ImportColumn;
use App\Platform\Import\MasterTemplate;
use App\Platform\Import\RowRules;

/** Physical locations in active warehouses (doc 29 §1.6); V_ and MAP- codes stay reserved for the system's own. */
final class LocationsTemplate extends MasterTemplate
{
    public function code(): string
    {
        return 'locations';
    }

    public function title(): string
    {
        return 'Locations';
    }

    public function targetPermission(): string
    {
        return 'master.org.create';
    }

    public function columns(): array
    {
        return [
            new ImportColumn('Plant code', true, 'Code of an existing plant in your roles.'),
            new ImportColumn('Warehouse code', true, 'Code of an active warehouse of that plant.'),
            new ImportColumn('Code', true, '1–20 characters A-Z, 0-9, "_" or "-"; unique in the plant; not starting with V_ or MAP-.'),
            new ImportColumn('Name', true, 'At most 200 characters.'),
            new ImportColumn('Location type', true, implode(', ', LocationsQuery::WAREHOUSE_TYPES).'.'),
        ];
    }

    public function validate(EoraPostgresConnection $db, array $row, int $rowNo, array &$seen): array
    {
        $code = strtoupper($row['Code']);
        $errors = RowRules::collect([
            RowRules::required($code, 'Code'),
            $code !== '' && preg_match('/^[A-Z0-9][A-Z0-9_-]{0,19}$/', $code) !== 1 ? 'Code: use 1–20 characters A-Z, 0-9, "_" or "-".' : null,
            str_starts_with($code, 'V_') || str_starts_with($code, 'MAP-') ? 'Codes starting with V_ or MAP- are reserved for virtual and Material at Production locations.' : null,
            RowRules::required($row['Name'], 'Name'),
            RowRules::maxLength($row['Name'], 200, 'Name'),
            RowRules::required($row['Location type'], 'Location type'),
            RowRules::oneOf($row['Location type'], LocationsQuery::WAREHOUSE_TYPES, 'Location type'),
            RowRules::required($row['Warehouse code'], 'Warehouse code'),
        ]);
        $this->checkPlant($db, $row['Plant code'], $errors, $plant);
        if ($plant !== null && $row['Warehouse code'] !== '') {
            $warehouse = $db->selectOne('select status from warehouses where plant_id = ? and code = ?', [$plant->id, strtoupper($row['Warehouse code'])]);
            if ($warehouse === null) {
                $errors[] = 'Warehouse '.strtoupper($row['Warehouse code']).' does not exist in this plant.';
            } elseif ($warehouse->status !== 'ACTIVE') {
                $errors[] = 'Warehouse '.strtoupper($row['Warehouse code']).' is inactive.';
            }
        }
        if ($errors === [] && $plant !== null && $db->selectOne('select 1 as ok from locations where plant_id = ? and code = ?', [$plant->id, $code]) !== null) {
            $errors[] = "Location {$code} already exists in plant ".strtoupper($row['Plant code']).'.';
        }

        return $errors === [] ? RowRules::collect([RowRules::once($seen, 'location', strtoupper($row['Plant code']).'/'.$code, $rowNo, "Location {$code}")]) : $errors;
    }

    public function commit(EoraPostgresConnection $db, array $row): string
    {
        $warehouse = $db->selectOne(
            'select w.id, w.plant_id from warehouses w join plants p on p.id = w.plant_id where p.code = ? and w.code = ?',
            [strtoupper($row['Plant code']), strtoupper($row['Warehouse code'])],
        );
        $id = (string) $db->selectOne(
            'insert into locations (tenant_id, plant_id, warehouse_id, code, name, location_type) values ((select c.tenant from ctx_api.context() c), ?, ?, ?, ?, ?) returning id',
            [$warehouse->plant_id, $warehouse->id, strtoupper($row['Code']), $row['Name'], strtoupper($row['Location type'])],
        )->id;

        return $this->created($db, 'locations', 'location', $id, ['code' => strtoupper($row['Code'])], (string) $warehouse->plant_id);
    }
}
