<?php

declare(strict_types=1);

namespace App\Modules\Planning\Queries;

use App\Platform\Query\Field;
use App\Platform\Query\QueryDefinition;

/**
 * `planning.item_weights` — net weight (NW, kg per piece) versions of the tenant's items (`master.conversion.view`;
 * tenant-wide, D-080). `inUse` marks the validated version in force today in the tenant's time zone.
 */
final class ItemWeightsQuery extends QueryDefinition
{
    public const array STATUSES = ['DRAFT', 'SUBMITTED', 'REJECTED', 'VALIDATED', 'RETIRED'];

    public function resource(): string
    {
        return 'planning.item_weights';
    }

    public function permission(): string
    {
        return 'master.conversion.view';
    }

    public function from(): string
    {
        return 'item_weights w
                 join items i on i.tenant_id = w.tenant_id and i.id = w.item_id
                 join tenants t on t.id = w.tenant_id
                 left join users up on up.id = w.prepared_by
                 left join users uv on uv.id = w.validated_by';
    }

    public function idColumn(): string
    {
        return 'w.id';
    }

    public function fields(): array
    {
        $today = '(now() at time zone t.timezone)::date';

        return [
            new Field('id', 'w.id', Field::UUID, filterable: true),
            new Field('itemId', 'w.item_id', Field::UUID, filterable: true),
            new Field('itemCode', 'i.code', sortable: true, search: 'prefix'),
            new Field('itemName', 'i.name', search: 'contains'),
            new Field('itemType', 'i.item_type', Field::ENUM, filterable: true, options: ['RAW', 'SEMI_FINISHED', 'FINISHED', 'SCRAP', 'CONSUMABLE']),
            new Field('versionNo', 'w.version_no', Field::INTEGER, sortable: true),
            new Field('status', 'w.status', Field::ENUM, sortable: true, filterable: true, options: self::STATUSES),
            new Field('netWeightKg', 'w.net_weight_kg::text'),
            new Field('effectiveFrom', 'w.effective_from::text', sortable: true),
            new Field('effectiveTo', 'w.effective_to::text'),
            new Field('inUse', "(w.status in ('VALIDATED', 'RETIRED') and w.effective_from <= {$today} and (w.effective_to is null or w.effective_to >= {$today}))", Field::BOOLEAN),
            new Field('changeReason', 'w.change_reason'),
            new Field('rejectReason', 'w.reject_reason'),
            new Field('preparedByName', 'up.display_name'),
            new Field('validatedByName', 'uv.display_name'),
            new Field('validatedAt', 'w.validated_at', Field::INSTANT),
            new Field('retiredAt', 'w.retired_at', Field::INSTANT),
            new Field('updatedAt', 'w.updated_at', Field::INSTANT),
            new Field('version', 'w.version', Field::INTEGER),
        ];
    }

    public function defaultSort(): array
    {
        return [['itemCode', false], ['versionNo', true]];
    }
}
