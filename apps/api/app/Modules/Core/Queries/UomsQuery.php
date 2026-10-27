<?php

declare(strict_types=1);

namespace App\Modules\Core\Queries;

use App\Platform\Export\ExportColumn;
use App\Platform\Export\Labels;
use App\Platform\Query\Field;
use App\Platform\Query\QueryDefinition;

/** `core.uoms` — units of the tenant (tenant-wide master, `master.part.view`). */
final class UomsQuery extends QueryDefinition
{
    public const array CLASSES = ['MASS', 'COUNT', 'LENGTH', 'AREA', 'VOLUME'];

    public function resource(): string
    {
        return 'core.uoms';
    }

    public function permission(): string
    {
        return 'master.part.view';
    }

    public function from(): string
    {
        return 'uoms u';
    }

    public function idColumn(): string
    {
        return 'u.id';
    }

    public function fields(): array
    {
        return [
            new Field('id', 'u.id', Field::UUID, filterable: true),
            new Field('code', 'u.code', sortable: true, filterable: true, search: 'prefix'),
            new Field('name', 'u.name', sortable: true, search: 'contains'),
            new Field('uomClass', 'u.uom_class', Field::ENUM, sortable: true, filterable: true, options: self::CLASSES),
            new Field('decimals', 'u.decimals', Field::INTEGER, sortable: true),
            new Field('status', 'u.status', Field::ENUM, sortable: true, filterable: true, options: ['ACTIVE', 'INACTIVE']),
            new Field('updatedAt', 'u.updated_at', Field::INSTANT, sortable: true),
            new Field('version', 'u.version', Field::INTEGER),
        ];
    }

    public function title(): string
    {
        return 'Units';
    }

    /** D-092: every column of the list, with the screen's headings; compound cells split into typed columns. */
    public function exportColumns(): array
    {
        return [
            new ExportColumn('Code', 'code'),
            new ExportColumn('Name', 'name'),
            new ExportColumn('Kind', 'uomClass', labels: Labels::UOM_CLASSES),
            new ExportColumn('Decimals', 'decimals', ExportColumn::INTEGER),
        ];
    }

    public function defaultSort(): array
    {
        return [['code', false]];
    }
}
