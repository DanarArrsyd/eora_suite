<?php

declare(strict_types=1);

namespace App\Modules\Core\Queries;

use App\Platform\Export\ExportColumn;
use App\Platform\Export\Labels;
use App\Platform\Query\Field;
use App\Platform\Query\QueryDefinition;

/** `core.suppliers` — suppliers of the tenant (tenant-wide partner master, `master.part.view`). */
final class SuppliersQuery extends QueryDefinition
{
    public function resource(): string
    {
        return 'core.suppliers';
    }

    public function permission(): string
    {
        return 'master.part.view';
    }

    public function from(): string
    {
        return 'suppliers s';
    }

    public function idColumn(): string
    {
        return 's.id';
    }

    public function fields(): array
    {
        return [
            new Field('id', 's.id', Field::UUID, filterable: true),
            new Field('code', 's.code', sortable: true, filterable: true, search: 'prefix'),
            new Field('name', 's.name', sortable: true, search: 'contains'),
            new Field('status', 's.status', Field::ENUM, sortable: true, filterable: true, options: ['ACTIVE', 'INACTIVE']),
            new Field('updatedAt', 's.updated_at', Field::INSTANT, sortable: true),
            new Field('version', 's.version', Field::INTEGER),
        ];
    }

    public function title(): string
    {
        return 'Suppliers';
    }

    /** D-092: every column of the list, with the screen's headings; compound cells split into typed columns. */
    public function exportColumns(): array
    {
        return [
            new ExportColumn('Code', 'code'),
            new ExportColumn('Name', 'name'),
            new ExportColumn('Status', 'status', labels: Labels::MASTER_STATUS),
        ];
    }

    public function defaultSort(): array
    {
        return [['code', false]];
    }
}
