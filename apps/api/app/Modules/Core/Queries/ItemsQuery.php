<?php

declare(strict_types=1);

namespace App\Modules\Core\Queries;

use App\Platform\Query\Field;
use App\Platform\Query\QueryDefinition;

/** `core.items` — Part Master with its base unit and material specification (tenant-wide, `master.part.view`). */
final class ItemsQuery extends QueryDefinition
{
    public const array TYPES = ['RAW', 'SEMI_FINISHED', 'FINISHED', 'SCRAP', 'CONSUMABLE'];

    public const array STATUSES = ['ACTIVE', 'INACTIVE', 'OBSOLETE'];

    public function resource(): string
    {
        return 'core.items';
    }

    public function permission(): string
    {
        return 'master.part.view';
    }

    public function from(): string
    {
        return 'items i join uoms u on u.tenant_id = i.tenant_id and u.id = i.base_uom_id
                 left join material_specs ms on ms.tenant_id = i.tenant_id and ms.item_id = i.id';
    }

    public function idColumn(): string
    {
        return 'i.id';
    }

    public function fields(): array
    {
        return [
            new Field('id', 'i.id', Field::UUID, filterable: true),
            new Field('code', 'i.code', sortable: true, filterable: true, search: 'prefix'),
            new Field('name', 'i.name', sortable: true, search: 'contains'),
            new Field('itemType', 'i.item_type', Field::ENUM, sortable: true, filterable: true, options: self::TYPES),
            new Field('baseUomId', 'i.base_uom_id', Field::UUID, filterable: true),
            new Field('baseUomCode', 'u.code', sortable: true),
            new Field('lotControlled', 'i.lot_controlled', Field::BOOLEAN, filterable: true),
            new Field('inspectionRequired', 'i.inspection_required', Field::BOOLEAN, filterable: true),
            new Field('incomingReleaseRequired', 'i.incoming_release_required', Field::BOOLEAN, filterable: true),
            new Field('drawingNo', 'i.drawing_no', search: 'contains'),
            new Field('drawingRev', 'i.drawing_rev'),
            new Field('status', 'i.status', Field::ENUM, sortable: true, filterable: true, options: self::STATUSES),
            new Field('specId', 'ms.id', Field::UUID),
            new Field('specGrade', 'ms.grade'),
            new Field('specThicknessMm', 'ms.thickness_mm::text'),
            new Field('specWidthMm', 'ms.width_mm::text'),
            new Field('specDensityGCm3', 'ms.density_g_cm3::text'),
            new Field('specVersion', 'ms.version', Field::INTEGER),
            new Field('updatedAt', 'i.updated_at', Field::INSTANT, sortable: true),
            new Field('version', 'i.version', Field::INTEGER),
        ];
    }

    public function defaultSort(): array
    {
        return [['code', false]];
    }
}
