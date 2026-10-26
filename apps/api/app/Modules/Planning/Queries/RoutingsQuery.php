<?php

declare(strict_types=1);

namespace App\Modules\Planning\Queries;

use App\Platform\Query\Field;
use App\Platform\Query\QueryDefinition;

/**
 * `planning.routings` — routings of the plants in the caller's `master.routing.view` scopes, with the version in effect
 * today (tenant calendar) and the open draft or submission, if any.
 */
final class RoutingsQuery extends QueryDefinition
{
    public function resource(): string
    {
        return 'planning.routings';
    }

    public function permission(): string
    {
        return 'master.routing.view';
    }

    public function from(): string
    {
        return "routings r
                 join plants p on p.tenant_id = r.tenant_id and p.id = r.plant_id
                 join items i on i.tenant_id = r.tenant_id and i.id = r.item_id
                 join tenants t on t.id = r.tenant_id
                 left join lateral (
                   select v.id, v.version_no, v.effective_from, v.standard_yield,
                          (select count(*) from routing_operations o where o.routing_version_id = v.id) as op_count
                     from routing_versions v
                    where v.routing_id = r.id and v.status in ('APPROVED', 'SUPERSEDED')
                      and v.effective_from <= (now() at time zone t.timezone)::date
                      and (v.effective_to is null or v.effective_to >= (now() at time zone t.timezone)::date)
                 ) cur on true
                 left join lateral (
                   select v.id, v.version_no, v.status from routing_versions v
                    where v.routing_id = r.id and v.status in ('DRAFT', 'SUBMITTED', 'REJECTED')
                    order by v.version_no desc limit 1
                 ) pend on true";
    }

    /** Counting and paging need neither the version in use nor the open version (shown-only laterals). */
    public function filterFrom(): string
    {
        return 'routings r
                 join plants p on p.tenant_id = r.tenant_id and p.id = r.plant_id
                 join items i on i.tenant_id = r.tenant_id and i.id = r.item_id';
    }

    public function idColumn(): string
    {
        return 'r.id';
    }

    public function plantColumn(): string
    {
        return 'r.plant_id';
    }

    public function companyColumn(): string
    {
        return 'p.company_id';
    }

    public function fields(): array
    {
        return [
            new Field('id', 'r.id', Field::UUID, filterable: true),
            new Field('code', 'r.routing_code', sortable: true, search: 'prefix'),
            new Field('routingKind', 'r.routing_kind', Field::ENUM, sortable: true, filterable: true, options: ['PRIMARY', 'ALTERNATE']),
            new Field('status', 'r.status', Field::ENUM, sortable: true, filterable: true, options: ['ACTIVE', 'INACTIVE']),
            new Field('plantId', 'r.plant_id', Field::UUID, filterable: true),
            new Field('plantCode', 'p.code', sortable: true),
            new Field('itemId', 'r.item_id', Field::UUID, filterable: true),
            new Field('itemCode', 'i.code', sortable: true, search: 'prefix', searchSql: self::itemMatch('r.item_id', 'code')),
            new Field('itemName', 'i.name', search: 'contains', searchSql: self::itemMatch('r.item_id', 'name')),
            new Field('currentVersionId', 'cur.id', Field::UUID),
            new Field('currentVersionNo', 'cur.version_no', Field::INTEGER),
            new Field('currentEffectiveFrom', 'cur.effective_from::text'),
            new Field('currentStandardYield', 'cur.standard_yield::text'),
            new Field('currentOperationCount', 'cur.op_count', Field::INTEGER),
            new Field('pendingVersionId', 'pend.id', Field::UUID),
            new Field('pendingVersionNo', 'pend.version_no', Field::INTEGER),
            new Field('pendingStatus', 'pend.status'),
            new Field('updatedAt', 'r.updated_at', Field::INSTANT),
            new Field('version', 'r.version', Field::INTEGER),
        ];
    }

    public function defaultSort(): array
    {
        return [['itemCode', false], ['code', false]];
    }
}
