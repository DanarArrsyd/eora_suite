<?php

declare(strict_types=1);

namespace App\Modules\Core\Queries;

use App\Platform\Export\ExportColumn;
use App\Platform\Query\Field;
use App\Platform\Query\QueryDefinition;

/** `core.plants` — plants inside the caller's `master.org.view` scopes. */
final class PlantsQuery extends QueryDefinition
{
    public function resource(): string
    {
        return 'core.plants';
    }

    public function permission(): string
    {
        return 'master.org.view';
    }

    public function from(): string
    {
        return 'plants p join companies c on c.tenant_id = p.tenant_id and c.id = p.company_id';
    }

    public function idColumn(): string
    {
        return 'p.id';
    }

    public function plantColumn(): string
    {
        return 'p.id';
    }

    public function companyColumn(): string
    {
        return 'p.company_id';
    }

    public function fields(): array
    {
        return [
            new Field('id', 'p.id', Field::UUID, filterable: true),
            new Field('code', 'p.code', sortable: true, filterable: true, search: 'prefix'),
            new Field('name', 'p.name', sortable: true, search: 'contains'),
            new Field('timezone', 'p.timezone'),
            new Field('companyId', 'p.company_id', Field::UUID, filterable: true),
            new Field('companyCode', 'c.code', sortable: true),
            new Field('companyName', 'c.legal_name'),
            new Field('updatedAt', 'p.updated_at', Field::INSTANT, sortable: true),
            new Field('version', 'p.version', Field::INTEGER),
        ];
    }

    public function title(): string
    {
        return 'Plants';
    }

    /** D-092: every column of the list, with the screen's headings; compound cells split into typed columns. */
    public function exportColumns(): array
    {
        return [
            new ExportColumn('Code', 'code'),
            new ExportColumn('Name', 'name'),
            new ExportColumn('Company', 'companyCode'),
            new ExportColumn('Company name', 'companyName'),
            new ExportColumn('Time zone', 'timezone'),
        ];
    }

    public function defaultSort(): array
    {
        return [['code', false]];
    }
}
