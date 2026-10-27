<?php

declare(strict_types=1);

namespace App\Modules\Core\Queries;

use App\Platform\Export\ExportColumn;
use App\Platform\Export\Labels;
use App\Platform\Query\Field;
use App\Platform\Query\QueryDefinition;

/** `core.locations` — physical and virtual locations of the plants inside the caller's `master.org.view` scopes. */
final class LocationsQuery extends QueryDefinition
{
    /** Physical types a user creates inside a warehouse (ADR-0002 §3; MAT_AT_PROD comes with its work center). */
    public const array WAREHOUSE_TYPES = ['RM_WAREHOUSE', 'CUSTODY_TRANSIT', 'QC_AREA', 'NG_AREA', 'SCRAP_YARD', 'FG_WAREHOUSE', 'STAGING'];

    public const array VIRTUAL_TYPES = ['V_SUPPLIER', 'V_CUSTOMER', 'V_CONSUMED', 'V_PRODUCED', 'V_ADJUSTMENT', 'V_OPENING'];

    public function resource(): string
    {
        return 'core.locations';
    }

    public function permission(): string
    {
        return 'master.org.view';
    }

    public function from(): string
    {
        return 'locations l join plants p on p.tenant_id = l.tenant_id and p.id = l.plant_id
                 left join warehouses w on w.tenant_id = l.tenant_id and w.id = l.warehouse_id
                 left join work_centers wc on wc.tenant_id = l.tenant_id and wc.id = l.work_center_id';
    }

    public function idColumn(): string
    {
        return 'l.id';
    }

    public function plantColumn(): string
    {
        return 'l.plant_id';
    }

    public function companyColumn(): string
    {
        return 'p.company_id';
    }

    public function fields(): array
    {
        return [
            new Field('id', 'l.id', Field::UUID, filterable: true),
            new Field('code', 'l.code', sortable: true, filterable: true, search: 'prefix'),
            new Field('name', 'l.name', sortable: true, search: 'contains'),
            new Field('locationType', 'l.location_type', Field::ENUM, sortable: true, filterable: true,
                options: [...self::WAREHOUSE_TYPES, 'MAT_AT_PROD', ...self::VIRTUAL_TYPES]),
            new Field('virtual', "(l.location_type like 'V\\_%')", Field::BOOLEAN, filterable: true),
            new Field('status', 'l.status', Field::ENUM, sortable: true, filterable: true, options: ['ACTIVE', 'INACTIVE']),
            new Field('plantId', 'l.plant_id', Field::UUID, filterable: true),
            new Field('plantCode', 'p.code', sortable: true),
            new Field('warehouseId', 'l.warehouse_id', Field::UUID, filterable: true),
            new Field('warehouseCode', 'w.code', sortable: true),
            new Field('workCenterId', 'l.work_center_id', Field::UUID, filterable: true),
            new Field('workCenterCode', 'wc.code', sortable: true),
            new Field('updatedAt', 'l.updated_at', Field::INSTANT, sortable: true),
            new Field('version', 'l.version', Field::INTEGER),
        ];
    }

    public function title(): string
    {
        return 'Locations';
    }

    /** D-092: every column of the list, with the screen's headings; compound cells split into typed columns. */
    public function exportColumns(): array
    {
        return [
            new ExportColumn('Plant', 'plantCode'),
            new ExportColumn('Code', 'code'),
            new ExportColumn('Name', 'name'),
            new ExportColumn('Type', 'locationType', labels: Labels::LOCATION_TYPES),
            new ExportColumn('Belongs to', static fn (array $r) => $r['warehouseCode'] !== null ? "Warehouse {$r['warehouseCode']}" : ($r['workCenterCode'] !== null ? "Work center {$r['workCenterCode']}" : 'Plant (virtual)')),
        ];
    }

    public function defaultSort(): array
    {
        return [['plantCode', false], ['code', false]];
    }
}
