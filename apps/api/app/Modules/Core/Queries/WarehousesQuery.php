<?php

declare(strict_types=1);

namespace App\Modules\Core\Queries;

use App\Platform\Query\Field;
use App\Platform\Query\QueryDefinition;

/** `core.warehouses` — warehouses of the plants inside the caller's `master.org.view` scopes. */
final class WarehousesQuery extends QueryDefinition
{
    public const array TYPES = ['RAW_MATERIAL', 'FINISHED_GOODS', 'GENERAL'];

    public function resource(): string
    {
        return 'core.warehouses';
    }

    public function permission(): string
    {
        return 'master.org.view';
    }

    public function from(): string
    {
        return 'warehouses w join plants p on p.tenant_id = w.tenant_id and p.id = w.plant_id';
    }

    public function idColumn(): string
    {
        return 'w.id';
    }

    public function plantColumn(): string
    {
        return 'w.plant_id';
    }

    public function companyColumn(): string
    {
        return 'p.company_id';
    }

    public function fields(): array
    {
        return [
            new Field('id', 'w.id', Field::UUID, filterable: true),
            new Field('code', 'w.code', sortable: true, filterable: true, search: 'prefix'),
            new Field('name', 'w.name', sortable: true, search: 'contains'),
            new Field('warehouseType', 'w.warehouse_type', Field::ENUM, sortable: true, filterable: true, options: self::TYPES),
            new Field('status', 'w.status', Field::ENUM, sortable: true, filterable: true, options: ['ACTIVE', 'INACTIVE']),
            new Field('plantId', 'w.plant_id', Field::UUID, filterable: true),
            new Field('plantCode', 'p.code', sortable: true),
            new Field('locationCount', '(select count(*) from locations l where l.warehouse_id = w.id)', Field::INTEGER),
            new Field('updatedAt', 'w.updated_at', Field::INSTANT, sortable: true),
            new Field('version', 'w.version', Field::INTEGER),
        ];
    }

    public function defaultSort(): array
    {
        return [['plantCode', false], ['code', false]];
    }
}
