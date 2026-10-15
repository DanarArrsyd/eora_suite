<?php

declare(strict_types=1);

namespace App\Modules\Core\Queries;

use App\Platform\Query\Field;
use App\Platform\Query\QueryDefinition;

/** `core.customers` — customers of the tenant (tenant-wide partner master, `master.part.view`). */
final class CustomersQuery extends QueryDefinition
{
    public function resource(): string
    {
        return 'core.customers';
    }

    public function permission(): string
    {
        return 'master.part.view';
    }

    public function from(): string
    {
        return 'customers c';
    }

    public function idColumn(): string
    {
        return 'c.id';
    }

    public function fields(): array
    {
        return [
            new Field('id', 'c.id', Field::UUID, filterable: true),
            new Field('code', 'c.code', sortable: true, filterable: true, search: 'prefix'),
            new Field('name', 'c.name', sortable: true, search: 'contains'),
            new Field('status', 'c.status', Field::ENUM, sortable: true, filterable: true, options: ['ACTIVE', 'INACTIVE']),
            new Field('updatedAt', 'c.updated_at', Field::INSTANT, sortable: true),
            new Field('version', 'c.version', Field::INTEGER),
        ];
    }

    public function defaultSort(): array
    {
        return [['code', false]];
    }
}
