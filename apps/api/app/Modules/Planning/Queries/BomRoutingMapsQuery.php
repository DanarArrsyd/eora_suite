<?php

declare(strict_types=1);

namespace App\Modules\Planning\Queries;

use App\Platform\Query\Field;
use App\Platform\Query\QueryDefinition;

/**
 * `planning.bom_routing_maps` — map revisions pairing a BOM version with a routing version of the same item, in the
 * caller's `master.routing.view` plant scopes (a map belongs to its routing's plant). `lineCount` / `bomLineCount` show
 * how complete a draft is.
 */
final class BomRoutingMapsQuery extends QueryDefinition
{
    public function resource(): string
    {
        return 'planning.bom_routing_maps';
    }

    public function permission(): string
    {
        return 'master.routing.view';
    }

    public function from(): string
    {
        return 'bom_routing_maps m
                 join items i on i.tenant_id = m.tenant_id and i.id = m.item_id
                 join plants p on p.tenant_id = m.tenant_id and p.id = m.plant_id
                 join bom_versions bv on bv.tenant_id = m.tenant_id and bv.id = m.bom_version_id
                 join routings r on r.tenant_id = m.tenant_id and r.id = m.routing_id
                 join routing_versions rv on rv.tenant_id = m.tenant_id and rv.id = m.routing_version_id
                 left join users up on up.id = m.prepared_by
                 left join users ua on ua.id = m.approved_by';
    }

    public function idColumn(): string
    {
        return 'm.id';
    }

    public function plantColumn(): string
    {
        return 'm.plant_id';
    }

    public function companyColumn(): string
    {
        return 'p.company_id';
    }

    public function fields(): array
    {
        return [
            new Field('id', 'm.id', Field::UUID, filterable: true),
            new Field('itemId', 'm.item_id', Field::UUID, filterable: true),
            new Field('itemCode', 'i.code', sortable: true, search: 'prefix'),
            new Field('plantCode', 'p.code', sortable: true),
            new Field('bomVersionId', 'm.bom_version_id', Field::UUID, filterable: true),
            new Field('bomVersionNo', 'bv.version_no', Field::INTEGER),
            new Field('bomStatus', 'bv.status'),
            new Field('routingId', 'm.routing_id', Field::UUID, filterable: true),
            new Field('routingCode', 'r.routing_code', sortable: true, search: 'prefix'),
            new Field('routingVersionId', 'm.routing_version_id', Field::UUID, filterable: true),
            new Field('routingVersionNo', 'rv.version_no', Field::INTEGER),
            new Field('routingStatus', 'rv.status'),
            new Field('mapRevision', 'm.map_revision', Field::INTEGER, sortable: true),
            new Field('status', 'm.status', Field::ENUM, sortable: true, filterable: true, options: BomVersionsQuery::STATUSES),
            new Field('changeReason', 'm.change_reason'),
            new Field('rejectReason', 'm.reject_reason'),
            new Field('preparedByName', 'up.display_name'),
            new Field('approvedByName', 'ua.display_name'),
            new Field('approvedAt', 'm.approved_at', Field::INSTANT),
            new Field('lineCount', '(select count(*) from bom_routing_map_lines ml where ml.map_id = m.id)', Field::INTEGER),
            new Field('bomLineCount', '(select count(*) from bom_lines l where l.bom_version_id = m.bom_version_id)', Field::INTEGER),
            new Field('updatedAt', 'm.updated_at', Field::INSTANT),
            new Field('version', 'm.version', Field::INTEGER),
        ];
    }

    public function defaultSort(): array
    {
        return [['itemCode', false], ['routingCode', false], ['mapRevision', true]];
    }
}
