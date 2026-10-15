<?php

declare(strict_types=1);

namespace App\Modules\Planning\Queries;

use App\Platform\Query\Field;
use App\Platform\Query\QueryDefinition;

/** `planning.routing_versions` — every version of the routings in the caller's `master.routing.view` scopes. */
final class RoutingVersionsQuery extends QueryDefinition
{
    public const array STATUSES = ['DRAFT', 'SUBMITTED', 'REJECTED', 'APPROVED', 'SUPERSEDED'];

    public function resource(): string
    {
        return 'planning.routing_versions';
    }

    public function permission(): string
    {
        return 'master.routing.view';
    }

    public function from(): string
    {
        return 'routing_versions v
                 join routings r on r.tenant_id = v.tenant_id and r.id = v.routing_id
                 join plants p on p.tenant_id = v.tenant_id and p.id = v.plant_id
                 join items i on i.tenant_id = r.tenant_id and i.id = r.item_id
                 left join users up on up.id = v.prepared_by
                 left join users ua on ua.id = v.approved_by';
    }

    public function idColumn(): string
    {
        return 'v.id';
    }

    public function plantColumn(): string
    {
        return 'v.plant_id';
    }

    public function companyColumn(): string
    {
        return 'p.company_id';
    }

    public function fields(): array
    {
        return [
            new Field('id', 'v.id', Field::UUID, filterable: true),
            new Field('routingId', 'v.routing_id', Field::UUID, filterable: true),
            new Field('code', 'r.routing_code', sortable: true, search: 'prefix'),
            new Field('itemCode', 'i.code', sortable: true, search: 'prefix'),
            new Field('itemName', 'i.name'),
            new Field('plantCode', 'p.code'),
            new Field('versionNo', 'v.version_no', Field::INTEGER, sortable: true),
            new Field('status', 'v.status', Field::ENUM, sortable: true, filterable: true, options: self::STATUSES),
            new Field('effectiveFrom', 'v.effective_from::text', sortable: true),
            new Field('effectiveTo', 'v.effective_to::text'),
            new Field('changeReason', 'v.change_reason'),
            new Field('standardYield', 'v.standard_yield::text'),
            new Field('rejectReason', 'v.reject_reason'),
            new Field('preparedBy', 'v.prepared_by', Field::UUID),
            new Field('preparedByName', 'up.display_name'),
            new Field('approvedByName', 'ua.display_name'),
            new Field('approvedAt', 'v.approved_at', Field::INSTANT),
            new Field('operationCount', '(select count(*) from routing_operations o where o.routing_version_id = v.id)', Field::INTEGER),
            new Field('updatedAt', 'v.updated_at', Field::INSTANT),
            new Field('version', 'v.version', Field::INTEGER),
        ];
    }

    public function defaultSort(): array
    {
        return [['itemCode', false], ['code', false], ['versionNo', true]];
    }
}
