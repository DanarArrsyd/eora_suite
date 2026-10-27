<?php

declare(strict_types=1);

namespace App\Modules\Core\Queries;

use App\Platform\Export\ExportColumn;
use App\Platform\Query\Field;
use App\Platform\Query\QueryDefinition;

/** `core.work_centers` — work centers of the plants inside the caller's `master.org.view` scopes. */
final class WorkCentersQuery extends QueryDefinition
{
    public function resource(): string
    {
        return 'core.work_centers';
    }

    public function permission(): string
    {
        return 'master.org.view';
    }

    public function from(): string
    {
        return "work_centers wc join plants p on p.tenant_id = wc.tenant_id and p.id = wc.plant_id
                 left join locations l on l.tenant_id = wc.tenant_id and l.work_center_id = wc.id and l.location_type = 'MAT_AT_PROD'";
    }

    public function idColumn(): string
    {
        return 'wc.id';
    }

    public function plantColumn(): string
    {
        return 'wc.plant_id';
    }

    public function companyColumn(): string
    {
        return 'p.company_id';
    }

    public function fields(): array
    {
        return [
            new Field('id', 'wc.id', Field::UUID, filterable: true),
            new Field('code', 'wc.code', sortable: true, filterable: true, search: 'prefix'),
            new Field('name', 'wc.name', sortable: true, search: 'contains'),
            new Field('workCenterType', 'wc.work_center_type', sortable: true, filterable: true),
            new Field('availableHoursPerShift', 'wc.available_hours_per_shift::text'),
            new Field('status', 'wc.status', Field::ENUM, sortable: true, filterable: true, options: ['ACTIVE', 'INACTIVE']),
            new Field('plantId', 'wc.plant_id', Field::UUID, filterable: true),
            new Field('plantCode', 'p.code', sortable: true),
            new Field('matAtProdLocationId', 'l.id', Field::UUID),
            new Field('matAtProdLocationCode', 'l.code'),
            new Field('machineCount', '(select count(*) from machines m where m.work_center_id = wc.id)', Field::INTEGER),
            new Field('updatedAt', 'wc.updated_at', Field::INSTANT, sortable: true),
            new Field('version', 'wc.version', Field::INTEGER),
        ];
    }

    public function title(): string
    {
        return 'Work Centers';
    }

    /** D-092: every column of the list, with the screen's headings; compound cells split into typed columns. */
    public function exportColumns(): array
    {
        return [
            new ExportColumn('Plant', 'plantCode'),
            new ExportColumn('Code', 'code'),
            new ExportColumn('Name', 'name'),
            new ExportColumn('Type', 'workCenterType'),
            new ExportColumn('Hours per shift', 'availableHoursPerShift', ExportColumn::DECIMAL),
            new ExportColumn('Machines', 'machineCount', ExportColumn::INTEGER),
            new ExportColumn('Material at Production', 'matAtProdLocationCode'),
        ];
    }

    public function defaultSort(): array
    {
        return [['plantCode', false], ['code', false]];
    }
}
