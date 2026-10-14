<?php

declare(strict_types=1);

namespace App\Modules\Core\Queries;

use App\Platform\Query\Field;
use App\Platform\Query\QueryDefinition;

/** `core.companies` — companies inside the caller's `master.org.view` scopes (a plant scope sees its plant's company). */
final class CompaniesQuery extends QueryDefinition
{
    public function resource(): string
    {
        return 'core.companies';
    }

    public function permission(): string
    {
        return 'master.org.view';
    }

    public function from(): string
    {
        return 'companies c';
    }

    public function idColumn(): string
    {
        return 'c.id';
    }

    public function companyColumn(): string
    {
        return 'c.id';
    }

    public function fields(): array
    {
        return [
            new Field('id', 'c.id', Field::UUID),
            new Field('code', 'c.code', sortable: true, filterable: true, search: 'prefix'),
            new Field('legalName', 'c.legal_name', sortable: true, search: 'contains'),
            new Field('updatedAt', 'c.updated_at', Field::INSTANT, sortable: true),
            new Field('version', 'c.version', Field::INTEGER),
        ];
    }

    public function defaultSort(): array
    {
        return [['code', false]];
    }
}
