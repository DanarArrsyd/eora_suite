<?php

declare(strict_types=1);

namespace App\Modules\Core\Queries;

use App\Platform\Query\Field;
use App\Platform\Query\QueryDefinition;

/** `core.dies` — dies, jigs and fixtures of the plants inside the caller's `master.org.view` scopes. */
final class DiesQuery extends QueryDefinition
{
    public const array TYPES = ['DIE', 'JIG', 'FIXTURE'];

    public const array STATUSES = ['ACTIVE', 'INACTIVE', 'MAINTENANCE'];

    public function resource(): string
    {
        return 'core.dies';
    }

    public function permission(): string
    {
        return 'master.org.view';
    }

    public function from(): string
    {
        return 'dies d join plants p on p.tenant_id = d.tenant_id and p.id = d.plant_id';
    }

    public function idColumn(): string
    {
        return 'd.id';
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
            new Field('id', 'd.id', Field::UUID, filterable: true),
            new Field('code', 'd.code', sortable: true, filterable: true, search: 'prefix'),
            new Field('name', 'd.name', sortable: true, search: 'contains'),
            new Field('dieType', 'd.die_type', Field::ENUM, sortable: true, filterable: true, options: self::TYPES),
            new Field('cavities', 'd.cavities', Field::INTEGER, sortable: true),
            new Field('strokeLife', 'd.stroke_life::text'),
            new Field('currentStrokes', 'd.current_strokes::text'),
            new Field('status', 'd.status', Field::ENUM, sortable: true, filterable: true, options: self::STATUSES),
            new Field('plantId', 'd.plant_id', Field::UUID, filterable: true),
            new Field('plantCode', 'p.code', sortable: true),
            new Field('updatedAt', 'd.updated_at', Field::INSTANT, sortable: true),
            new Field('version', 'd.version', Field::INTEGER),
        ];
    }

    public function defaultSort(): array
    {
        return [['code', false]];
    }
}
