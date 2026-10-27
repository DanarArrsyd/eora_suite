<?php

declare(strict_types=1);

namespace App\Modules\Planning\Queries;

use App\Platform\Export\ExportColumn;
use App\Platform\Export\Labels;
use App\Platform\Query\Field;
use App\Platform\Query\QueryDefinition;

/** `planning.process_types` — the tenant's process catalogue (`master.routing.view`). */
final class ProcessTypesQuery extends QueryDefinition
{
    public const array CATEGORIES = ['TRANSFORMATION', 'INSPECTION', 'HANDLING'];

    public function resource(): string
    {
        return 'planning.process_types';
    }

    public function permission(): string
    {
        return 'master.routing.view';
    }

    public function from(): string
    {
        return 'process_types pt';
    }

    public function idColumn(): string
    {
        return 'pt.id';
    }

    public function fields(): array
    {
        return [
            new Field('id', 'pt.id', Field::UUID, filterable: true),
            new Field('code', 'pt.code', sortable: true, filterable: true, search: 'prefix'),
            new Field('name', 'pt.name', sortable: true, search: 'contains'),
            new Field('category', 'pt.category', Field::ENUM, sortable: true, filterable: true, options: self::CATEGORIES),
            new Field('defaultWorkCenterType', 'pt.default_work_center_type'),
            new Field('status', 'pt.status', Field::ENUM, sortable: true, filterable: true, options: ['ACTIVE', 'INACTIVE']),
            new Field('updatedAt', 'pt.updated_at', Field::INSTANT),
            new Field('version', 'pt.version', Field::INTEGER),
        ];
    }

    public function title(): string
    {
        return 'Process Types';
    }

    /** D-092: every column of the list, with the screen's headings; compound cells split into typed columns. */
    public function exportColumns(): array
    {
        return [
            new ExportColumn('Code', 'code'),
            new ExportColumn('Name', 'name'),
            new ExportColumn('Category', 'category', labels: Labels::PROCESS_CATEGORIES),
            new ExportColumn('Usual work center type', 'defaultWorkCenterType'),
            new ExportColumn('Status', 'status', labels: Labels::MASTER_STATUS),
        ];
    }

    public function defaultSort(): array
    {
        return [['code', false]];
    }
}
