<?php

declare(strict_types=1);

namespace App\Modules\Planning\Queries;

use App\Platform\Query\Field;
use App\Platform\Query\QueryDefinition;

/**
 * `planning.bom_versions` — every BOM version of the tenant's items (`master.routing.view`; BOMs are per item and
 * tenant-wide, D-078). `inUse` marks the approved version in effect today in the tenant's time zone.
 */
final class BomVersionsQuery extends QueryDefinition
{
    public const array STATUSES = ['DRAFT', 'SUBMITTED', 'REJECTED', 'APPROVED', 'SUPERSEDED'];

    public function resource(): string
    {
        return 'planning.bom_versions';
    }

    public function permission(): string
    {
        return 'master.routing.view';
    }

    public function from(): string
    {
        return 'bom_versions v
                 join items i on i.tenant_id = v.tenant_id and i.id = v.item_id
                 join tenants t on t.id = v.tenant_id
                 left join users up on up.id = v.prepared_by
                 left join users ua on ua.id = v.approved_by';
    }

    public function idColumn(): string
    {
        return 'v.id';
    }

    public function fields(): array
    {
        $today = '(now() at time zone t.timezone)::date';

        return [
            new Field('id', 'v.id', Field::UUID, filterable: true),
            new Field('itemId', 'v.item_id', Field::UUID, filterable: true),
            new Field('itemCode', 'i.code', sortable: true, search: 'prefix', searchSql: self::itemMatch('v.item_id', 'code')),
            new Field('itemName', 'i.name', search: 'contains', searchSql: self::itemMatch('v.item_id', 'name')),
            new Field('itemType', 'i.item_type', Field::ENUM, filterable: true, options: ['RAW', 'SEMI_FINISHED', 'FINISHED', 'SCRAP', 'CONSUMABLE']),
            new Field('versionNo', 'v.version_no', Field::INTEGER, sortable: true),
            new Field('status', 'v.status', Field::ENUM, sortable: true, filterable: true, options: self::STATUSES),
            new Field('effectiveFrom', 'v.effective_from::text', sortable: true),
            new Field('effectiveTo', 'v.effective_to::text'),
            new Field('inUse', "(v.status in ('APPROVED', 'SUPERSEDED') and v.effective_from <= {$today} and (v.effective_to is null or v.effective_to >= {$today}))", Field::BOOLEAN),
            new Field('changeReason', 'v.change_reason'),
            new Field('rejectReason', 'v.reject_reason'),
            new Field('preparedByName', 'up.display_name'),
            new Field('approvedByName', 'ua.display_name'),
            new Field('approvedAt', 'v.approved_at', Field::INSTANT),
            new Field('lineCount', '(select count(*) from bom_lines l where l.bom_version_id = v.id)', Field::INTEGER),
            new Field('updatedAt', 'v.updated_at', Field::INSTANT),
            new Field('version', 'v.version', Field::INTEGER),
        ];
    }

    public function defaultSort(): array
    {
        return [['itemCode', false], ['versionNo', true]];
    }
}
