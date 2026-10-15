<?php

declare(strict_types=1);

namespace App\Modules\Core\Queries;

use App\Platform\Query\Field;
use App\Platform\Query\QueryDefinition;

/** `core.machines` — machines of the plants inside the caller's `master.org.view` scopes. */
final class MachinesQuery extends QueryDefinition
{
    public function resource(): string
    {
        return 'core.machines';
    }

    public function permission(): string
    {
        return 'master.org.view';
    }

    public function from(): string
    {
        return 'machines m join work_centers wc on wc.tenant_id = m.tenant_id and wc.id = m.work_center_id
                 join plants p on p.tenant_id = m.tenant_id and p.id = m.plant_id';
    }

    public function idColumn(): string
    {
        return 'm.id';
    }

    public function plantColumn(): string
    {
        return 'm.plant_id';
    }

    public function companyColumn(): string
    {
        return 'p.company_id';
    }

    public function fields(): array
    {
        return [
            new Field('id', 'm.id', Field::UUID, filterable: true),
            new Field('code', 'm.code', sortable: true, filterable: true, search: 'prefix'),
            new Field('name', 'm.name', sortable: true, search: 'contains'),
            new Field('strokeCounter', 'm.stroke_counter', Field::BOOLEAN, filterable: true),
            new Field('status', 'm.status', Field::ENUM, sortable: true, filterable: true, options: ['ACTIVE', 'INACTIVE']),
            new Field('workCenterId', 'm.work_center_id', Field::UUID, filterable: true),
            new Field('workCenterCode', 'wc.code', sortable: true),
            new Field('plantId', 'm.plant_id', Field::UUID, filterable: true),
            new Field('plantCode', 'p.code', sortable: true),
            new Field('updatedAt', 'm.updated_at', Field::INSTANT, sortable: true),
            new Field('version', 'm.version', Field::INTEGER),
        ];
    }

    public function defaultSort(): array
    {
        return [['plantCode', false], ['code', false]];
    }
}
