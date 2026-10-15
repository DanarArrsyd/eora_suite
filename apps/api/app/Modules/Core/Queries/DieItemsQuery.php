<?php

declare(strict_types=1);

namespace App\Modules\Core\Queries;

use App\Platform\Query\Field;
use App\Platform\Query\QueryDefinition;

/** `core.die_items` — which parts each die makes, scoped by the die's plant (`master.org.view`). */
final class DieItemsQuery extends QueryDefinition
{
    public function resource(): string
    {
        return 'core.die_items';
    }

    public function permission(): string
    {
        return 'master.org.view';
    }

    public function from(): string
    {
        return 'die_items di
                 join dies d on d.tenant_id = di.tenant_id and d.id = di.die_id
                 join plants p on p.tenant_id = d.tenant_id and p.id = d.plant_id
                 join items i on i.tenant_id = di.tenant_id and i.id = di.item_id';
    }

    public function idColumn(): string
    {
        return 'di.id';
    }

    public function plantColumn(): string
    {
        return 'd.plant_id';
    }

    public function companyColumn(): string
    {
        return 'p.company_id';
    }

    public function fields(): array
    {
        return [
            new Field('id', 'di.id', Field::UUID, filterable: true),
            new Field('dieId', 'di.die_id', Field::UUID, filterable: true),
            new Field('code', 'd.code', sortable: true, search: 'prefix'),
            new Field('dieName', 'd.name'),
            new Field('plantCode', 'p.code', sortable: true),
            new Field('itemId', 'di.item_id', Field::UUID, filterable: true),
            new Field('itemCode', 'i.code', sortable: true, search: 'prefix'),
            new Field('itemName', 'i.name', search: 'contains'),
            new Field('partsPerStroke', 'di.parts_per_stroke', Field::INTEGER, sortable: true),
            new Field('status', 'di.status', Field::ENUM, sortable: true, filterable: true, options: ['ACTIVE', 'INACTIVE']),
            new Field('updatedAt', 'di.updated_at', Field::INSTANT),
            new Field('version', 'di.version', Field::INTEGER),
        ];
    }

    public function defaultSort(): array
    {
        return [['code', false], ['itemCode', false]];
    }
}
